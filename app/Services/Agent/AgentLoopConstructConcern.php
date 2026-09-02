<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\Telemetry\RunTraceContext;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

trait AgentLoopConstructConcern
{

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
        private int $maxEstimatedInputTokens = ContextBudget::MAX_ESTIMATED_INPUT_TOKENS,
        private readonly ?AgentRunContext $runContext = null,
        private readonly ?\HaoCode\Services\Api\LlmProvider $provider = null,
        private readonly ?RunJournal $runJournal = null,
    ) {
        $this->cancellationToken = $cancellationToken ?? new CancellationToken();
        $this->responseRetryPolicy = new AgentResponseRetryPolicy($toolRegistry);
        $this->runStateLifecycle = new RunStateLifecycle($runJournal);
        $this->snapshotCoordinator = new AgentSnapshotCoordinator;
        $this->transcriptLifecycle = new AgentTranscriptLifecycle($sessionManager, $toolOrchestrator);
        $this->repeatedToolFailureDetector = new RepeatedToolFailureDetector;
        $this->finalResponseCoordinator = new AgentFinalResponseCoordinator;
        $this->turnInjections = new TurnInjectionQueue;
        $this->interruptSettlement = new SmartInterruptSettlement(
            $sessionManager,
            $toolOrchestrator,
            $this->runStateLifecycle,
            $runContext,
        );
    }

    /**
     * Queue for model-facing text delivered at the next turn boundary.
     *
     * @internal
     */
    public function turnInjections(): TurnInjectionQueue
    {
        return $this->turnInjections;
    }

    /**
     * Configure goal-directed behaviour for this run.
     *
     * Both parts are opt-in. Without a reminder config nothing is injected; without
     * a goal the run finishes as soon as the model stops calling tools.
     *
     * @param  array{recapEvery?: int, fullEvery?: int}|null  $reminder
     *
     * @internal
     */
    public function configureGoal(?string $goal, ?array $reminder = null, int $verificationRounds = 1): void
    {
        $goal = $goal === null ? null : (trim($goal) === '' ? null : trim($goal));

        if ($reminder !== null) {
            $this->turnInjections->addProducer(new GoalReminderPolicy(
                $goal,
                (int) ($reminder['recapEvery'] ?? 5),
                (int) ($reminder['fullEvery'] ?? 10),
                fn (): ?string => $this->lastUserPrompt,
            ));
        }

        $this->goalVerifier = $goal !== null && $verificationRounds > 0
            ? new GoalVerificationPolicy($goal, $verificationRounds)
            : null;
    }

    private function responseRetryPolicy(): AgentResponseRetryPolicy
    {
        return $this->responseRetryPolicy ??= new AgentResponseRetryPolicy(
            isset($this->toolRegistry) ? $this->toolRegistry : null,
        );
    }

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->toolOrchestrator->setPermissionPromptHandler($handler);
    }

    public function setMaxTurns(int $maxTurns): void
    {
        if ($maxTurns < 1) {
            throw new \InvalidArgumentException('maxTurns must be >= 1.');
        }

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

    /** @internal */
    public function getCurrentWorkingDirectory(): ?string
    {
        return $this->workingDirectory ?? $this->runContext?->workingDirectory;
    }

    /**
     * Keep the loop/session cwd in sync with a worktree transition made by a
     * tool without discarding the live ToolUseContext or its read receipts.
     *
     * @internal
     */
    private function synchronizeToolWorkingDirectory(string $directory): void
    {
        $this->workingDirectory = $directory;
        $this->sessionManager->setCurrentWorkingDirectory($directory);
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->cancellationToken->cancel();
    }

    public function isAborted(): bool
    {
        return $this->isCancellationRequested();
    }

    private function isCancellationRequested(): bool
    {
        return $this->aborted || $this->cancellationToken->isCancelled();
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
        return $this->runOutcome(
            $userInput,
            $onTextDelta,
            $onToolStart,
            $onToolComplete,
            $onTurnStart,
            $onThinkingDelta,
        )->text;
    }

    /** @internal */
    public function runOutcome(
        string|array $userInput,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ): AgentRunOutcome {
        $this->transcriptLifecycle->assertUsable();
        $inputEvent = $this->runStateLifecycle->begin($userInput);
        $originalModel = $this->runContext?->settings->getModel();
        $this->runBaseModel = $originalModel;
        $this->toolOrchestrator->resetSkillScope();
        $agentSpan = $this->tracer?->startSpan(
            name: 'agent.run',
            openInferenceKind: PhoenixTracer::KIND_AGENT,
            attributes: array_merge([
                'input.value' => is_string($userInput) ? $userInput : json_encode($userInput, JSON_UNESCAPED_UNICODE),
                'input.mime_type' => is_string($userInput) ? 'text/plain' : 'application/json',
                'session.id' => $this->sessionManager->getSessionId(),
            ], RunTraceContext::attributes($this->runJournal, $inputEvent?->eventId)),
        );
        $agentScope = $agentSpan?->activate();

        try {
            $outcome = $this->runInternal($userInput, $onTextDelta, $onToolStart, $onToolComplete, $onTurnStart, $onThinkingDelta);
            // Route through PhoenixTracer::setAttribute so redact_messages
            // masks the agent's final answer; a direct setAttribute bypasses
            // the sanitizer that startSpan() applies to its initial attributes.
            $this->tracer?->setAttribute($agentSpan, 'output.value', $outcome->text);
            $this->tracer?->setAttribute($agentSpan, 'llm.token_count.prompt', $this->totalInputTokens);
            $this->tracer?->setAttribute($agentSpan, 'llm.token_count.completion', $this->totalOutputTokens);
            $this->completeRunOutcome($outcome);

            return $outcome;
        } catch (\Throwable $e) {
            $this->failRunOutcome($e);
            $this->tracer?->recordException($agentSpan, $e);
            throw $e;
        } finally {
            if ($originalModel !== null && $this->toolOrchestrator->getActiveSkillModelOverride() !== null) {
                $this->runContext?->settings->set('model', $originalModel);
            }
            $this->toolOrchestrator->resetSkillScope();
            $this->cancellationToken->close();
            $this->runBaseModel = null;
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
        return $this->resumeInterruptOutcome(
            $interruptId,
            $decisions,
            $onTextDelta,
            $onToolStart,
            $onToolComplete,
            $onTurnStart,
            $onThinkingDelta,
        )->text;
    }

    /** @internal */
    public function resumeInterruptOutcome(
        string $interruptId,
        array $decisions,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ): AgentRunOutcome {
        $this->transcriptLifecycle->assertUsable();
        $originalModel = $this->runContext?->settings->getModel();
        $this->runBaseModel = $originalModel;
        $restoredModelOverride = $this->toolOrchestrator->getActiveSkillModelOverride();
        if ($restoredModelOverride !== null) {
            // A durable interrupt can occur after an inline Skill activated but
            // before the next model request. Apply the restored override before
            // that first resumed request, just as runInternal() does between
            // ordinary tool turns.
            $this->runContext?->settings->set('model', $restoredModelOverride);
        }

        try {
            if ($this->abortRequestedChecker !== null && ($this->abortRequestedChecker)()) {
                $this->lastRunTurns = 0;
                $this->abort();

                $outcome = AgentRunOutcome::cancelled();
                $this->completeRunOutcome($outcome);

                return $outcome;
            }
            $context = $this->toolUseContext ??= new ToolUseContext(
                workingDirectory: $this->workingDirectory ?? (getcwd() ?: '/'),
                sessionId: $this->sessionManager->getSessionId(),
                shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
                runContext: $this->runContext,
                provider: $this->provider,
                toolRegistry: $this->toolRegistry,
                onWorkingDirectoryChanged: function (string $directory): void {
                    $this->synchronizeToolWorkingDirectory($directory);
                },
                turnInjections: $this->turnInjections,
                planFilePath: $this->sessionManager->getPlanFilePath(),
            );
            $context->beginReadReceiptBatch();
            $readReceiptBatchCommitted = false;
            try {
                $resolution = (new HumanInterruptCoordinator($this->sessionManager, $this->toolOrchestrator))->resolve(
                    $interruptId,
                    $decisions,
                    $context,
                    $onToolStart,
                    $onToolComplete,
                    function () use ($interruptId, $decisions): void {
                        $this->runStateLifecycle->resume($interruptId, $decisions);
                    },
                );
                $this->interruptSourceAgentId = $resolution['interrupt']->sourceAgentId;
                $this->interruptSourceTeam = $resolution['interrupt']->sourceTeam;
                $this->queueCheckpointReadReceiptsForVisibleResults($resolution['checkpoint'], $context);
                $this->messageHistory->addToolResultMessage(
                    $resolution['results'],
                    $this->turnInjections->drain(0, $this->sessionManager->getSessionId()),
                );
                $context->commitReadReceiptBatch();
                $readReceiptBatchCommitted = true;
            } finally {
                if (! $readReceiptBatchCommitted) {
                    $context->discardReadReceiptBatch();
                }
            }

            $outcome = $this->runInternal(null, $onTextDelta, $onToolStart, $onToolComplete, $onTurnStart, $onThinkingDelta);
            $this->completeRunOutcome($outcome);

            return $outcome;
        } catch (\Throwable $error) {
            $this->failRunOutcome($error);
            throw $error;
        } finally {
            if ($originalModel !== null && $this->toolOrchestrator->getActiveSkillModelOverride() !== null) {
                $this->runContext?->settings->set('model', $originalModel);
            }
            $this->toolOrchestrator->resetSkillScope();
            $this->cancellationToken->close();
            $this->runBaseModel = null;
        }
    }

}
