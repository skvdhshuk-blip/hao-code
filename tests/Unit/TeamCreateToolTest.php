<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\BuiltInAgents;
use HaoCode\Tools\Team\TeamCreateTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class TeamCreateToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/haocode-team-create-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_schema_accepts_explicit_inherit_model(): void
    {
        $tool = new TeamCreateTool(
            $this->createMock(AgentLoopFactory::class),
            new TeamManager($this->root.'/teams'),
            new BackgroundAgentManager($this->root.'/agents'),
            new TaskManager($this->root.'/tasks'),
        );

        $validated = $tool->inputSchema()->validate([
            'name' => 'reviewers',
            'task' => 'Review the release',
            'members' => [['role' => 'reviewer', 'model' => 'inherit']],
        ]);

        $this->assertSame('inherit', $validated['members'][0]['model']);
    }

    public function test_rejects_member_roles_that_collide_after_normalization(): void
    {
        $tool = new TeamCreateTool(
            $this->createMock(AgentLoopFactory::class),
            new TeamManager($this->root.'/teams'),
            new BackgroundAgentManager($this->root.'/agents'),
            new TaskManager($this->root.'/tasks'),
        );

        $result = $tool->call([
            'name' => 'reviewers',
            'task' => 'Review the release',
            'members' => [
                ['role' => 'QA Lead'],
                ['role' => 'qa-lead'],
            ],
        ], new ToolUseContext('/tmp', 'test'));

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('collide after normalization', $result->output);
        $this->assertFileDoesNotExist($this->root.'/teams/reviewers.team.json');
    }

    public function test_rejects_unknown_member_agent_type_before_creating_manifest(): void
    {
        $tool = new TeamCreateTool(
            $this->createMock(AgentLoopFactory::class),
            new TeamManager($this->root.'/teams'),
            new BackgroundAgentManager($this->root.'/agents'),
            new TaskManager($this->root.'/tasks'),
        );

        $result = $tool->call([
            'name' => 'reviewers',
            'task' => 'Review the release',
            'members' => [
                ['role' => 'reviewer', 'agent_type' => 'Exlpore'],
            ],
        ], new ToolUseContext('/tmp', 'test'));

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown agent type: Exlpore', $result->output);
        $this->assertFileDoesNotExist($this->root.'/teams/reviewers.team.json');
        $this->assertNull((new BackgroundAgentManager($this->root.'/agents'))->get('reviewers_reviewer'));
        $this->assertNull((new TaskManager($this->root.'/tasks'))->get('reviewers_reviewer'));
    }

    public function test_member_execution_applies_model_system_prompt_and_omit_instructions(): void
    {
        $agents = new BackgroundAgentManager($this->root.'/agents');
        $tasks = new TaskManager($this->root.'/tasks');
        $agents->create('reviewers_reader', 'Review the release', 'Explore');
        $agents->requestStop('reviewers_reader');
        $tasks->createWithId('reviewers_reader', 'Reader', 'Reviewing');
        $definition = BuiltInAgents::get('Explore');
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('runOutcome')->willReturn(\HaoCode\Services\Agent\AgentRunOutcome::normal('Done'));
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturnCallback(function (...$arguments) use ($loop, $definition): AgentLoop {
                $this->assertSame('claude-opus-4-8', $arguments[10] ?? null);
                $this->assertSame($definition->systemPrompt, $arguments[11] ?? null);
                $this->assertTrue($arguments[12] ?? false);

                return $loop;
            });
        $tool = new TeamCreateTool(
            $factory,
            new TeamManager($this->root.'/teams'),
            $agents,
            $tasks,
        );
        $method = new \ReflectionMethod($tool, 'executeBackgroundAgent');
        $method->setAccessible(true);

        $method->invoke(
            $tool,
            'reviewers_reader',
            'reviewers',
            'Review the release',
            $definition,
            'claude-opus-4-8',
            new ToolUseContext('/tmp', 'controller'),
            false,
            1,
        );

        $this->assertSame('completed', $agents->get('reviewers_reader')['status']);
        $this->assertSame('completed', $tasks->get('reviewers_reader')->status);
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
