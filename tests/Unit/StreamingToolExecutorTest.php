<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\StreamingToolExecutor;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class StreamingToolExecutorTest extends TestCase
{
    use StreamingToolExecutorTestMakeRegistryConcern;
    use StreamingToolExecutorTestSdkToolDefaultsConcern;
    use StreamingToolExecutorTestTestItDoesNotScheduleTheSameBlockTwiceConcern;
    use StreamingToolExecutorTestTestCancellationInterruptsWaitForForkedToolAndReturnsAbortedResultConcern;

    // ─── helpers ──────────────────────────────────────────────────────────

    // ─── no context set — ignores blocks ──────────────────────────────────

    // ─── unsafe tools queued for sequential execution ─────────────────────

    // ─── results sorted by block index ────────────────────────────────────

    // ─── hasEarlyExecutions / earlyExecutionCount ─────────────────────────

    // ─── cleanup resets state ─────────────────────────────────────────────

    // ─── on_complete callback passed through for queued blocks ───────────

    // ─── existing test ─────────────────────────────────────────────────────
}
