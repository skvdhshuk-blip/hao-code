<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Agent\ToolCall;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class AgentLoopTest extends TestCase
{
    use AgentLoopTestMakeToolConcern;
    use AgentLoopTestTestRestoreRunSnapshotRestoresCostAndUsageTotalsConcern;
    use AgentLoopTestTestRepeatedIdenticalToolErrorsTriggerOneNoToolFinalizationConcern;
    use AgentLoopTestTestItReportsToolInputJsonParseErrorsDuringRetryConcern;
    use AgentLoopTestTestItRetriesTheTurnWhenTheModelReturnsPlaceholderFileReferencesConcern;
    use AgentLoopTestTestSkillScopeFiltersNextTurnToolsAppliesModelAndRestoresItAfterRunConcern;
    use AgentLoopTestTestInvalidProviderUsageCannotReduceTotalsOrCostConcern;

    // ─── helpers ──────────────────────────────────────────────────────────

    // ─── simple end_turn returns text ─────────────────────────────────────

    // ─── abort ────────────────────────────────────────────────────────────

    // ─── isAborted starts false ────────────────────────────────────────────

    // ─── token tracking ───────────────────────────────────────────────────

    // ─── onTurnStart callback ─────────────────────────────────────────────

    // ─── cost limit stop ──────────────────────────────────────────────────

    // ─── max turns exceeded ───────────────────────────────────────────────

    // ─── auto-compact uses last-turn tokens, not cumulative ───────────────

    // ─── existing tests below ─────────────────────────────────────────────

    // ─── no consecutive user messages after a tool-use turn ───────────────

    // ─── token getters initial values ─────────────────────────────────────

    // ─── getMessageHistory ────────────────────────────────────────────────

    // ─── getEstimatedCost ─────────────────────────────────────────────────

    // ─── getCacheTokens ───────────────────────────────────────────────────

    // ─── onTurnStart increments turn number ───────────────────────────────
}
