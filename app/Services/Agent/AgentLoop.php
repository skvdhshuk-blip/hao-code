<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use HaoCode\Tools\ToolUseContext;

class AgentLoop
{
    use AgentLoopConstructConcern;
    use AgentLoopRunInternalConcern;
    use AgentLoopBuildRunSnapshotConcern;
    private int $maxTurns = 50;
    private int $maxMalformedToolInputRetries = 4;
    private int $maxTotalMalformedToolInputRetries = 10;
    private int $maxIncompleteResponseRetries = 2;
    private bool $aborted = false;
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

    private ?AgentResponseRetryPolicy $responseRetryPolicy = null;

    private readonly RunStateLifecycle $runStateLifecycle;

    private readonly AgentSnapshotCoordinator $snapshotCoordinator;

    private readonly AgentTranscriptLifecycle $transcriptLifecycle;

    private readonly RepeatedToolFailureDetector $repeatedToolFailureDetector;

    private readonly AgentFinalResponseCoordinator $finalResponseCoordinator;

    /** Model-facing text owed at the next turn boundary (background notices, reminders, plan hand-off). */
    private readonly TurnInjectionQueue $turnInjections;

    /** Optional end-of-run goal check; null when the run has no configured goal. */
    private ?GoalVerificationPolicy $goalVerifier = null;

    /** Parent model captured for the current user turn, including HITL snapshots. */
    private ?string $runBaseModel = null;

    private function completeRunOutcome(AgentRunOutcome $outcome): void
    {
        $this->runStateLifecycle->complete($outcome, $this->lastRunTurns, [
            'input_tokens' => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
            'cache_creation_tokens' => $this->totalCacheCreationTokens,
            'cache_read_tokens' => $this->totalCacheReadTokens,
        ]);
    }

    private function failRunOutcome(\Throwable $error): void
    {
        $snapshot = $error instanceof HumanInterruptException
            ? $this->buildRunSnapshot($this->lastRunTurns)
            : [];
        $this->runStateLifecycle->fail($error, $this->lastRunTurns, $snapshot);
    }

    /** @internal */
    public function markSessionResumed(): void
    {
        $this->sessionStarted = true;
        $this->transcriptLifecycle->bindToolResultStorage();
    }
}
