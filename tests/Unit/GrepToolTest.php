<?php

namespace Tests\Unit;

use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class GrepToolTest extends TestCase
{
    use GrepToolTestSetUpConcern;
    use GrepToolTestTestPhpFallbackDoesNotReadSymlinkedFilesBelowSearchRootConcern;

    private GrepTool $tool;
    private ToolUseContext $context;
    private string $tmpDir;

    // ─── Force PHP fallback (bypass ripgrep) ─────────────────────────────

    // ─── Basic matching ───────────────────────────────────────────────────

    // ─── files_with_matches mode ──────────────────────────────────────────

    // ─── count mode ───────────────────────────────────────────────────────

    // ─── case insensitive ─────────────────────────────────────────────────

    // ─── glob filtering ───────────────────────────────────────────────────

    // ─── head_limit ───────────────────────────────────────────────────────

    // ─── single file search ───────────────────────────────────────────────

    // ─── context lines (the bug that was fixed) ───────────────────────────

    // ─── patterns containing forward slash ───────────────────────────────

    // ─── isReadOnly ───────────────────────────────────────────────────────
}
