<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Agent\TeamResultCollector;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Tools\Team\TeamAwaitTool;
use HaoCode\Tools\Team\TeamCollectTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class TeamResultToolsTest extends TestCase
{
    private string $root;
    private TeamManager $teams;
    private BackgroundAgentManager $agents;
    private TeamResultCollector $collector;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/haocode-team-results-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/teams', 0755, true);
        mkdir($this->root.'/agents', 0755, true);
        $this->teams = new TeamManager($this->root.'/teams');
        $this->agents = new BackgroundAgentManager($this->root.'/agents');
        $this->collector = new TeamResultCollector($this->teams, $this->agents);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function test_collect_returns_complete_structured_results(): void
    {
        $this->seedTeam();
        $tool = new TeamCollectTool($this->collector);

        $result = $tool->call(['name' => 'research'], new ToolUseContext('/tmp', 'test'));
        $payload = json_decode($result->output, true);

        $this->assertFalse($result->isError);
        $this->assertSame(2, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['succeeded']);
        $this->assertSame(1, $payload['summary']['failed']);
        $this->assertSame('Full evidence from docs.', $payload['members'][0]['result']);
        $this->assertSame('Provider failed', $payload['members'][1]['error']);
    }

    public function test_await_returns_immediately_when_every_member_is_terminal(): void
    {
        $this->seedTeam();
        $tool = new TeamAwaitTool($this->collector);

        $started = microtime(true);
        $result = $tool->call(['name' => 'research'], new ToolUseContext('/tmp', 'test'));
        $payload = json_decode($result->output, true);

        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->assertFalse($payload['timed_out']);
        $this->assertSame(0, $payload['summary']['pending']);
    }

    public function test_collect_surfaces_a_waiting_member_interrupt(): void
    {
        $this->teams->create('research', [
            ['role' => 'docs', 'agent_type' => 'Explore', 'prompt' => 'Read docs'],
        ]);
        $this->agents->create('research_docs', 'Read docs', 'Explore');
        $interrupt = new HumanInterrupt(
            'int-team',
            'session-team-child',
            [new HumanActionRequest('call-team', 'Write', [], 'Review write')],
            date('c'),
            'research_docs',
            'research',
        );
        $this->agents->markWaitingForInput('research_docs', $interrupt);

        $this->expectException(HumanInterruptException::class);
        (new TeamCollectTool($this->collector))->call(['name' => 'research'], new ToolUseContext('/tmp', 'test'));
    }

    public function test_waiting_member_with_stale_result_remains_pending(): void
    {
        $this->teams->create('research', [
            ['role' => 'docs', 'agent_type' => 'Explore', 'prompt' => 'Read docs'],
        ]);
        $this->agents->create('research_docs', 'Read docs', 'Explore');
        $this->agents->recordResult('research_docs', 'Result from a previous turn.');
        $interrupt = new HumanInterrupt(
            'int-team-stale',
            'session-team-child',
            [new HumanActionRequest('call-team', 'Write', [], 'Review write')],
            date('c'),
            'research_docs',
            'research',
        );
        $this->agents->markWaitingForInput('research_docs', $interrupt);

        $payload = $this->collector->collect('research');

        $this->assertSame(1, $payload['summary']['pending']);
        $this->assertSame(0, $payload['summary']['succeeded']);
        $this->assertSame('pending', $payload['members'][0]['outcome']);
    }

    public function test_collect_does_not_surface_stale_interrupt_after_completion(): void
    {
        $this->teams->create('research', [
            ['role' => 'docs', 'agent_type' => 'Explore', 'prompt' => 'Read docs'],
        ]);
        $this->agents->create('research_docs', 'Read docs', 'Explore');
        $interrupt = new HumanInterrupt(
            'int-team',
            'session-team-child',
            [new HumanActionRequest('call-team', 'Write', [], 'Review write')],
            date('c'),
            'research_docs',
            'research',
        );
        $this->agents->markWaitingForInput('research_docs', $interrupt);
        $this->agents->markCompleted('research_docs', 'Finished after resume.');

        $result = (new TeamCollectTool($this->collector))->call(
            ['name' => 'research'],
            new ToolUseContext('/tmp', 'test'),
        );
        $payload = json_decode($result->output, true);

        $this->assertFalse($result->isError);
        $this->assertNull($payload['members'][0]['pending_interrupt']);
        $this->assertSame('completed', $payload['members'][0]['status']);
    }

    private function seedTeam(): void
    {
        $this->teams->create('research', [
            ['role' => 'docs', 'agent_type' => 'Explore', 'prompt' => 'Read docs'],
            ['role' => 'code', 'agent_type' => 'Explore', 'prompt' => 'Read code'],
        ]);
        $this->agents->create('research_docs', 'Read docs', 'Explore');
        $this->agents->recordResult('research_docs', 'Full evidence from docs.');
        $this->agents->create('research_code', 'Read code', 'Explore');
        $this->agents->markError('research_code', 'Provider failed');
    }
}
