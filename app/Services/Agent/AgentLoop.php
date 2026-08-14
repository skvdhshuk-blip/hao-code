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
    use AgentLoopConstructConcern;
    use AgentLoopRunInternalConcern;
    use AgentLoopBuildRunSnapshotConcern;
    use AgentLoopMarkSessionResumedConcern;

    private const MAX_IDENTICAL_TOOL_ERROR_BATCHES = 3;

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

    private ?AgentResponseRetryPolicy $responseRetryPolicy = null;

    /** Parent model captured for the current user turn, including HITL snapshots. */
    private ?string $runBaseModel = null;
}
