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

class AgentLoop
{
    private int $maxTurns = 50;

    private int $maxMalformedToolInputRetries = 4;

    private int $maxTotalMalformedToolInputRetries = 10;

    private int $maxIncompleteResponseRetries = 2;

    private bool $aborted = false;

    private bool $durablePersistenceFailed = false;

    private bool $sessionStarted = false;

    /** @var array<int, array<string, mixed>>|null Cache-stable prompt for this loop/session. */
    private ?array $systemPrompt = null;

    private int $totalInputTokens = 0;

    private int $totalOutputTokens = 0;

    private int $totalCacheCreationTokens = 0;

    private int $totalCacheReadTokens = 0;

    /** Tracks the most recent API call's input token count for auto-compact decisions. */
    private int $lastTurnInputTokens = 0;

    /** Number of logical agent turns consumed by the most recent run. */
    private int $lastRunTurns = 0;

    private bool $autoTitleGenerated = false;

    private ?string $workingDirectory = null;

    private CancellationToken $cancellationToken;

    private ?ToolUseContext $toolUseContext = null;

    private ?string $interruptSourceAgentId = null;

    private ?string $interruptSourceTeam = null;

    private ?\Closure $eventPump = null;

    /** @var \Closure(\HaoCode\Sdk\Message): void|null */
    private ?\Closure $autoDecisionHandler = null;

    /** @var \Closure(): bool|null */
    private ?\Closure $abortRequestedChecker = null;

    /** Decider for smart/auto HITL modes; rebuilt per run so circuit-breaker state stays run-scoped. */
    private ?SmartInterruptDecider $interruptDecider = null;

    private bool $interruptDeciderResolved = false;

    /** Most recent user input, used as guardian-review context in smart HITL mode. */
    private ?string $lastUserPrompt = null;

    /** Live sandbox for this run; used to export a durable HITL lease. */
    private ?\HaoCode\Sdk\Sandbox\SandboxRuntime $sandboxRuntime = null;

    /** When true, model turns advertise no tools (structured JSON correction). */
    private bool $forceNoTools = false;

    public function __construct(
        private readonly QueryEngine $queryEngine,
        private readonly ToolOrchestrator $toolOrchestrator,
        private readonly ContextBuilder $contextBuilder,
        private readonly MessageHistory $messageHistory,
        private readonly PermissionChecker $permissionChecker,
        private readonly SessionManager $sessionManager,
        private readonly ContextCompactor $contextCompactor,
        private readonly CostTracker $costTracker,
        private readonly ToolRegistry $toolRegistry,
        private readonly ?HookExecutor $hookExecutor = null,
        private readonly ?PhoenixTracer $tracer = null,
        ?CancellationToken $cancellationToken = null,
        private readonly int $maxEstimatedInputTokens = ContextBudget::MAX_ESTIMATED_INPUT_TOKENS,
        private readonly ?AgentRunContext $runContext = null,
        private readonly ?\HaoCode\Services\Api\LlmProvider $provider = null,
    ) {
        $this->cancellationToken = $cancellationToken ?? new CancellationToken();
    }

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->toolOrchestrator->setPermissionPromptHandler($handler);
    }

    public function setMaxTurns(int $maxTurns): void
    {
        $this->maxTurns = $maxTurns;
    }

    /**
     * Bind the run's sandbox so interrupt snapshots can reattach the same root.
     *
     * @internal
     */
    public function attachSandboxRuntime(?\HaoCode\Sdk\Sandbox\SandboxRuntime $sandboxRuntime): void
    {
        $this->sandboxRuntime = $sandboxRuntime;
    }

    /**
     * Force the next {@see run()} to advertise zero tools (structured correction).
     *
     * @internal
     */
    public function forceNoTools(bool $enabled = true): void
    {
        $this->forceNoTools = $enabled;
    }

    /**
     * Register a non-blocking external event pump invoked between agent turns.
     * Replaces any existing pump. Prefer {@see appendEventPump()} when composing.
     *
     * @internal
     */
    public function setEventPump(?callable $eventPump): void
    {
        $this->eventPump = $eventPump === null ? null : \Closure::fromCallable($eventPump);
    }

    /**
     * Chain an additional event pump after any existing one (e.g. MCP poll + abort).
     *
     * @internal
     */
    public function appendEventPump(callable $eventPump): void
    {
        $next = \Closure::fromCallable($eventPump);
        $prev = $this->eventPump;
        $this->eventPump = static function () use ($prev, $next): void {
            if ($prev !== null) {
                ($prev)();
            }
            ($next)();
        };
    }

    /**
     * Register a handler invoked with each Message::autoDecision event emitted
     * by the smart/auto HITL decider. Streaming entry points use this to yield
     * the events; non-streaming callers may leave it unset.
     *
     * @internal
     */
    public function setAutoDecisionHandler(?callable $handler): void
    {
        $this->autoDecisionHandler = $handler === null ? null : \Closure::fromCallable($handler);
    }

    /**
     * Re-synchronize an external cancellation source after per-operation state
     * is reset and before any provider request is started.
     *
     * @internal
     */
    public function setAbortRequestedChecker(?callable $checker): void
    {
        $this->abortRequestedChecker = $checker === null ? null : \Closure::fromCallable($checker);
    }

    public function setWorkingDirectory(string $dir): void
    {
        $this->workingDirectory = $dir;
        $this->toolUseContext = null;
        $this->sessionManager->setCurrentWorkingDirectory($dir);
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->cancellationToken->cancel();
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /**
     * Run the agent loop for a user message.
     *
     * @param  string|array  $userInput  Plain text, or array of content blocks for mixed text+image
     * @return string The final assistant response text
     */
    public function run(
        string|array $userInput,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ): string {
        $this->assertDurableConversationUsable();
        $originalModel = $this->runContext?->settings->getModel();
        $this->toolOrchestrator->resetSkillScope();
        $agentSpan = $this->tracer?->startSpan(
            name: 'agent.run',
            openInferenceKind: PhoenixTracer::KIND_AGENT,
            attributes: [
                'input.value' => is_string($userInput) ? $userInput : json_encode($userInput, JSON_UNESCAPED_UNICODE),
                'input.mime_type' => is_string($userInput) ? 'text/plain' : 'application/json',
                'session.id' => $this->sessionManager->getSessionId(),
            ],
        );
        $agentScope = $agentSpan?->activate();

        try {
            $output = $this->runInternal($userInput, $onTextDelta, $onToolStart, $onToolComplete, $onTurnStart, $onThinkingDelta);
            // Route through PhoenixTracer::setAttribute so redact_messages
            // masks the agent's final answer; a direct setAttribute bypasses
            // the sanitizer that startSpan() applies to its initial attributes.
            $this->tracer?->setAttribute($agentSpan, 'output.value', $output);
            $this->tracer?->setAttribute($agentSpan, 'llm.token_count.prompt', $this->totalInputTokens);
            $this->tracer?->setAttribute($agentSpan, 'llm.token_count.completion', $this->totalOutputTokens);

            return $output;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($agentSpan, $e);
            throw $e;
        } finally {
            if ($originalModel !== null && $this->toolOrchestrator->getActiveSkillModelOverride() !== null) {
                $this->runContext?->settings->set('model', $originalModel);
            }
            $this->toolOrchestrator->resetSkillScope();
            $this->cancellationToken->close();
            $agentScope?->detach();
            $agentSpan?->end();
        }
    }

    /**
     * Resolve a durable interrupt and continue the model loop without adding a new user message.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @internal
     */
    public function resumeInterrupt(
        string $interruptId,
        array $decisions,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ): string {
        $this->assertDurableConversationUsable();
        if ($this->abortRequestedChecker !== null && ($this->abortRequestedChecker)()) {
            $this->lastRunTurns = 0;
            $this->abort();

            return '(aborted)';
        }
        $context = $this->toolUseContext ??= new ToolUseContext(
            workingDirectory: $this->workingDirectory ?? (getcwd() ?: '/'),
            sessionId: $this->sessionManager->getSessionId(),
            shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
            runContext: $this->runContext,
            provider: $this->provider,
            toolRegistry: $this->toolRegistry,
        );
        $resolution = (new HumanInterruptCoordinator($this->sessionManager, $this->toolOrchestrator))->resolve(
            $interruptId,
            $decisions,
            $context,
            $onToolStart,
            $onToolComplete,
        );
        $this->interruptSourceAgentId = $resolution['interrupt']->sourceAgentId;
        $this->interruptSourceTeam = $resolution['interrupt']->sourceTeam;
        $this->messageHistory->addToolResultMessage($resolution['results']);

        return $this->runInternal(null, $onTextDelta, $onToolStart, $onToolComplete, $onTurnStart, $onThinkingDelta);
    }

    /**
     * Attempt to settle a pending interrupt batch without a human (smart/auto
     * HITL modes). Emits one auto-decision event per action through the
     * registered handler. When the whole batch is auto-decided, the decisions
     * are applied through the exact same path a human resume would take
     * (HumanInterruptCoordinator::resolve against the recorded checkpoint), so
     * validation, checkpoint, and tool-execution semantics are preserved.
     *
     * @return array<int, array<string, mixed>>|null Resolved tool results, or
     *         null when the batch must interrupt for a human.
     */
    private function settleInterruptBatchAutomatically(
        HumanInterrupt $interrupt,
        ToolUseContext $context,
        ?callable $onToolStart,
        ?callable $onToolComplete,
    ): ?array {
        $decider = $this->smartInterruptDecider();
        if ($decider === null) {
            return null; // ask mode: zero behaviour change.
        }

        $batch = $decider->decide($interrupt, $this->lastUserPrompt ?? '');
        foreach ($batch['events'] as $event) {
            if ($this->autoDecisionHandler !== null) {
                ($this->autoDecisionHandler)($event);
            }
        }
        if ($batch['status'] !== 'auto') {
            return null;
        }

        $resolution = (new HumanInterruptCoordinator($this->sessionManager, $this->toolOrchestrator))->resolve(
            $interrupt->id,
            $batch['decisions'],
            $context,
            $onToolStart,
            $onToolComplete,
        );

        return $resolution['results'];
    }

    /**
     * Build the per-run smart/auto interrupt decider. Returns null in ask mode
     * so the default path stays untouched. The reviewer reuses the run's
     * provider settings, with the model overridden by hitlReviewModel when set.
     */
    private function smartInterruptDecider(): ?SmartInterruptDecider
    {
        if ($this->interruptDeciderResolved) {
            return $this->interruptDecider;
        }
        $this->interruptDeciderResolved = true;

        $mode = $this->runContext?->hitlMode ?? 'ask';
        if (! in_array($mode, ['smart', 'auto'], true)) {
            return null;
        }

        $cwd = $this->workingDirectory
            ?? $this->runContext?->workingDirectory
            ?? (getcwd() ?: '/');
        $sandbox = $this->runContext?->sandbox;
        if ($sandbox !== null && ! is_dir($cwd)) {
            // A sandbox remote cwd (e.g. '/workspace') usually does not exist
            // on the PHP host; classify against the host project directory
            // instead of failing every action closed.
            $cwd = $this->runContext?->projectDirectory ?? $cwd;
        }
        $allowlistPath = $this->runContext?->hitlAllowlistPath;
        $allowlist = is_string($allowlistPath) && trim($allowlistPath) !== ''
            ? HitlAllowlist::fromFile($allowlistPath)
            : null;
        $reviewer = null;
        if ($mode === 'smart') {
            $settings = $this->runContext?->settings;
            $apiKey = $settings?->getApiKey();
            $baseUrl = $settings?->getBaseUrl();
            $providerType = $settings?->getProviderType();
            $reviewer = new HitlReviewer([
                'apiKey' => is_string($apiKey) && trim($apiKey) !== '' ? trim($apiKey) : null,
                'model' => $this->runContext?->hitlReviewModel ?? $settings?->getModel(),
                'baseUrl' => is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : null,
                'providerType' => is_string($providerType) && trim($providerType) !== '' ? trim($providerType) : null,
                'maxBudgetUsd' => $this->runContext?->budgetLedger?->getLimit(),
                'oauthBearer' => null,
            ], $cwd, usageAccumulator: $this->runContext?->usageAccumulator, budgetLedger: $this->runContext?->budgetLedger);
        }

        return $this->interruptDecider = new SmartInterruptDecider(
            mode: $mode,
            reviewer: $reviewer,
            cwd: $cwd,
            fallbackSessionId: (string) $this->sessionManager->getSessionId(),
            sandbox: $sandbox,
            allowlist: $allowlist,
        );
    }

    /**
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

            // Wire up tool result persistence storage only for durable sessions.
            if ($this->sessionManager->isPersistenceEnabled()) {
                $toolResultStorage = new ToolResultStorage($this->sessionManager->getSessionId());
                $this->toolOrchestrator->setToolResultStorage($toolResultStorage);
            }

            $this->hookExecutor?->execute('SessionStart', [
                'session_id' => $this->sessionManager->getSessionId(),
            ]);
        }

        $turnCount = 0;
        $malformedToolInputRetries = [];
        $totalMalformedToolInputRetries = 0;
        $incompleteResponseRetries = 0;
        $systemPrompt = $this->systemPrompt ??= $this->contextBuilder->buildSystemPrompt();

        while ($turnCount < $this->maxTurns && ! $this->aborted) {
            if ($this->eventPump !== null) {
                ($this->eventPump)();
            }
            if ($this->costTracker->shouldStop()) {
                return '(Cost limit reached: '.$this->costTracker->getSummary().')';
            }
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
                        throw new \RuntimeException(
                            'Estimated model input exceeds the safe context budget after emergency compaction. '.
                            'Reduce the user input, project instructions, or advertised tools.',
                        );
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
            );
            $streamingExecutor->setContext($context, $onToolStart, $onToolComplete);

            try {
                // 4. Call Anthropic API with streaming — tools execute as they arrive
                $processor = $this->queryEngine->query(
                    systemPrompt: $systemPrompt,
                    messages: $messages,
                    onTextDelta: $onTextDelta,
                    onToolBlockComplete: fn (array $block, int $index) => $this->aborted ? null : $streamingExecutor->onToolBlockReady($block, $index),
                    onThinkingDelta: $onThinkingDelta,
                    shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
                    toolsOverride: $activeTools,
                );

                if ($this->aborted) {
                    $streamingExecutor->cleanup();

                    return '(aborted)';
                }

                // 5. Track usage
                $usage = $processor->getUsage();
                $this->recordUsage($usage);

                // 5b. Cost tracking — set model for per-model pricing
                $responseModel = $processor->getModel();
                if ($responseModel !== null) {
                    $this->costTracker->setModel($responseModel);
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

                // 9. Record transcript
                $this->persistAssistantTurn($assistantMessage, $toolResults);

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
            } catch (\Throwable $e) {
                $streamingExecutor->cleanup();
                throw $e;
            }
        }

        if ($this->aborted) {
            return '(aborted)';
        }

        return $this->finalizeAfterTurnLimit($systemPrompt, $onTextDelta, $onThinkingDelta);
    }

    /**
     * Capture the scoped child-run identity needed for a process-safe resume.
     *
     * @return array<string, mixed>
     */
    private function buildRunSnapshot(int $turnCount): array
    {
        return [
            'cwd' => $this->runContext?->workingDirectory,
            'project_directory' => $this->runContext?->projectDirectory,
            'worktree_path' => $this->runContext?->worktreePath,
            'worktree_branch' => $this->runContext?->worktreeBranch,
            'managed_worktree' => $this->runContext?->managedWorktree ?? false,
            'background_owner_agent_id' => $this->runContext?->backgroundOwnerAgentId,
            'model' => $this->runContext?->settings->getModel(),
            'system_prompt' => $this->runContext?->settings->getSystemPrompt(),
            'append_system_prompt' => $this->runContext?->settings->getAppendSystemPrompt(),
            'omit_project_instructions' => $this->runContext?->omitProjectInstructions ?? false,
            'agent_type' => $this->runContext?->agentType,
            'read_only' => $this->runContext?->readOnly ?? false,
            'max_turns_remaining' => max(1, $this->maxTurns - $turnCount),
            'allowed_tools' => $this->effectiveAllowedTools(),
            'active_skill_allowed_tools' => $this->toolOrchestrator->getActiveSkillAllowedTools(),
            'active_skill_model_override' => $this->toolOrchestrator->getActiveSkillModelOverride(),
            'active_skill_context' => $this->toolOrchestrator->getActiveSkillContext(),
            // Prefer shared tree totals so nested AgentAsTool usage survives HITL resume.
            'estimated_cost_usd' => $this->getEstimatedCost(),
            'budget_ledger_id' => $this->runContext?->budgetLedger?->getId(),
            'budget_limit_usd' => $this->runContext?->budgetLedger?->getLimit(),
            'total_input_tokens' => $this->getTotalInputTokens(),
            'total_output_tokens' => $this->getTotalOutputTokens(),
            'total_cache_creation_tokens' => $this->getCacheCreationTokens(),
            'total_cache_read_tokens' => $this->getCacheReadTokens(),
            'last_turn_input_tokens' => $this->lastTurnInputTokens,
            'sandbox_lease' => $this->sandboxRuntime?->exportLease(),
        ];
    }

    /** @return string[] */
    private function effectiveAllowedTools(): array
    {
        $allowed = $this->toolOrchestrator->getAdvertisedAllowedTools()
            ?? array_keys($this->toolRegistry->getAllTools());
        if (isset($this->toolRegistry->getAllTools()['Skill'])
            && ! in_array('Skill', $allowed, true)) {
            $allowed[] = 'Skill';
        }

        return $allowed;
    }

    /** @return array<int, array<string, mixed>> */
    private function getActiveSkillApiTools(): array
    {
        $tools = $this->toolRegistry->toApiTools();
        $allowedTools = $this->toolOrchestrator->getAdvertisedAllowedTools();
        if ($allowedTools === null) {
            return $tools;
        }

        $allowedTools[] = 'Skill';

        return array_values(array_filter(
            $tools,
            static fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), $allowedTools, true),
        ));
    }

    private function withInitialTurnContext(string|array $userInput): string|array
    {
        $turnContext = $this->contextBuilder->buildTurnContext();
        if ($turnContext === '') {
            return $userInput;
        }

        $contextBlock = [
            'type' => 'text',
            'text' => "# Initial workspace context\n\n{$turnContext}",
        ];

        if (is_array($userInput)) {
            return array_merge([$contextBlock], $userInput);
        }

        return [
            $contextBlock,
            ['type' => 'text', 'text' => $userInput],
        ];
    }

    private function finalizeAfterTurnLimit(
        array $systemPrompt,
        ?callable $onTextDelta,
        ?callable $onThinkingDelta,
    ): string {
        $this->contextCompactor->microCompact($this->messageHistory);
        $messages = $this->messageHistory->getMessagesForApi();
        $messages[] = [
            'role' => 'user',
            'content' => 'The tool-turn limit has been reached. Do not call tools. Return the best final answer now using the evidence already collected, and state any remaining uncertainty.',
        ];

        $estimatedTokens = ContextBudget::estimateTokens($systemPrompt, $messages, []);
        if ($estimatedTokens > $this->maxEstimatedInputTokens) {
            $this->contextCompactor->compact($this->messageHistory);
            $messages = $this->messageHistory->getMessagesForApi();
            $messages[] = [
                'role' => 'user',
                'content' => 'Return the final answer now without tools, using the retained evidence.',
            ];
            if (ContextBudget::estimateTokens($systemPrompt, $messages, []) > $this->maxEstimatedInputTokens) {
                $this->contextCompactor->emergencyCompact($this->messageHistory);
                $messages = $this->messageHistory->getMessagesForApi();
                $messages[] = [
                    'role' => 'user',
                    'content' => 'Return a concise final answer now without tools, using the retained evidence previews.',
                ];
            }
        }

        $processor = $this->queryEngine->query(
            systemPrompt: $systemPrompt,
            messages: $messages,
            onTextDelta: $onTextDelta,
            onThinkingDelta: $onThinkingDelta,
            shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
            toolsOverride: [],
        );

        $usage = $processor->getUsage();
        $this->recordUsage($usage);
        if ($processor->getModel() !== null) {
            $this->costTracker->setModel($processor->getModel());
        }
        $this->costTracker->addUsage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );

        $assistantMessage = $processor->toAssistantMessage();
        $this->messageHistory->addAssistantMessage($assistantMessage);
        $this->persistAssistantTurn($assistantMessage, []);

        $answer = trim($processor->getAccumulatedText());

        return $answer !== ''
            ? $answer
            : "Reached maximum turn limit ({$this->maxTurns}) without a final answer.";
    }

    public function getTotalInputTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getInputTokens() ?? $this->totalInputTokens;
    }

    /** @internal */
    public function getLocalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getLastTurnInputTokens(): int
    {
        return $this->lastTurnInputTokens;
    }

    public function getLastRunTurns(): int
    {
        return $this->lastRunTurns;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getOutputTokens() ?? $this->totalOutputTokens;
    }

    /** @internal */
    public function getLocalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function getEstimatedCost(): float
    {
        return $this->costTracker->getTotalCost();
    }

    /** @internal */
    public function getLocalEstimatedCost(): float
    {
        return $this->costTracker->getLocalTotalCost();
    }

    public function isCostEstimateAvailable(): bool
    {
        return $this->costTracker->isPricingAvailable();
    }

    public function getCostTracker(): CostTracker
    {
        return $this->costTracker;
    }

    /** @internal */
    public function getBudgetLedger(): ?\HaoCode\Services\Cost\BudgetLedger
    {
        return $this->runContext?->budgetLedger;
    }

    /** @internal */
    public function getRunContext(): ?AgentRunContext
    {
        return $this->runContext;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getCacheCreationTokens()
            ?? $this->totalCacheCreationTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getCacheReadTokens()
            ?? $this->totalCacheReadTokens;
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function recordUsage(array $usage): void
    {
        $this->lastTurnInputTokens = (int) ($usage['context_input_tokens'] ?? $usage['input_tokens'] ?? 0);
        $input = $this->lastTurnInputTokens;
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        $this->totalInputTokens += $input;
        $this->totalOutputTokens += $output;
        $this->totalCacheCreationTokens += $cacheCreation;
        $this->totalCacheReadTokens += $cacheRead;

        $this->runContext?->usageAccumulator?->add($input, $output, $cacheCreation, $cacheRead);
    }

    public function getMessageHistory(): MessageHistory
    {
        return $this->messageHistory;
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    /**
     * Install a run-lifetime skill capability envelope (used by forked skills).
     *
     * @param  list<string>|null  $allowedTools
     * @internal
     */
    public function setBaseSkillScope(?array $allowedTools): void
    {
        $this->toolOrchestrator->setBaseSkillScope($allowedTools);
    }

    /** @internal */
    public function restoreRunSnapshot(array $snapshot): void
    {
        if (is_array($snapshot['allowed_tools'] ?? null)) {
            $this->toolOrchestrator->setResumeAllowedTools($snapshot['allowed_tools']);
        }
        $this->toolOrchestrator->restoreSkillScope(
            is_array($snapshot['active_skill_allowed_tools'] ?? null)
                ? $snapshot['active_skill_allowed_tools']
                : null,
            is_string($snapshot['active_skill_model_override'] ?? null)
                ? $snapshot['active_skill_model_override']
                : null,
            is_string($snapshot['active_skill_context'] ?? null)
                ? $snapshot['active_skill_context']
                : null,
        );

        $this->totalInputTokens = max(0, (int) ($snapshot['total_input_tokens'] ?? 0));
        $this->totalOutputTokens = max(0, (int) ($snapshot['total_output_tokens'] ?? 0));
        $this->totalCacheCreationTokens = max(0, (int) ($snapshot['total_cache_creation_tokens'] ?? 0));
        $this->totalCacheReadTokens = max(0, (int) ($snapshot['total_cache_read_tokens'] ?? 0));
        $this->lastTurnInputTokens = max(0, (int) ($snapshot['last_turn_input_tokens'] ?? 0));
        if (is_numeric($snapshot['estimated_cost_usd'] ?? null)) {
            $this->costTracker->setTotalCost(max(0.0, (float) $snapshot['estimated_cost_usd']));
        }

        // Seed a fresh shared accumulator so resume QueryResult usage matches the
        // checkpoint (AgentAsTool children add on top of this base).
        $acc = $this->runContext?->usageAccumulator;
        if ($acc !== null
            && $acc->getInputTokens() === 0
            && $acc->getOutputTokens() === 0
            && $acc->getCacheCreationTokens() === 0
            && $acc->getCacheReadTokens() === 0
            && ($this->totalInputTokens > 0
                || $this->totalOutputTokens > 0
                || $this->totalCacheCreationTokens > 0
                || $this->totalCacheReadTokens > 0)) {
            $acc->add(
                $this->totalInputTokens,
                $this->totalOutputTokens,
                $this->totalCacheCreationTokens,
                $this->totalCacheReadTokens,
            );
        }
    }

    public function resetSessionMetrics(): void
    {
        $this->aborted = false;
        $this->sessionStarted = false;
        $this->runContext?->usageAccumulator?->reset();
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->totalCacheCreationTokens = 0;
        $this->totalCacheReadTokens = 0;
        $this->lastTurnInputTokens = 0;
        $this->lastRunTurns = 0;
        $this->costTracker->reset();
    }

    /**
     * Revoke write authorization when aggregate-budget compaction means the
     * model received only a persisted preview of an otherwise complete Read.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, array{tool_use_id: string, content: string, is_error: bool}>  $before
     * @param  array<int, array{tool_use_id: string, content: string, is_error: bool}>  $after
     */
    private function invalidateCompactedReadReceipts(
        array $toolCalls,
        array $before,
        array $after,
        ToolUseContext $context,
    ): void {
        $readPaths = [];
        foreach ($toolCalls as $toolCall) {
            $path = $toolCall->input['file_path'] ?? null;
            if ($toolCall->name === 'Read' && is_string($path) && $path !== '') {
                $readPaths[$toolCall->id] = $path;
            }
        }
        if ($readPaths === []) {
            return;
        }

        $visibleContent = [];
        foreach ($after as $result) {
            $id = $result['tool_use_id'] ?? null;
            $content = $result['content'] ?? null;
            if (is_string($id) && is_string($content)) {
                $visibleContent[$id] = $content;
            }
        }

        foreach ($before as $result) {
            $id = $result['tool_use_id'] ?? null;
            $content = $result['content'] ?? null;
            if (! is_string($id) || ! is_string($content)
                || ! isset($readPaths[$id], $visibleContent[$id])
                || hash_equals($content, $visibleContent[$id])
            ) {
                continue;
            }

            $context->markFileReadIncomplete($readPaths[$id]);
        }
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @return array<int, array{id: string, name: string, error: string}>
     */
    private function findMalformedToolUseFailures(array $toolCalls, ToolUseContext $context): array
    {
        $failures = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->toolRegistry->getTool($toolCall->name);
            if ($tool === null) {
                continue;
            }

            $inputJsonError = $toolCall->inputError;
            if (is_string($inputJsonError) && $inputJsonError !== '') {
                $rawInputSnippet = $this->summarizeMalformedToolInput($toolCall->rawInput);
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $inputJsonError.($rawInputSnippet !== null ? ' Raw input: '.$rawInputSnippet : ''),
                ];

                continue;
            }

            $rawInput = $toolCall->input;
            if (! is_array($rawInput)) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => 'Tool input must decode to an object.',
                ];

                continue;
            }

            try {
                $validatedInput = $tool->inputSchema()->validate($rawInput);
            } catch (\InvalidArgumentException $e) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $e->getMessage(),
                ];

                continue;
            } catch (\TypeError $e) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $semanticError = $tool->validateInput($validatedInput, $context);
            if ($semanticError !== null) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $semanticError,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function sanitizeMalformedToolAssistantMessage(array $assistantMessage, array $failures): array
    {
        $failedToolIds = [];
        foreach ($failures as $failure) {
            $failedToolIds[$failure['id']] = true;
        }

        $sanitizedContent = [];
        foreach ($assistantMessage['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $toolUseId = $block['id'] ?? null;
            if (! is_string($toolUseId) || ! isset($failedToolIds[$toolUseId])) {
                continue;
            }

            if (! is_array($block['input'] ?? null)) {
                $block['input'] = [];
            }

            $sanitizedContent[] = $block;
        }

        $assistantMessage['content'] = array_values($sanitizedContent);

        return $assistantMessage;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    private function buildMalformedToolRetryResults(array $failures): array
    {
        return array_map(function (array $failure): array {
            return [
                'tool_use_id' => $failure['id'],
                'content' => $this->buildMalformedToolRetryMessage($failure['name'], $failure['error']),
                'is_error' => true,
            ];
        }, $failures);
    }

    private function buildMalformedToolRetryMessage(string $toolName, string $error): string
    {
        $lines = [
            'Tool input validation failed. This tool call was not executed.',
            $error,
            'Retry with corrected input.',
        ];

        if ($error === 'Tool input must decode to an object.') {
            $lines[] = 'Tool inputs must be JSON objects that match the tool schema.';
        }

        if ($toolName === 'Write') {
            $lines[] = 'For Write: include an absolute file_path, send the complete file contents in content, and do not prefix JSON or file contents with stray ":" placeholder text.';
            if ($this->isToolInputJsonFailure($error)) {
                $lines[] = 'If the file content is large or multiline, stop resending the same broken JSON blob. Split the file into smaller writes or create it in smaller Bash heredoc chunks.';
                $lines[] = 'For large source files, create a tiny scaffold first, then use Edit in small chunks no larger than about 8 lines or 400 characters.';
            }
        }

        if ($toolName === 'TodoWrite') {
            $lines[] = 'For TodoWrite: send a todos array with real tasks, or skip TodoWrite entirely if there is nothing useful to track.';
        }

        if ($toolName === 'Bash') {
            $lines[] = 'For Bash: do not send shell no-ops or probes such as ": > /dev/null 2>&1" or "true". If you need context first, run a real command like "pwd" or "ls".';
            if ($this->isToolInputJsonFailure($error)) {
                $lines[] = 'If the command is large or multiline, split it into smaller concrete commands instead of resending one giant heredoc payload.';
                $lines[] = 'Do not send large heredocs, inline python/node scripts, base64 blobs, or long printf command lists in one Bash call.';
            }
        }

        if ($toolName === 'TeamCreate') {
            $lines[] = 'For TeamCreate: include name, task, and a non-empty members array; every member needs a role.';
            $lines[] = 'Keep the payload compact. For large teams, omit member prompts and use descriptive role names.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function buildMalformedToolRetryInstruction(array $failures, int $retryCount): string
    {
        $lines = [
            'Retry with corrected tool input only. Do not repeat the same malformed call.',
            'If you are unsure, inspect the repo or directory first instead of sending an empty or placeholder tool input.',
        ];

        if ($retryCount >= 2) {
            $lines[] = 'You have already repeated this invalid tool pattern. Stop and correct it now.';
        }

        $hasJsonParseFailure = false;
        foreach ($failures as $failure) {
            if ($this->isToolInputJsonFailure($failure['error'])) {
                $hasJsonParseFailure = true;
                break;
            }
        }
        if ($hasJsonParseFailure) {
            $lines[] = 'If a large multiline payload keeps breaking tool JSON, stop resending the same blob. Split it into smaller commands or smaller file writes.';
            $lines[] = 'Do not narrate a new strategy and then resend another large blob. The next turn must begin with a small concrete tool call.';
            $lines[] = 'Do not use Agent or Skill as a fallback for ordinary file creation or editing. Stay in this thread and use the local tools directly.';
        }

        $toolNames = array_values(array_unique(array_map(
            fn (array $failure): string => $failure['name'],
            $failures,
        )));

        foreach ($toolNames as $toolName) {
            if ($toolName === 'Write') {
                $lines[] = 'For Write: send a valid JSON object with both absolute file_path and full content strings.';
                $lines[] = 'Prefer a tiny initial Write followed by Edit chunks for long files.';
            } elseif ($toolName === 'TodoWrite') {
                $lines[] = 'For TodoWrite: send {"todos":[...]} with real tasks, or omit TodoWrite if there is nothing useful to track.';
            } elseif ($toolName === 'Bash') {
                $lines[] = 'For Bash: send a real shell command in command. Never send ":" placeholders or no-op probes like ": > /dev/null 2>&1" or "true"; use "pwd" or "ls" if you need to inspect the directory first.';
                $lines[] = 'Keep Bash commands short and concrete; avoid giant multiline file-generation commands.';
            } elseif ($toolName === 'TeamCreate') {
                $lines[] = 'For TeamCreate: send one compact JSON object with name, task, and members; each member must contain role.';
                $lines[] = 'For large teams, omit member prompts and use descriptive role names. Do not include literal newlines, control characters, or omit members.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Track retries by failure category so a corrected, parseable call with a
     * schema error does not consume the remaining JSON-repair budget.
     *
     * @param array<int, array{id: string, name: string, error: string}> $failures
     */
    private function malformedFailureSignature(array $failures): string
    {
        $parts = array_map(function (array $failure): string {
            $category = $this->isToolInputJsonFailure($failure['error'])
                ? 'json'
                : (str_contains($failure['error'], 'validation failed') ? 'schema' : 'other');

            return $failure['name'].':'.$category;
        }, $failures);
        sort($parts);

        return implode('|', $parts);
    }

    private function isToolInputJsonFailure(string $error): bool
    {
        return str_contains($error, 'Tool input JSON could not be parsed')
            || str_contains($error, 'Tool input JSON was incomplete');
    }

    private function summarizeMalformedToolInput(string $rawInput): ?string
    {
        $rawInput = trim($rawInput);
        if ($rawInput === '') {
            return null;
        }

        $snippet = preg_replace('/\s+/', ' ', $rawInput);
        if ($snippet === null || $snippet === '') {
            return null;
        }

        if (mb_strlen($snippet) > 120) {
            $snippet = mb_substr($snippet, 0, 120).'...';
        }

        return $snippet;
    }

    private function shouldRetryIncompleteAssistantResponse(
        StreamProcessor $processor,
        array $assistantMessage,
        ?string $stopReason,
        int $retryCount,
    ): bool {
        if ($retryCount >= $this->maxIncompleteResponseRetries) {
            return false;
        }

        if ($stopReason === 'max_tokens') {
            return true;
        }

        if ($this->isNarrationOnlyAssistantMessage($assistantMessage)) {
            return true;
        }

        if (! $this->assistantMessageHasVisibleContent($assistantMessage)) {
            return true;
        }

        if ($processor->hasFinalMessageEvent()) {
            return false;
        }

        return $this->assistantMessageHasVisibleContent($assistantMessage);
    }

    private function assistantMessageHasVisibleContent(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                return true;
            }

            if ($type === 'thinking' && trim((string) ($block['thinking'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function recordIncompleteAssistantResponse(array $assistantMessage, bool $skipHistory = false): void
    {
        if ($skipHistory || ! $this->assistantMessageHasVisibleContent($assistantMessage)) {
            return;
        }

        $this->messageHistory->addAssistantMessage($assistantMessage);
        $this->persistAssistantTurn($assistantMessage, []);
    }

    private function assertDurableConversationUsable(): void
    {
        if ($this->durablePersistenceFailed) {
            throw new \RuntimeException(
                'This durable conversation cannot continue because a previous transcript write failed. '
                .'Create or resume a fresh conversation from the last persisted state.',
            );
        }
    }

    private function persistAssistantTurn(array $assistantMessage, array $toolResults): void
    {
        try {
            $this->sessionManager->recordTurn($assistantMessage, $toolResults);
        } catch (\Throwable $e) {
            $this->durablePersistenceFailed = true;

            throw new \RuntimeException(
                'Model or tool execution may have completed, but the durable transcript could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $e,
            );
        }
    }

    private function persistSessionTitle(string $title): void
    {
        try {
            $this->sessionManager->setTitle($title);
        } catch (\Throwable $e) {
            $this->durablePersistenceFailed = true;

            throw new \RuntimeException(
                'Model or tool execution completed, but the durable session title could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $e,
            );
        }
    }

    private function buildIncompleteResponseRetryInstruction(
        ?string $stopReason,
        int $retryCount,
        bool $skipHistory = false,
    ): string {
        $lines = [];

        if ($stopReason === 'max_tokens') {
            $lines[] = 'Your previous response hit the output limit before you finished.';
        } else {
            $lines[] = 'Your previous response ended without a usable final answer.';
        }

        $lines[] = 'Continue exactly from where you left off.';
        $lines[] = 'Do not restart from scratch.';
        $lines[] = 'If the task requires file changes, commands, or verification, keep using tools until the requested work is actually complete.';

        if ($skipHistory) {
            $lines[] = 'Do not narrate progress or announce the next step. Take the next concrete action immediately.';
        }

        if ($retryCount >= 2) {
            $lines[] = 'You have already been cut off once. Finish the task now instead of narrating the next step.';
        }

        return implode("\n", $lines);
    }

    private function shouldSkipIncompleteAssistantHistory(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content) || count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 160) {
            return false;
        }

        if ($this->isLowValueNarrationText($text)) {
            return true;
        }

        if (preg_match('/[：:]$/u', $text) === 1) {
            return true;
        }

        return preg_match(
            '/^(现在|接下来|继续|然后|下一步|接着|Now\\b|Next\\b|I(?:\'ll| will)\\b|Let\'s\\b)/iu',
            $text,
        ) === 1;
    }

    private function isNarrationOnlyAssistantMessage(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content) || count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 220) {
            return false;
        }

        return $this->isLowValueNarrationText($text);
    }

    private function isLowValueNarrationText(string $text): bool
    {
        return preg_match(
            '/^(现在|接下来|继续|然后|下一步|接着|让我|我(?:先|会|将|正在|要|需要|尝试|打算|使用)|I(?:\'ll| will| need to| am going to)|Let me|Using\\b|Now\\b|Next\\b)/iu',
            trim($text),
        ) === 1;
    }
}
