<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Tools\ToolUseContext;

trait PermissionCheckerTestPlanFileExceptionConcern
{
    public function test_plan_mode_allows_writing_the_plan_file(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $context = $this->planContext();

        $decision = $checker->check(
            $this->makeTool('Write'),
            ['file_path' => $context->planFilePath],
            $context,
        );

        $this->assertTrue($decision->allowed);
    }

    public function test_plan_mode_allows_a_relative_path_to_the_plan_file(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $context = $this->planContext();

        $decision = $checker->check(
            $this->makeTool('Edit'),
            ['file_path' => './plans/../plans/plan-session.md'],
            $context,
        );

        $this->assertTrue($decision->allowed);
    }

    public function test_plan_mode_still_denies_writing_any_other_file(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $context = $this->planContext();

        $decision = $checker->check(
            $this->makeTool('Write'),
            ['file_path' => $context->workingDirectory.'/src/App.php'],
            $context,
        );

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('plan mode', $decision->reason ?? '');
    }

    public function test_plan_mode_denies_a_stateful_tool_even_on_the_plan_path(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $context = $this->planContext();

        $decision = $checker->check(
            $this->makeTool('Bash'),
            ['command' => 'echo hi > '.$context->planFilePath, 'file_path' => $context->planFilePath],
            $context,
        );

        $this->assertFalse($decision->allowed);
    }

    public function test_the_exception_does_nothing_without_a_configured_plan_file(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );

        $decision = $checker->check(
            $this->makeTool('Write'),
            ['file_path' => sys_get_temp_dir().'/plans/plan-session.md'],
            $context,
        );

        $this->assertFalse($decision->allowed);
    }

    private function planContext(): ToolUseContext
    {
        $workingDirectory = sys_get_temp_dir();

        return new ToolUseContext(
            workingDirectory: $workingDirectory,
            sessionId: 'test',
            planFilePath: $workingDirectory.'/plans/plan-session.md',
        );
    }
}
