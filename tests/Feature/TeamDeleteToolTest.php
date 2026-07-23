<?php

namespace Tests\Feature;

use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Team\TeamDeleteTool;
use HaoCode\Tools\ToolUseContext;
use Tests\TestCase;

class TeamDeleteToolTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/haocode-team-delete-test-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
        config(['haocode.session_path' => $this->tempDir.'/sessions']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }

    public function test_delete_cancels_waiting_member_interrupt_before_removing_state(): void
    {
        $teams = new TeamManager($this->tempDir.'/teams');
        $agents = new BackgroundAgentManager($this->tempDir.'/agents');
        $tasks = new TaskManager($this->tempDir.'/tasks');
        $sessions = new SessionManager;
        $team = $teams->create('reviewers', [[
            'role' => 'reader',
            'agent_type' => 'Explore',
            'prompt' => 'Review the repository.',
        ]]);
        $agentId = $team['members'][0]['agent_id'];
        $agents->create($agentId, 'Review the repository.', 'Explore');
        $tasks->createWithId($agentId, 'Reader', 'Reviewing');

        $interrupt = new HumanInterrupt(
            'int-team-delete',
            $sessions->getSessionId(),
            [new HumanActionRequest('call-1', 'Bash', [], 'Review')],
            date('c'),
            $agentId,
        );
        $sessions->recordPendingInterrupt(
            $interrupt->toArray(),
            ['assistant_message' => ['role' => 'assistant', 'content' => []]],
        );
        $agents->markWaitingForInput($agentId, $interrupt);

        $result = (new TeamDeleteTool($teams, $agents, $tasks, $sessions))->call(
            ['name' => 'reviewers'],
            new ToolUseContext($this->tempDir, 'controller'),
        );

        $this->assertFalse($result->isError);
        $this->assertNull($teams->get('reviewers'));
        $this->assertNull($agents->get($agentId));
        $this->assertNull($tasks->get($agentId));
        $this->assertSame(
            'interrupt_cancelled',
            $sessions->getInterruptState($sessions->getSessionId(), 'int-team-delete')['type'],
        );
    }

    public function test_delete_keeps_state_when_a_running_member_cannot_be_safely_stopped(): void
    {
        $teams = new TeamManager($this->tempDir.'/teams');
        $agents = new BackgroundAgentManager($this->tempDir.'/agents');
        $tasks = new TaskManager($this->tempDir.'/tasks');
        $sessions = new SessionManager;
        $team = $teams->create('reviewers', [[
            'role' => 'reader',
            'agent_type' => 'Explore',
        ]]);
        $agentId = $team['members'][0]['agent_id'];
        $pid = getmypid();
        $this->assertNotFalse($pid);
        $agents->create($agentId, 'Review the repository.', 'Explore', pid: $pid);
        $agents->markRunning($agentId);
        $tasks->createWithId($agentId, 'Reader', 'Reviewing');

        $result = (new TeamDeleteTool($teams, $agents, $tasks, $sessions))->call(
            ['name' => 'reviewers'],
            new ToolUseContext($this->tempDir, 'controller'),
        );

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('shutdown could not be confirmed', $result->output);
        $this->assertNotNull($teams->get('reviewers'));
        $this->assertTrue($agents->isStopRequested($agentId));
        $this->assertNotNull($tasks->get($agentId));
    }

    public function test_delete_removes_task_even_when_member_state_is_already_missing(): void
    {
        $teams = new TeamManager($this->tempDir.'/teams');
        $agents = new BackgroundAgentManager($this->tempDir.'/agents');
        $tasks = new TaskManager($this->tempDir.'/tasks');
        $sessions = new SessionManager;
        $team = $teams->create('reviewers', [['role' => 'reader']]);
        $agentId = $team['members'][0]['agent_id'];
        $tasks->createWithId($agentId, 'Reader', 'Reviewing');

        $result = (new TeamDeleteTool($teams, $agents, $tasks, $sessions))->call(
            ['name' => 'reviewers'],
            new ToolUseContext($this->tempDir, 'controller'),
        );

        $this->assertFalse($result->isError);
        $this->assertNull($teams->get('reviewers'));
        $this->assertNull($tasks->get($agentId));
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
