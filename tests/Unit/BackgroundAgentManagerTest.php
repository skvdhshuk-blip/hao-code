<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
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
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
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
        $this->assertSame(1234, $agent['pid']);
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
    }
}
