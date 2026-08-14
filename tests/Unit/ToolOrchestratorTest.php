<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolOrchestratorTest extends TestCase
{
    use ToolOrchestratorTestMakeOrchestratorConcern;
    use ToolOrchestratorTestTestAbortFromStartCallbackSkipsToolAndEmitsTerminalCompletionConcern;
    use ToolOrchestratorTestTestExecuteToolsReturnsResultsForAllSafeAndUnsafeBlocksConcern;
    use ToolOrchestratorTestTestHumanReviewBatchesGatedActionsAndKeepsHookModifiedInputConcern;
    use ToolOrchestratorTestTestSkillScopeEnforcesBashCommandPatternConcern;

    // ─── helpers ──────────────────────────────────────────────────────────

    // ─── unknown tool ─────────────────────────────────────────────────────

    // ─── schema validation ────────────────────────────────────────────────

    // ─── semantic validation ──────────────────────────────────────────────

    // ─── PreToolUse hook blocking ─────────────────────────────────────────

    // ─── PreToolUse hook modifying input ──────────────────────────────────

    // ─── permission denied ────────────────────────────────────────────────

    // ─── success path ─────────────────────────────────────────────────────

    // ─── PostToolUse hook appending output ────────────────────────────────

    // ─── tool throws exception ────────────────────────────────────────────

    // ─── output truncation ────────────────────────────────────────────────

    // ─── onStart / onComplete callbacks ──────────────────────────────────

    // ─── mixed safe+unsafe parallel execution ─────────────────────────────

    // ─── deny vs ask distinction ──────────────────────────────────────────

    // ─── parallel_tool_completion (existing test) ─────────────────────────

    // ─── repeated Read hint ───────────────────────────────────────────────
}
