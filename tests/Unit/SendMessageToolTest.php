<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\BackgroundAgentLimits;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Tools\Agent\SendMessageTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SendMessageToolTest extends TestCase
{
    private string $tempDir;
    private BackgroundAgentManager $manager;
    private SendMessageTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/haocode_send_message_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new BackgroundAgentManager($this->tempDir);
        $this->tool = new SendMessageTool($this->manager);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'session-main');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function test_it_queues_a_message_for_a_running_agent(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markRunning('agent_demo');

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'Check the failing tests',
            'summary' => 'new task',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Queued message', $result->output);
        $this->assertSame(1, $this->manager->get('agent_demo')['pending_messages']);
    }

    public function test_it_errors_when_agent_does_not_exist(): void
    {
        $result = $this->tool->call([
            'to' => 'agent_missing',
            'message' => 'Hello?',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_it_errors_when_agent_has_already_completed(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markCompleted('agent_demo', 'Done');

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'One more thing',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('no longer running', $result->output);
    }

    public function test_it_rejects_a_dead_process_instead_of_queuing_forever(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore', pid: 99999999);
        $this->manager->markRunning('agent_demo');

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'Are you there?',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertSame('dead', $this->manager->get('agent_demo')['status']);
        $this->assertSame(0, $this->manager->get('agent_demo')['pending_messages']);
    }

    public function test_it_rejects_messages_while_agent_waits_for_interrupt_resume(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markWaitingForInput('agent_demo', new HumanInterrupt(
            'int-child',
            'session-child',
            [new HumanActionRequest('call-1', 'Bash', [], 'Review')],
            date('c'),
            'agent_demo',
        ));

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'This would otherwise be orphaned.',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('resume the interrupt first', $result->output);
        $this->assertSame(0, $this->manager->get('agent_demo')['pending_messages']);
    }

    public function test_capacity_rejection_uses_stable_background_busy_error(): void
    {
        $manager = new BackgroundAgentManager(
            $this->tempDir,
            limits: new BackgroundAgentLimits(messageMaxBytes: 16),
        );
        $manager->create('agent_busy', 'Inspect repo', 'Explore', ownerRunId: 'session-main');
        $result = (new SendMessageTool($manager))->call([
            'to' => 'agent_busy',
            'message' => str_repeat('x', 32),
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertSame('background_busy', $result->safeError);
        $this->assertSame('background_busy', $result->metadata['code']);
        $this->assertSame('message_bytes', $result->metadata['resource']);
        $this->assertSame(0, $manager->get('agent_busy')['pending_messages']);
    }
}
