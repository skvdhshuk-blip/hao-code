<?php

namespace Tests\Unit;

use HaoCode\Services\Hooks\HookDefinition;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookProcessRunner;
use HaoCode\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

class HookExecutorTest extends TestCase
{
    use HookExecutorTestMakeExecutorConcern;
    use HookExecutorTestTestHookJsonOutputWithModifiedInputConcern;


    // ─── execute() — no hooks ─────────────────────────────────────────────

    // ─── execute() — hook succeeds ────────────────────────────────────────

    // ─── execute() — hook fails (non-zero exit) ───────────────────────────

    // ─── execute() — hook outputs "deny" keyword ─────────────────────────

    // ─── execute() — hook outputs JSON ────────────────────────────────────

    // ─── execute() — multiple hooks ───────────────────────────────────────

    // ─── execute() — context passed as env vars ───────────────────────────

    // ─── HookResult value object ──────────────────────────────────────────

    // ─── matcher filtering ────────────────────────────────────────────────

    // ─── non-string context values are skipped for env ────────────────────

    // ─── context isolation fix ────────────────────────────────────────────

    // ─── HookDefinition value object ─────────────────────────────────────
}
