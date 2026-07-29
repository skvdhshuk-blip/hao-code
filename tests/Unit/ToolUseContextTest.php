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

    public function test_read_receipt_can_be_marked_incomplete_and_forgotten(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-context-');
        file_put_contents($file, 'content');
        $context = new ToolUseContext(dirname($file), 'receipt');

        try {
            $context->recordFileRead($file, 'content');
            $this->assertTrue($context->wasFileRead($file));

            $context->markFileReadIncomplete(basename($file));
            $this->assertFalse($context->wasFileRead($file));
            $this->assertFalse($context->getFileRevision($file)?->complete);

            $context->forgetFileRead($file);
            $this->assertNull($context->getFileRevision($file));
            $this->assertNull($context->getFileState($file));
        } finally {
            @unlink($file);
        }
    }

    public function test_virtual_read_receipt_can_be_revoked_without_host_metadata(): void
    {
        $context = new ToolUseContext('/workspace', 'virtual-receipt');
        $path = '/workspace/remote.txt';

        $context->recordVirtualFileRead($path, 'remote content');

        $revision = $context->getFileRevision($path);
        $this->assertNotNull($revision);
        $this->assertFalse($revision->local);
        $this->assertTrue($revision->complete);
        $this->assertSame(hash('sha256', 'remote content'), $revision->sha256);
        $this->assertSame('remote content', $context->getFileState($path)?->content);

        $context->markFileReadIncomplete('remote.txt');

        $this->assertFalse($context->wasFileRead($path));
        $this->assertFalse($context->getFileRevision($path)?->complete);

        $context->forgetFileRead('remote.txt');

        $this->assertNull($context->getFileRevision($path));
        $this->assertNull($context->getFileState($path));
    }

    public function test_virtual_read_receipt_identity_does_not_follow_host_symlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $base = sys_get_temp_dir().'/haocode-virtual-context-'.bin2hex(random_bytes(5));
        $outside = $base.'-outside';
        mkdir($base, 0700);
        mkdir($outside, 0700);
        symlink($outside, $base.'/remote');
        $remoteCwd = $base.'/remote';
        $context = new ToolUseContext($remoteCwd, 'virtual-symlink');

        try {
            $context->recordVirtualFileRead('file.txt', 'guest bytes');

            $revision = $context->getFileRevision($remoteCwd.'/file.txt');
            $this->assertNotNull($revision);
            $this->assertFalse($revision->local);
            $this->assertSame($remoteCwd.'/file.txt', $revision->canonicalPath);
            $this->assertNotSame($outside.'/file.txt', $revision->canonicalPath);
        } finally {
            @unlink($base.'/remote');
            @rmdir($base);
            @rmdir($outside);
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
