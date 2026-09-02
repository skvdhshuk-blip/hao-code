<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\BackgroundCompletionNotifier;
use PHPUnit\Framework\TestCase;

class BackgroundCompletionNotifierTest extends TestCase
{
    private string $tempDir;

    private BackgroundAgentManager $manager;

    protected function setUp(): void
    {
        BackgroundAgentManager::resetSignalReaper();
        $this->tempDir = sys_get_temp_dir().'/haocode_notifier_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new BackgroundAgentManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        BackgroundAgentManager::resetSignalReaper();
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private function notifier(): BackgroundCompletionNotifier
    {
        return new BackgroundCompletionNotifier(
            fn (): BackgroundAgentManager => $this->manager,
            watchBash: false,
        );
    }

    public function test_it_returns_null_when_nothing_finished(): void
    {
        $this->manager->create('agent_busy', 'p', 'Explore', 'Busy', null, null, null, 'session-a');

        $this->assertNull(($this->notifier())(1, 'session-a'));
    }

    public function test_it_reports_a_completed_agent_with_its_result(): void
    {
        $this->manager->create('agent_done', 'p', 'Explore', 'Explore auth flow', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_done', 'auth lives in app/Auth');

        $notice = ($this->notifier())(1, 'session-a');

        $this->assertStringContainsString('# Background task updates', $notice);
        $this->assertStringContainsString('agent_done', $notice);
        $this->assertStringContainsString('Explore auth flow', $notice);
        $this->assertStringContainsString('auth lives in app/Auth', $notice);
    }

    public function test_it_reports_a_failed_agent_with_its_error(): void
    {
        $this->manager->create('agent_bad', 'p', 'Explore', 'Run tests', null, null, null, 'session-a');
        $this->manager->markError('agent_bad', 'composer not found');

        $notice = ($this->notifier())(1, 'session-a');

        $this->assertStringContainsString('failed', $notice);
        $this->assertStringContainsString('composer not found', $notice);
    }

    public function test_each_completion_is_reported_only_once(): void
    {
        $this->manager->create('agent_once', 'p', 'Explore', 'Once', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_once', 'done');

        $notifier = $this->notifier();
        $this->assertNotNull($notifier(1, 'session-a'));
        $this->assertNull($notifier(2, 'session-a'));
    }

    public function test_it_ignores_completions_owned_by_another_session(): void
    {
        $this->manager->create('agent_other', 'p', 'Explore', 'Other', null, null, null, 'session-b');
        $this->manager->markCompleted('agent_other', 'not yours');

        $this->assertNull(($this->notifier())(1, 'session-a'));
    }

    public function test_a_broken_manager_does_not_break_the_turn(): void
    {
        $notifier = new BackgroundCompletionNotifier(
            function (): BackgroundAgentManager {
                throw new \RuntimeException('state directory unreadable');
            },
            watchBash: false,
        );

        $this->assertNull($notifier(1, 'session-a'));
    }

    public function test_a_null_manager_is_tolerated(): void
    {
        $notifier = new BackgroundCompletionNotifier(fn (): ?BackgroundAgentManager => null, watchBash: false);

        $this->assertNull($notifier(1, 'session-a'));
    }
}
