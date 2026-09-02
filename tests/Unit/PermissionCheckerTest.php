<?php

namespace Tests\Unit;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class PermissionCheckerTest extends TestCase
{
    use PermissionCheckerTestSetUpConcern;
    use PermissionCheckerTestTestNonInteractiveModeDowngradesAskToDenyConcern;
    use PermissionCheckerTestPlanFileExceptionConcern;

    private ToolUseContext $context;

    // ─── BypassPermissions mode ───────────────────────────────────────────

    // ─── Plan mode ────────────────────────────────────────────────────────

    // ─── AcceptEdits mode ─────────────────────────────────────────────────

    // ─── Read-only auto-approve ───────────────────────────────────────────

    // ─── Allow / deny rules ───────────────────────────────────────────────

    // ─── :* prefix matching bug fix ───────────────────────────────────────

    // ─── Bash dangerous pattern detection ────────────────────────────────

    // ─── default branch: non-Bash/Read/Edit/Write/Glob/Grep tools ────────
}
