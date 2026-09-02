<?php

namespace Tests\Unit;

use HaoCode\Tools\PlanMode\EnterPlanModeTool;
use HaoCode\Tools\PlanMode\ExitPlanModeTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class PlanModeToolsTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── EnterPlanModeTool ────────────────────────────────────────────────

    public function test_enter_name(): void
    {
        $this->assertSame('EnterPlanMode', (new EnterPlanModeTool)->name());
    }

    public function test_enter_description_mentions_plan(): void
    {
        $this->assertStringContainsString('plan', strtolower((new EnterPlanModeTool)->description()));
    }

    public function test_enter_is_read_only(): void
    {
        $this->assertTrue((new EnterPlanModeTool)->isReadOnly([]));
    }

    public function test_enter_is_concurrency_safe(): void
    {
        $this->assertTrue((new EnterPlanModeTool)->isConcurrencySafe([]));
    }

    public function test_enter_call_returns_success(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertFalse($result->isError);
    }

    public function test_enter_call_mentions_plan_mode(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('plan mode', strtolower($result->output));
    }

    public function test_enter_call_mentions_exit_plan_mode(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('ExitPlanMode', $result->output);
    }

    // ─── ExitPlanModeTool ─────────────────────────────────────────────────

    public function test_exit_name(): void
    {
        $this->assertSame('ExitPlanMode', (new ExitPlanModeTool)->name());
    }

    public function test_exit_description_mentions_plan(): void
    {
        $this->assertStringContainsString('plan', strtolower((new ExitPlanModeTool)->description()));
    }

    public function test_exit_is_read_only(): void
    {
        $this->assertTrue((new ExitPlanModeTool)->isReadOnly([]));
    }

    public function test_exit_is_not_concurrency_safe(): void
    {
        // It switches the permission mode and writes the plan file.
        $this->assertFalse((new ExitPlanModeTool)->isConcurrencySafe([]));
    }

    public function test_exit_reports_when_no_plan_exists(): void
    {
        $result = (new ExitPlanModeTool)->call([], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('No plan found', $result->output);
    }

    public function test_exit_no_longer_mentions_the_plan_off_command(): void
    {
        // The command never existed in this SDK; the tool now resolves plan mode
        // itself instead of telling the user to run something.
        $tool = new ExitPlanModeTool;

        $this->assertStringNotContainsString('/plan off', $tool->description());
        $this->assertStringNotContainsString('/plan off', $tool->call([], $this->context)->output);
    }
}
