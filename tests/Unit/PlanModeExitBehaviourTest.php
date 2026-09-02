<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\TurnInjectionQueue;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\PlanMode\ExitPlanModeTool;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class PlanModeExitBehaviourTest extends TestCase
{
    private string $tempDir;

    private string $planFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/haocode_plan_exit_'.uniqid();
        mkdir($this->tempDir.'/plans', 0755, true);
        $this->planFile = $this->tempDir.'/plans/session.md';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/plans/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir.'/plans');
        @rmdir($this->tempDir);
    }

    private function context(
        string $planExitMode,
        string $permissionMode = 'plan',
        ?TurnInjectionQueue $queue = null,
        bool $readOnly = false,
    ): ToolUseContext {
        $settings = new SettingsManager($this->tempDir);
        $settings->set('permission_mode', $permissionMode);

        $runContext = new AgentRunContext(
            workingDirectory: $this->tempDir,
            projectDirectory: $this->tempDir,
            settings: $settings,
            skillLoader: new SkillLoader($this->tempDir, []),
            cancellationToken: new CancellationToken,
            readOnly: $readOnly,
            planExitMode: $planExitMode,
        );

        return new ToolUseContext(
            workingDirectory: $this->tempDir,
            sessionId: 'session',
            runContext: $runContext,
            turnInjections: $queue ?? new TurnInjectionQueue,
            planFilePath: $this->planFile,
        );
    }

    public function test_return_mode_ends_the_run_and_leaves_the_mode_alone(): void
    {
        file_put_contents($this->planFile, '1. Do the thing');
        $queue = new TurnInjectionQueue;
        $context = $this->context('return', queue: $queue);

        $result = (new ExitPlanModeTool)->call([], $context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('plan_ready', $result->output);
        $this->assertSame(
            ['reason' => 'plan_ready', 'text' => '1. Do the thing'],
            $queue->takeTermination(),
        );
        $this->assertSame('plan', $context->runContext->settings->getPermissionMode()->value);
    }

    public function test_auto_mode_switches_the_mode_and_injects_the_plan(): void
    {
        file_put_contents($this->planFile, '1. Do the thing');
        $queue = new TurnInjectionQueue;
        $context = $this->context('auto', queue: $queue);

        $result = (new ExitPlanModeTool)->call([], $context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertSame('default', $context->runContext->settings->getPermissionMode()->value);
        $this->assertNull($queue->takeTermination());

        $injected = $queue->drain(1, 'session');
        $this->assertStringContainsString('# Approved plan', $injected);
        $this->assertStringContainsString('1. Do the thing', $injected);
    }

    public function test_the_plan_argument_is_saved_to_the_plan_file(): void
    {
        $context = $this->context('return');

        (new ExitPlanModeTool)->call(['plan' => 'inline plan text'], $context);

        $this->assertStringContainsString('inline plan text', (string) file_get_contents($this->planFile));
    }

    public function test_it_refuses_outside_plan_mode(): void
    {
        file_put_contents($this->planFile, 'a plan');
        $context = $this->context('auto', permissionMode: 'default');

        $result = (new ExitPlanModeTool)->call([], $context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('only available in plan mode', $result->output);
    }

    public function test_it_refuses_for_a_read_only_agent(): void
    {
        file_put_contents($this->planFile, 'a plan');
        $context = $this->context('auto', readOnly: true);

        $result = (new ExitPlanModeTool)->call([], $context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('read-only', $result->output);
    }

    public function test_an_empty_plan_file_is_reported_with_its_path(): void
    {
        $context = $this->context('return');

        $result = (new ExitPlanModeTool)->call([], $context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString($this->planFile, $result->output);
    }
}
