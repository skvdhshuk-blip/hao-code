<?php
namespace Tests\Unit;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolUseContextTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $context = new ToolUseContext(
            workingDirectory: '/tmp',
            sessionId: 'abc123',
        );

        $this->assertSame('/tmp', $context->workingDirectory);
        $this->assertSame('abc123', $context->sessionId);
    }

    public function test_is_aborted_returns_false_by_default(): void
    {
        $context = new ToolUseContext('/tmp', 'abc');
        $this->assertFalse($context->isAborted());
    }

    public function test_is_aborted_returns_true_when_should_abort_returns_true(): void
    {
        $context = new ToolUseContext('/tmp', 'abc', shouldAbort: fn() => true);
        $this->assertTrue($context->isAborted());
    }

    public function test_is_aborted_returns_false_when_should_abort_returns_false(): void
    {
        $context = new ToolUseContext('/tmp', 'abc', shouldAbort: fn() => false);
        $this->assertFalse($context->isAborted());
    }

    public function test_on_progress_callback_is_stored(): void
    {
        $called = false;
        $context = new ToolUseContext('/tmp', 'abc', onProgress: function ($value) use (&$called) {
            $called = true;
        });

        $this->assertInstanceOf(\Closure::class, $context->onProgress);
        ($context->onProgress)('test');
        $this->assertTrue($called);
    }

    public function test_read_state_is_isolated_between_contexts(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-context-');
        $first = new ToolUseContext('/tmp', 'first');
        $second = new ToolUseContext('/tmp', 'second');

        try {
            $first->recordFileRead($file);

            $this->assertTrue($first->wasFileRead($file));
            $this->assertFalse($second->wasFileRead($file));
        } finally {
            @unlink($file);
        }
    }

    public function test_run_context_can_be_propagated_to_tools(): void
    {
        $runContext = new AgentRunContext(
            '/tmp/project',
            '/tmp/project',
            new SettingsManager('/tmp/project'),
            new SkillLoader('/tmp/project'),
            new CancellationToken(),
        );

        $context = new ToolUseContext('/tmp/project', 'abc', runContext: $runContext);

        $this->assertSame($runContext, $context->runContext);
        $childContext = $runContext->fork();
        $this->assertNotSame($runContext->cancellationToken, $childContext->cancellationToken);

        $runContext->cancellationToken->cancel();
        $this->assertTrue($childContext->cancellationToken->isCancelled());
    }

    public function test_read_only_fork_enforces_plan_permissions_without_mutating_parent(): void
    {
        $settings = new SettingsManager('/tmp/project');
        $settings->set('permission_mode', 'bypass_permissions');
        $runContext = new AgentRunContext(
            '/tmp/project',
            '/tmp/project',
            $settings,
            new SkillLoader('/tmp/project'),
            new CancellationToken(),
        );

        $child = $runContext->fork(readOnly: true);

        $this->assertSame('bypass_permissions', $runContext->settings->getPermissionMode()->value);
        $this->assertSame('plan', $child->settings->getPermissionMode()->value);
    }
}
