<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\Bash\BackgroundBashSupervisor;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class BashToolTest extends TestCase
{
    use BashToolTestSetUpConcern;
    use BashToolTestTestForegroundOutputLimitTerminatesCommandBeforeLaterSideEffectsConcern;
    use BashToolTestTestBackgroundOutputLimitIsReportedWhenProcessExitsBeforePipeDrainConcern;

    private BashTool $tool;
    private ToolUseContext $context;

    // ─── validateInput ────────────────────────────────────────────────────

    // ─── detectDangerousPatterns (via reflection) ─────────────────────────

    // ─── interpretExitCode (via reflection) ───────────────────────────────

    // ─── isReadOnlyCommand ────────────────────────────────────────────────

    // ─── isReadOnly ───────────────────────────────────────────────────────

    // ─── call: output truncation ──────────────────────────────────────────

    // ─── call: timeout enforcement ────────────────────────────────────────

    // ─── call: warns on dangerous patterns ───────────────────────────────

    // ─── env_deny hardening (chatgpt 5.5) ──────────────────────────────
    //
    // BashTool strips PolicyLoader::REQUIRED_ENV_DENY before spawning the
    // subprocess. LD_PRELOAD / DYLD_* / PYTHONPATH / NODE_OPTIONS / PERL5OPT
    // enable code injection into child processes and must never reach the
    // spawned shell regardless of policy configuration.
}
