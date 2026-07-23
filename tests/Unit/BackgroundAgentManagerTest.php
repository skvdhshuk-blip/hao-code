<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use PHPUnit\Framework\TestCase;

class BackgroundAgentManagerTest extends TestCase
{
    private string $tempDir;
    private BackgroundAgentManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/haocode_background_agents_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new BackgroundAgentManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_create_and_get_round_trip(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore', 'Repo explorer', 1234);

        $agent = $this->manager->get('agent_demo');

        $this->assertNotNull($agent);
        $this->assertSame('Inspect repo', $agent['prompt']);
        $this->assertSame('Explore', $agent['agent_type']);
        $this->assertSame(1234, $agent['pid']);
        $this->assertSame('pending', $agent['status']);
    }

    public function test_create_persists_background_worktree_identity(): void
    {
        $this->manager->create(
            'agent_demo',
            'Inspect repo',
            'Explore',
            worktreePath: '/tmp/project/.claude/worktrees/agent-a1b2c3d4',
            worktreeBranch: 'agent-a1b2c3d4',
        );

        $agent = $this->manager->get('agent_demo');
        $this->assertSame(
            '/tmp/project/.claude/worktrees/agent-a1b2c3d4',
            $agent['worktree_path'],
        );
        $this->assertSame('agent-a1b2c3d4', $agent['worktree_branch']);
        $this->assertTrue($agent['worktree_retained']);
    }

    public function test_rejects_path_traversal_ids_before_writing_files(): void
    {
        $outsidePath = dirname($this->tempDir).'/escape.state.json';
        @unlink($outsidePath);

        try {
            $this->manager->create('../escape', 'Inspect repo', 'Explore');
            $this->fail('Expected an invalid ID exception.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid background agent ID.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($outsidePath);
    }

    public function test_rejects_invalid_ids_on_read_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid background agent ID.');

        $this->manager->get('../../escape');
    }

    public function test_create_does_not_overwrite_an_existing_agent(): void
    {
        $this->manager->create('agent_demo', 'First prompt', 'Explore');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Background agent 'agent_demo' already exists.");

        $this->manager->create('agent_demo', 'Second prompt', 'Explore');
    }

    public function test_queue_and_pop_message_updates_pending_count(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');

        $queued = $this->manager->queueMessage('agent_demo', 'Check migrations', 'follow-up', 'session-1');

        $this->assertNotNull($queued);
        $this->assertSame(1, $queued['pending_messages']);
        $this->assertSame(1, $this->manager->get('agent_demo')['pending_messages']);

        $message = $this->manager->popNextMessage('agent_demo');

        $this->assertSame('Check migrations', $message['message']);
        $this->assertSame('follow-up', $message['summary']);
        $this->assertSame('session-1', $message['from']);
        $this->assertSame(0, $this->manager->get('agent_demo')['pending_messages']);
    }

    public function test_request_stop_sets_flag(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');

        $this->manager->requestStop('agent_demo');

        $this->assertTrue($this->manager->isStopRequested('agent_demo'));
    }

    public function test_mark_completed_persists_last_result(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');

        $this->manager->markCompleted('agent_demo', 'Done');

        $agent = $this->manager->get('agent_demo');
        $this->assertSame('completed', $agent['status']);
        $this->assertSame('Done', $agent['last_result']);
    }

    public function test_record_result_marks_agent_idle(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markRunning('agent_demo');

        $this->manager->recordResult('agent_demo', 'Waiting for follow-up');

        $this->assertSame('idle', $this->manager->get('agent_demo')['status']);
    }

    public function test_refresh_status_persists_dead_process(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore', pid: 99999999);
        $this->manager->markRunning('agent_demo');

        $agent = $this->manager->refreshStatus('agent_demo');

        $this->assertSame('dead', $agent['status']);
        $this->assertSame('dead', $this->manager->get('agent_demo')['status']);
    }

    public function test_attach_process_does_not_overwrite_early_error(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markError('agent_demo', 'Failed before parent attached PID');

        $agent = $this->manager->attachProcess('agent_demo', 1234);

        $this->assertSame('error', $agent['status']);
        $this->assertNull($agent['pid']);
    }

    public function test_waiting_for_input_persists_child_session_and_interrupt(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $interrupt = new HumanInterrupt(
            'int-child',
            'session-child',
            [new HumanActionRequest('call-1', 'Bash', [], 'Review')],
            date('c'),
            'agent_demo',
        );

        $this->manager->markWaitingForInput('agent_demo', $interrupt);
        $agent = $this->manager->refreshStatus('agent_demo');

        $this->assertSame('waiting_for_input', $agent['status']);
        $this->assertSame('session-child', $agent['child_session_id']);
        $this->assertSame('int-child', $agent['pending_interrupt']['id']);
        $this->assertNull($agent['pid']);
    }

    public function test_terminal_transitions_clear_stale_interrupt_metadata(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $interrupt = new HumanInterrupt(
            'int-child',
            'session-child',
            [new HumanActionRequest('call-1', 'Bash', [], 'Review')],
            date('c'),
            'agent_demo',
        );

        $this->manager->markWaitingForInput('agent_demo', $interrupt);
        $this->manager->markCompleted('agent_demo', 'Done');

        $agent = $this->manager->get('agent_demo');
        $this->assertSame('completed', $agent['status']);
        $this->assertArrayNotHasKey('pending_interrupt', $agent);
        $this->assertArrayNotHasKey('child_session_id', $agent);
    }

    public function test_queue_message_rejects_waiting_agent_at_storage_boundary(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markWaitingForInput('agent_demo', new HumanInterrupt(
            'int-child',
            'session-child',
            [new HumanActionRequest('call-1', 'Bash', [], 'Review')],
            date('c'),
            'agent_demo',
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resume the interrupt first');

        $this->manager->queueMessage('agent_demo', 'This must not be queued.');
    }

    public function test_reaper_marks_unfinished_child_and_task_terminal(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required.');
        }

        $tasks = new TaskManager($this->tempDir.'/tasks');
        $manager = new BackgroundAgentManager($this->tempDir.'/agents', $tasks);
        $manager->create('agent_demo', 'Inspect repo', 'Explore');
        $tasks->createWithId('agent_demo', 'Agent', 'Running');

        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(0);
        }
        $this->assertGreaterThan(0, $pid);
        $manager->attachProcess('agent_demo', $pid);

        $deadline = microtime(true) + 2;
        do {
            usleep(20_000);
            $agent = $manager->refreshStatus('agent_demo');
        } while (($agent['status'] ?? null) !== 'dead' && microtime(true) < $deadline);

        $this->assertSame('dead', $agent['status']);
        $this->assertSame('completed', $tasks->get('agent_demo')->status);
    }

    public function test_reaper_tracks_a_child_that_reaches_terminal_state_before_exiting(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required.');
        }

        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(100_000);
            exit(0);
        }
        $this->assertGreaterThan(0, $pid);

        $this->manager->markCompleted('agent_demo', 'Done');
        $this->manager->attachProcess('agent_demo', $pid);
        usleep(150_000);
        $this->manager->get('agent_demo');

        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
        $this->assertSame('completed', $this->manager->get('agent_demo')['status']);
    }

    public function test_sigchld_reaper_collects_owned_child_without_a_follow_up_manager_call(): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required.');
        }

        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(100_000);
            exit(0);
        }
        $this->assertGreaterThan(0, $pid);
        $this->manager->attachProcess('agent_demo', $pid);

        try {
            $deadline = microtime(true) + 2;
            while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(20_000);
            }

            $this->assertFalse(@posix_kill($pid, 0));
            $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
            $this->assertSame('dead', $this->manager->get('agent_demo')['status']);
        } finally {
            @posix_kill($pid, 15);
            @pcntl_waitpid($pid, $status, WNOHANG);
        }
    }

    public function test_refuses_to_signal_a_pid_not_owned_by_this_manager(): void
    {
        $pid = getmypid();
        $this->assertNotFalse($pid);
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore', pid: $pid);
        $this->manager->markRunning('agent_demo');

        $this->assertFalse($this->manager->terminateProcess('agent_demo'));
    }

    public function test_terminate_process_waits_for_and_reaps_an_owned_child(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required.');
        }

        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $pid = pcntl_fork();
        if ($pid === 0) {
            while (true) {
                usleep(100_000);
            }
        }
        $this->assertGreaterThan(0, $pid);
        $this->manager->attachProcess('agent_demo', $pid);

        $this->assertTrue($this->manager->terminateProcess('agent_demo'));
        $this->assertSame('dead', $this->manager->get('agent_demo')['status']);
        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
