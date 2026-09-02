<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\HumanActionRequest;
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
    ): AgentRunOutcome {
        $this->aborted = false;
        $this->cancellationToken->reset();
        $this->interruptSettlement->reset();
        $retryPolicy = $this->responseRetryPolicy();
        $this->lastRunTurns = 0;
        if ($this->abortRequestedChecker !== null && ($this->abortRequestedChecker)()) {
            $this->abort();
            return AgentRunOutcome::cancelled();
        }
        if ($this->isCancellationRequested()) {
            return AgentRunOutcome::cancelled();
        }
        if ($this->costTracker->shouldStop()) {
            return AgentRunOutcome::budgetExhausted($this->costTracker->getSummary());
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
            // Anything owed since the previous run (a background task that finished
            // between two send() calls) rides along on this user message.
            $carriedOver = $this->turnInjections->drain(0, $this->sessionManager->getSessionId());
            if ($carriedOver !== null) {
                $modelInput = TurnInjectionQueue::appendTextBlock($modelInput, $carriedOver);
            }
            $this->transcriptLifecycle->recordUserInput(
                $userInput,
                $modelInput,
                $isSessionStart,
                $this->messageHistory,
            );
        }
        // Fire SessionStart hook on the very first user turn
        if ($isSessionStart) {
            $this->sessionStarted = true;
            $this->transcriptLifecycle->bindToolResultStorage();
            $this->hookExecutor?->execute('SessionStart', [
                'session_id' => $this->sessionManager->getSessionId(),
            ]);
        }
        $turnCount = 0;
        $malformedToolInputRetries = [];
        $totalMalformedToolInputRetries = 0;
        $incompleteResponseRetries = 0;
        $goalVerificationRounds = 0;
        $lastToolErrorFingerprint = null;
        $identicalToolErrorBatches = 0;
        $finalizationReason = null;
        $systemPrompt = $this->systemPrompt ??= $this->contextBuilder->buildSystemPrompt();
        while ($turnCount < $this->maxTurns && ! $this->isCancellationRequested()) {
            if ($this->eventPump !== null) {
                ($this->eventPump)();
            }
            if ($this->isCancellationRequested()) {
                return AgentRunOutcome::cancelled();
            }
            if ($this->costTracker->shouldStop()) {
                return AgentRunOutcome::budgetExhausted($this->costTracker->getSummary());
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
                disableEarlyExecution: $this->toolOrchestrator->hasHumanInterruptsConfigured()
                    || $this->toolOrchestrator->requiresSequentialToolExecution(),
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
                turnInjections: $this->turnInjections,
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
                    telemetrySystemPrompt: $this->contextBuilder->getTelemetrySystemPrompt(),
                    telemetryMessages: $this->messageHistory->getTelemetryMessagesForApi(),
                );

                if ($this->isCancellationRequested()) {
                    $streamingExecutor->cleanup();

                    return AgentRunOutcome::cancelled();
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

                    return AgentRunOutcome::budgetExhausted($this->costTracker->getSummary());
                }

                $assistantMessage = $processor->toAssistantMessage();
                $toolCalls = $processor->getIndexedToolCalls();
                $stopReason = $processor->getStopReason();

                // 6. Check if we need to execute tools
                if ($toolCalls === []) {
                    $skipIncompleteAssistantHistory = $retryPolicy
                        ->shouldSkipIncompleteAssistantHistory($assistantMessage);
                    if ($retryPolicy->shouldRetryIncompleteAssistantResponse(
                        $processor,
                        $assistantMessage,
                        $stopReason,
                        $incompleteResponseRetries,
                        $this->maxIncompleteResponseRetries,
                    )) {
                        $incompleteResponseRetries++;
                        if (! $skipIncompleteAssistantHistory
                            && $retryPolicy->assistantMessageHasVisibleContent($assistantMessage)) {
                            $this->messageHistory->addAssistantMessage($assistantMessage);
                            $this->transcriptLifecycle->persistTurn($assistantMessage, []);
                        }
                        $this->messageHistory->addUserMessage(
                            $retryPolicy->buildIncompleteResponseRetryInstruction(
                                $stopReason,
                                $incompleteResponseRetries,
                                $skipIncompleteAssistantHistory,
                            ),
                        );
                        $turnCount--;

                        continue;
                    }

                    if (! $retryPolicy->assistantMessageHasTextContent($assistantMessage)) {
                        $streamingExecutor->cleanup();

                        throw new \RuntimeException(
                            "Model returned an empty final response after {$incompleteResponseRetries} retries.",
                        );
                    }

                    $incompleteResponseRetries = 0;
                    $this->messageHistory->addAssistantMessage($assistantMessage);
                    $this->transcriptLifecycle->persistTurn($assistantMessage, []);

                    // The model believes it is done. When the run has a goal, make it
                    // check its own answer against that goal once before we agree.
                    $goalCheck = $this->goalVerifier?->instruction(
                        $processor->getAccumulatedText(),
                        $goalVerificationRounds,
                    );
                    if ($goalCheck !== null) {
                        $goalVerificationRounds++;
                        $this->turnInjections->push($goalCheck);
                        $this->transcriptLifecycle->recordSyntheticUserMessage(
                            (string) $this->turnInjections->drain(
                                $turnCount,
                                $this->sessionManager->getSessionId(),
                            ),
                            $this->messageHistory,
                        );
                        // Bounded by its own counter, not by maxTurns: a run that is
                        // already at the limit must still get its check.
                        $turnCount--;

                        continue;
                    }

                    $this->hookExecutor?->execute('Stop', [
                        'session_id' => $this->sessionManager->getSessionId(),
                        'turn' => $turnCount,
                    ]);

                    return AgentRunOutcome::normal($processor->getAccumulatedText());
                }

                $malformedToolUseFailures = $retryPolicy
                    ->findMalformedToolUseFailures($toolCalls, $context);
                if ($malformedToolUseFailures !== []) {
                    $streamingExecutor->cleanup();

                    $failureSignature = $retryPolicy
                        ->malformedFailureSignature($malformedToolUseFailures);
                    $signatureRetries = $malformedToolInputRetries[$failureSignature] ?? 0;

                    if ($signatureRetries < $this->maxMalformedToolInputRetries
                        && $totalMalformedToolInputRetries < $this->maxTotalMalformedToolInputRetries) {
                        $signatureRetries++;
                        $totalMalformedToolInputRetries++;
                        $malformedToolInputRetries[$failureSignature] = $signatureRetries;
                        $assistantMessage = $retryPolicy->sanitizeMalformedToolAssistantMessage(
                            $assistantMessage,
                            $malformedToolUseFailures,
                        );
                        $toolResults = $retryPolicy
                            ->buildMalformedToolRetryResults($malformedToolUseFailures);
                        $this->messageHistory->addAssistantMessage($assistantMessage);
                        $this->messageHistory->addToolResultMessage(
                            $toolResults,
                            $retryPolicy->buildMalformedToolRetryInstruction(
                                $malformedToolUseFailures,
                                $signatureRetries,
                            ),
                        );
                        $this->transcriptLifecycle->persistTurn($assistantMessage, $toolResults);
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
                        $autoResults = $this->interruptSettlement->settle(
                            $interrupt,
                            $context,
                            $onToolStart,
                            $onToolComplete,
                            $this->workingDirectory,
                            $this->lastUserPrompt ?? '',
                            $this->autoDecisionHandler,
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
                    ReadReceiptVisibility::invalidate(
                        $toolCalls,
                        $uncompactedToolResults,
                        $toolResults,
                        $context,
                    );
                }

                // 8. Feed tool results back, carrying anything owed to the model this turn.
                // A trailing text block is cache-safe: the single breakpoint sits on the
                // penultimate message, and this message has not been sent yet.
                $this->messageHistory->addToolResultMessage(
                    $toolResults,
                    $this->turnInjections->drain($turnCount, $this->sessionManager->getSessionId()),
                );
                $context->commitReadReceiptBatch();
                $readReceiptBatchCommitted = true;

                // 9. Record transcript
                $this->transcriptLifecycle->persistTurn($assistantMessage, $toolResults);

                // 9b. A tool may hand control back to the host (ExitPlanMode in return mode).
                $requestedTermination = $this->turnInjections->takeTermination();
                if ($requestedTermination !== null) {
                    $this->hookExecutor?->execute('Stop', [
                        'session_id' => $this->sessionManager->getSessionId(),
                        'turn' => $turnCount,
                    ]);

                    return AgentRunOutcome::terminated(
                        $requestedTermination['reason'],
                        $requestedTermination['text'],
                    );
                }

                if ($this->repeatedToolFailureDetector->detect(
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
                if (! $this->autoTitleGenerated
                    && $this->transcriptLifecycle->persistInitialTitle($userInput)) {
                    $this->autoTitleGenerated = true;
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
            return AgentRunOutcome::cancelled();
        }

        $this->synchronizeRuntimeContextBudget();

        return $this->finalResponseCoordinator->finalize(
            $systemPrompt,
            $onTextDelta,
            $onThinkingDelta,
            $finalizationReason,
            $this->maxTurns,
            $this->maxEstimatedInputTokens,
            $this->lastRunTurns,
            $this->contextCompactor,
            $this->messageHistory,
            $this->queryEngine,
            $this->contextBuilder,
            $this->costTracker,
            $this->transcriptLifecycle,
            $this->hookExecutor,
            $this->sessionManager,
            fn (): bool => $this->cancellationToken->isCancelled(),
            fn (array $usage): array => $this->normalizeUsage($usage),
            function (array $usage): void { $this->recordUsage($usage); },
        );
    }

}
