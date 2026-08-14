<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hitl\HitlAllowlist;
use HaoCode\Services\Hitl\HitlReviewer;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

trait AgentLoopRunInternalConcern
{    /**
     * Original run body, preserved verbatim behind the tracer wrapper in
     * {@see run()} so span lifecycle stays isolated from the agent logic.
     */
    private function runInternal(
        string|array|null $userInput,
        ?callable $onTextDelta,
        ?callable $onToolStart,
        ?callable $onToolComplete,
        ?callable $onTurnStart,
        ?callable $onThinkingDelta = null,
    ): string {
        $this->aborted = false;
        $this->cancellationToken->reset();
        $this->interruptDecider = null;
        $this->interruptDeciderResolved = false;
        $this->lastRunTurns = 0;
        if ($this->abortRequestedChecker !== null && ($this->abortRequestedChecker)()) {
            $this->abort();
            return '(aborted)';
        }
        if ($this->isCancellationRequested()) {
            return '(aborted)';
        }
        if ($this->costTracker->shouldStop()) {
            return '(Cost limit reached: '.$this->costTracker->getSummary().')';
        }
        if (is_string($userInput)) {
            $this->lastUserPrompt = $userInput;
        } elseif (is_array($userInput)) {
            $encoded = json_encode($userInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->lastUserPrompt = is_string($encoded) ? $encoded : null;
        }
        $isSessionStart = ! $this->sessionStarted;
        if ($userInput !== null) {
            $modelInput = $isSessionStart
                ? $this->withInitialTurnContext($userInput)
                : $userInput;
            $this->sessionManager->recordEntry([
                'type' => 'user_message',
                'content' => $userInput,
            ]);
            $this->messageHistory->addUserMessage($modelInput);
        }
        // Fire SessionStart hook on the very first user turn
        if ($isSessionStart) {
            $this->sessionStarted = true;
            $this->initializeDurableToolResultStorage();
            $this->hookExecutor?->execute('SessionStart', [
                'session_id' => $this->sessionManager->getSessionId(),
            ]);
        }
        $turnCount = 0;
        $malformedToolInputRetries = [];
        $totalMalformedToolInputRetries = 0;
        $incompleteResponseRetries = 0;
        $lastToolErrorFingerprint = null;
        $identicalToolErrorBatches = 0;
        $finalizationReason = null;
        $systemPrompt = $this->systemPrompt ??= $this->contextBuilder->buildSystemPrompt();
        while ($turnCount < $this->maxTurns && ! $this->isCancellationRequested()) {
            if ($this->eventPump !== null) {
                ($this->eventPump)();
            }
            if ($this->isCancellationRequested()) {
                return '(aborted)';
            }
            if ($this->costTracker->shouldStop()) {
                return '(Cost limit reached: '.$this->costTracker->getSummary().')';
            }
            $this->synchronizeRuntimeContextBudget();
            $turnCount++;
            if ($turnCount > $this->lastRunTurns) {
                $this->lastRunTurns = $turnCount;
                if ($onTurnStart) {
                    $onTurnStart($turnCount);
                }
            }
            // 1. Auto-compact if context is getting large.
            // Use $lastTurnInputTokens (size of the most recent API call's context), NOT
            // $totalInputTokens (cumulative across all turns). Cumulative tokens only grow,
            // so once the threshold is crossed the auto-compact would otherwise fire on
            // every subsequent turn — even after compaction has already cut the context.
            if ($this->contextCompactor->shouldAutoCompact($this->lastTurnInputTokens)) {
                $this->contextCompactor->compact($this->messageHistory);
            } elseif ($this->contextCompactor->shouldMicroCompact($this->lastTurnInputTokens)) {
                $this->contextCompactor->microCompact($this->messageHistory);
            }
            // 2. Build the request from the turn-stable system prompt and current history.
            $messages = $this->messageHistory->getMessagesForApi();
            $activeTools = $this->forceNoTools ? [] : $this->getActiveSkillApiTools();
            $estimatedTokens = ContextBudget::estimateTokens(
                $systemPrompt,
                $messages,
                $activeTools,
            );
            if ($estimatedTokens > $this->maxEstimatedInputTokens) {
                $this->contextCompactor->compact($this->messageHistory);
                $messages = $this->messageHistory->getMessagesForApi();
                $estimatedTokens = ContextBudget::estimateTokens(
                    $systemPrompt,
                    $messages,
                    $activeTools,
                );
                if ($estimatedTokens > $this->maxEstimatedInputTokens) {
                    $this->contextCompactor->emergencyCompact($this->messageHistory);
                    $messages = $this->messageHistory->getMessagesForApi();
                    $estimatedTokens = ContextBudget::estimateTokens(
                        $systemPrompt,
                        $messages,
                        $activeTools,
                    );
                    if ($estimatedTokens > $this->maxEstimatedInputTokens) {
                        $this->throwContextBudgetExceeded($estimatedTokens);
                    }
                }
            }
            // 3. Set up streaming tool executor for early tool execution
            $streamingExecutor = new StreamingToolExecutor(
                toolOrchestrator: $this->toolOrchestrator,
                toolRegistry: $this->toolRegistry,
                cancellationToken: $this->cancellationToken,
                disableEarlyExecution: $this->toolOrchestrator->hasHumanInterruptsConfigured(),
            );
            $context = $this->toolUseContext ??= new ToolUseContext(
                workingDirectory: $this->workingDirectory ?? getcwd(),
                sessionId: $this->sessionManager->getSessionId(),
                shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
                runContext: $this->runContext,
                provider: $this->provider,
                toolRegistry: $this->toolRegistry,
                onWorkingDirectoryChanged: function (string $directory): void {
                    $this->synchronizeToolWorkingDirectory($directory);
                },
            );
            $streamingExecutor->setContext($context, $onToolStart, $onToolComplete);
            $context->beginReadReceiptBatch();
            $readReceiptBatchCommitted = false;

            try {
                // 4. Call Anthropic API with streaming — tools execute as they arrive
                $processor = $this->queryEngine->query(
                    systemPrompt: $systemPrompt,
                    messages: $messages,
                    onTextDelta: $onTextDelta,
                    onToolBlockComplete: fn (array $block, int $index) => $this->isCancellationRequested() ? null : $streamingExecutor->onToolBlockReady($block, $index),
                    onThinkingDelta: $onThinkingDelta,
                    shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
                    toolsOverride: $activeTools,
                );

                if ($this->isCancellationRequested()) {
                    $streamingExecutor->cleanup();

                    return '(aborted)';
                }

                // 5. Track usage
                $usage = $this->normalizeUsage($processor->getUsage());
                $this->recordUsage($usage);

                // 5b. Cost tracking — set model for per-model pricing
                $responseModel = $processor->getModel();
                if ($responseModel !== null) {
                    $this->costTracker->setResponseModel($responseModel);
                }
                $this->costTracker->addUsage(
                    $usage['input_tokens'] ?? 0,
                    $usage['output_tokens'] ?? 0,
                    $usage['cache_creation_input_tokens'] ?? 0,
                    $usage['cache_read_input_tokens'] ?? 0,
                );

                if ($this->costTracker->shouldStop()) {
                    $streamingExecutor->cleanup();

                    return '(Cost limit reached: '.$this->costTracker->getSummary().')';
                }

                $assistantMessage = $processor->toAssistantMessage();
                $toolCalls = $processor->getIndexedToolCalls();
                $stopReason = $processor->getStopReason();

                // 6. Check if we need to execute tools
                if ($toolCalls === []) {
                    $skipIncompleteAssistantHistory = $this->shouldSkipIncompleteAssistantHistory($assistantMessage);
                    if ($this->shouldRetryIncompleteAssistantResponse(
                        $processor,
                        $assistantMessage,
                        $stopReason,
                        $incompleteResponseRetries,
                    )) {
                        $incompleteResponseRetries++;
                        $this->recordIncompleteAssistantResponse($assistantMessage, $skipIncompleteAssistantHistory);
                        $this->messageHistory->addUserMessage(
                            $this->buildIncompleteResponseRetryInstruction(
                                $stopReason,
                                $incompleteResponseRetries,
                                $skipIncompleteAssistantHistory,
                            ),
                        );
                        $turnCount--;

                        continue;
                    }

                    if (! $this->assistantMessageHasVisibleContent($assistantMessage)) {
                        $streamingExecutor->cleanup();

                        throw new \RuntimeException(
                            "Model returned an empty final response after {$incompleteResponseRetries} retries.",
                        );
                    }

                    $incompleteResponseRetries = 0;
                    $this->messageHistory->addAssistantMessage($assistantMessage);
                    $this->persistAssistantTurn($assistantMessage, []);
                    $this->hookExecutor?->execute('Stop', [
                        'session_id' => $this->sessionManager->getSessionId(),
                        'turn' => $turnCount,
                    ]);

                    return $processor->getAccumulatedText();
                }

                $malformedToolUseFailures = $this->findMalformedToolUseFailures($toolCalls, $context);
                if ($malformedToolUseFailures !== []) {
                    $streamingExecutor->cleanup();

                    $failureSignature = $this->malformedFailureSignature($malformedToolUseFailures);
                    $signatureRetries = $malformedToolInputRetries[$failureSignature] ?? 0;

                    if ($signatureRetries < $this->maxMalformedToolInputRetries
                        && $totalMalformedToolInputRetries < $this->maxTotalMalformedToolInputRetries) {
                        $signatureRetries++;
                        $totalMalformedToolInputRetries++;
                        $malformedToolInputRetries[$failureSignature] = $signatureRetries;
                        $assistantMessage = $this->sanitizeMalformedToolAssistantMessage(
                            $assistantMessage,
                            $malformedToolUseFailures,
                        );
                        $toolResults = $this->buildMalformedToolRetryResults($malformedToolUseFailures);
                        $this->messageHistory->addAssistantMessage($assistantMessage);
                        $this->messageHistory->addToolResultMessage(
                            $toolResults,
                            $this->buildMalformedToolRetryInstruction(
                                $malformedToolUseFailures,
                                $signatureRetries,
                            ),
                        );
                        $this->persistAssistantTurn($assistantMessage, $toolResults);
                        $turnCount--;

                        continue;
                    }

                    throw new \RuntimeException(
                        'Model returned malformed tool input repeatedly: '.implode(
                            '; ',
                            array_map(
                                fn (array $failure): string => $failure['name'].': '.$failure['error'],
                                $malformedToolUseFailures,
                            ),
                        ),
                    );
                }
                $malformedToolInputRetries = [];
                $totalMalformedToolInputRetries = 0;
                $incompleteResponseRetries = 0;

                $this->messageHistory->addAssistantMessage($assistantMessage);

                if ($this->toolOrchestrator->hasHumanInterruptsConfigured()) {
                    $blocks = array_map(static fn (ToolCall $call): array => $call->toArray(), $toolCalls);
                    $review = $this->toolOrchestrator->prepareHumanReview($blocks, $context);
                    $toolResults = $review['results'];

                    foreach ($review['prepared'] as $index => $block) {
                        if (isset($review['actions'][$index])) {
                            continue;
                        }
                        try {
                            $toolResults[$index] = $this->toolOrchestrator->executePreparedToolBlock(
                                $block,
                                $context,
                                $onToolStart,
                                $onToolComplete,
                            );
                        } catch (HumanInterruptException $childInterrupt) {
                            foreach ($blocks as $siblingIndex => $sibling) {
                                if ($siblingIndex === $index || isset($toolResults[$siblingIndex])) {
                                    continue;
                                }
                                $toolResults[$siblingIndex] = [
                                    'tool_use_id' => $sibling['id'],
                                    'content' => 'Deferred because a child agent requires human input; retry after the child resumes.',
                                    'is_error' => true,
                                ];
                            }
                            $parentAction = new HumanActionRequest(
                                id: (string) $block['id'],
                                toolName: (string) $block['name'],
                                input: $block['input'] ?? [],
                                description: 'Continue with the resumed child agent result',
                                allowedDecisions: ['respond', 'reject'],
                                agentId: $this->runContext?->agentId,
                            );
                            $parentInterrupt = new HumanInterrupt(
                                id: 'int_'.bin2hex(random_bytes(12)),
                                sessionId: $this->sessionManager->getSessionId(),
                                actions: [$parentAction],
                                createdAt: date('c'),
                                sourceAgentId: $this->runContext?->agentId ?? $this->interruptSourceAgentId,
                                sourceTeam: $this->runContext?->teamName ?? $this->interruptSourceTeam,
                            );
                            $this->sessionManager->recordPendingInterrupt($parentInterrupt->toArray(), [
                                'assistant_message' => $assistantMessage,
                                'blocks' => [$index => $block],
                                'results' => $toolResults,
                                'run_snapshot' => $this->buildRunSnapshot($turnCount),
                                'pending_read_file_state' => $context->getPendingReadFileStateSnapshot(),
                                'allowed_tools' => $this->effectiveAllowedTools(),
                                'interrupt_on' => $this->toolOrchestrator->getInterruptOn(),
                                'enable_ask_user' => $this->toolOrchestrator->isAskUserEnabled(),
                                'permission_interrupts' => $this->toolOrchestrator->arePermissionInterruptsEnabled(),
                                'operation' => $this->runContext?->responseSchema !== null ? 'structured' : 'query',
                                'response_schema' => $this->runContext?->responseSchema,
                            ]);
                            $this->sessionManager->recordInterruptParentLink(
                                $childInterrupt->interrupt->sessionId,
                                $childInterrupt->interrupt->id,
                                $parentInterrupt->sessionId,
                                $parentInterrupt->id,
                                $parentAction->id,
                            );
                            throw $childInterrupt;
                        }
                    }

                    if ($review['actions'] !== []) {
                        $interrupt = new HumanInterrupt(
                            id: 'int_'.bin2hex(random_bytes(12)),
                            sessionId: $this->sessionManager->getSessionId(),
                            actions: array_values($review['actions']),
                            createdAt: date('c'),
                            sourceAgentId: $this->runContext?->agentId ?? $this->interruptSourceAgentId,
                            sourceTeam: $this->runContext?->teamName ?? $this->interruptSourceTeam,
                        );
                        $this->sessionManager->recordPendingInterrupt($interrupt->toArray(), [
                            'assistant_message' => $assistantMessage,
                            'blocks' => $review['prepared'],
                            'results' => $toolResults,
                            'run_snapshot' => $this->buildRunSnapshot($turnCount),
                            'pending_read_file_state' => $context->getPendingReadFileStateSnapshot(),
                            'allowed_tools' => $this->effectiveAllowedTools(),
                            'interrupt_on' => $this->toolOrchestrator->getInterruptOn(),
                            'enable_ask_user' => $this->toolOrchestrator->isAskUserEnabled(),
                            'permission_interrupts' => $this->toolOrchestrator->arePermissionInterruptsEnabled(),
                            'operation' => $this->runContext?->responseSchema !== null ? 'structured' : 'query',
                            'response_schema' => $this->runContext?->responseSchema,
                        ]);

                        // Smart/auto HITL: try to settle the batch automatically.
                        // Returns the resolved tool results when every action was
                        // auto-decided, null when the batch must go to a human
                        // (ask mode, escalation, or fail-closed decider error).
                        $autoResults = $this->settleInterruptBatchAutomatically(
                            $interrupt,
                            $context,
                            $onToolStart,
                            $onToolComplete,
                        );
                        if ($autoResults === null) {
                            throw new HumanInterruptException($interrupt);
                        }
                        $toolResults = $autoResults;
                    }

                    ksort($toolResults);
                    $toolResults = array_values($toolResults);
                } else {

                // Kimi's SSE stream can omit the trailing content_block_stop for the last tool_use block.
                // Reconcile against the finalized assistant message so every tool_use gets a matching tool_result.
                foreach ($toolCalls as $index => $toolCall) {
                    $streamingExecutor->onToolBlockReady($toolCall->toArray(), $index);
                }

                // 7. Collect tool results (early-forked safe tools + queued unsafe tools)
                $toolResults = $streamingExecutor->collectResults();
                }

                $modelOverride = $this->toolOrchestrator->getActiveSkillModelOverride();
                if ($modelOverride !== null) {
                    $this->runContext?->settings->set('model', $modelOverride);
                }

                // 7b. Enforce per-message aggregate budget for large results
                $storage = $this->toolOrchestrator->getToolResultStorage();
                if ($storage !== null) {
                    $uncompactedToolResults = $toolResults;
                    $toolResults = $storage->enforceMessageBudget($toolResults);
                    $this->invalidateCompactedReadReceipts(
                        $toolCalls,
                        $uncompactedToolResults,
                        $toolResults,
                        $context,
                    );
                }

                // 8. Feed tool results back
                $this->messageHistory->addToolResultMessage($toolResults);
                $context->commitReadReceiptBatch();
                $readReceiptBatchCommitted = true;

                // 9. Record transcript
                $this->persistAssistantTurn($assistantMessage, $toolResults);

                if ($this->detectRepeatedToolErrorBatch(
                    $toolCalls,
                    $toolResults,
                    $lastToolErrorFingerprint,
                    $identicalToolErrorBatches,
                )) {
                    $finalizationReason = 'repeated identical tool failure';
                    $turnCount = $this->maxTurns;
                    break;
                }

                // 10. Auto-generate session title after first turn
                if (! $this->autoTitleGenerated && $this->sessionManager->getTitle() === null) {
                    $this->autoTitleGenerated = true;
                    if (is_string($userInput)) {
                        $rawTitle = $userInput;
                    } elseif (is_array($userInput)) {
                        // Array of content blocks (e.g. text + image): extract text parts.
                        // Each block is normally an array like ['type'=>'text','text'=>'...'],
                        // but guard against bare strings that may appear in mixed inputs.
                        $texts = array_filter(
                            array_map(fn ($block) => is_string($block) ? $block : (is_array($block) ? ($block['text'] ?? null) : null), $userInput),
                            fn ($t) => is_string($t) && $t !== '',
                        );
                        $rawTitle = implode(' ', $texts);
                    } else {
                        $rawTitle = '';
                    }
                    $firstInput = mb_substr($rawTitle, 0, 80);
                    $title = preg_replace('/\s+/', ' ', trim($firstInput));
                    if ($title !== '') {
                        $this->persistSessionTitle($title);
                    }
                }
            } finally {
                if (! $readReceiptBatchCommitted) {
                    $context->discardReadReceiptBatch();
                }
                // Generator abandonment aborts the loop before force-closing
                // its SDK streaming Fiber. In that cancellation path, reap
                // resources silently because completion callbacks suspend the
                // Fiber to yield messages. Ordinary failures still emit the
                // terminal aborted tool result before surfacing the error.
                $streamingExecutor->cleanup(notifyCompletion: ! $this->isCancellationRequested());
            }
        }

        if ($this->isCancellationRequested()) {
            return '(aborted)';
        }

        return $this->finalizeAfterTurnLimit(
            $systemPrompt,
            $onTextDelta,
            $onThinkingDelta,
            $finalizationReason,
        );
    }
}
