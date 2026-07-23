<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\BuiltInAgents;
use HaoCode\Tools\Agent\AgentTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class AgentToolTest extends TestCase
{
    private function makeFactory(?AgentLoop $loop = null): AgentLoopFactory
    {
        $factory = $this->createMock(AgentLoopFactory::class);
        if ($loop !== null) {
            $factory->method('createIsolated')->willReturn($loop);
        }
        return $factory;
    }

    private function makeLoop(string $result = 'result'): AgentLoop
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturn($result);
        $loop->method('getTotalInputTokens')->willReturn(0);
        $loop->method('getTotalOutputTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.0);
        return $loop;
    }

    private function context(): ToolUseContext
    {
        return new ToolUseContext('/tmp', 'test');
    }

    // ─── success path ─────────────────────────────────────────────────────

    public function test_returns_sub_agent_output(): void
    {
        $tool = new AgentTool($this->makeFactory($this->makeLoop('answer from agent')));
        $result = $tool->call(['prompt' => 'Do something useful'], $this->context());
        $this->assertFalse($result->isError);
        $this->assertSame('answer from agent', $result->output);
    }

    // ─── error handling ───────────────────────────────────────────────────

    public function test_sub_agent_exception_returns_error(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willThrowException(new \RuntimeException('sub crashed'));

        $tool = new AgentTool($this->makeFactory($loop));
        $result = $tool->call(['prompt' => 'Do something'], $this->context());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('sub crashed', $result->output);
    }

    public function test_named_background_agent_cannot_overwrite_existing_state(): void
    {
        $root = sys_get_temp_dir().'/haocode-agent-tool-test-'.bin2hex(random_bytes(4));
        $agents = new BackgroundAgentManager($root.'/agents');
        $tasks = new TaskManager($root.'/tasks');
        $agents->create('agent_demo', 'Original prompt', 'general-purpose');
        $tool = new AgentTool($this->makeFactory(), $agents, $tasks);

        $method = new \ReflectionMethod($tool, 'claimBackgroundAgent');
        $method->setAccessible(true);
        $result = $method->invoke(
            $tool,
            'agent_demo',
            'Replacement prompt',
            BuiltInAgents::get('general-purpose'),
            'Replacement',
            'Replacement task',
        );

        $this->assertInstanceOf(\HaoCode\Tools\ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('already exists', $result->output);
        $this->assertSame('Original prompt', $agents->get('agent_demo')['prompt']);
        $this->assertNull($tasks->get('agent_demo'));

        $this->removeDirectory($root);
    }

    // ─── metadata ─────────────────────────────────────────────────────────

    public function test_metadata_contains_token_counts_and_cost(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturn('ok');
        $loop->method('getTotalInputTokens')->willReturn(100);
        $loop->method('getTotalOutputTokens')->willReturn(50);
        $loop->method('getEstimatedCost')->willReturn(0.005);

        $tool = new AgentTool($this->makeFactory($loop));
        $result = $tool->call(['prompt' => 'Analyze this'], $this->context());
        $this->assertSame(100, $result->metadata['inputTokens']);
        $this->assertSame(50, $result->metadata['outputTokens']);
        $this->assertSame(0.005, $result->metadata['cost']);
    }

    // ─── default agent type ───────────────────────────────────────────────

    public function test_default_agent_type_is_general_purpose(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('getTotalInputTokens')->willReturn(0);
        $loop->method('getTotalOutputTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.0);

        $promptPassed = '';
        $loop->method('run')->willReturnCallback(function (string $p) use (&$promptPassed) {
            $promptPassed = $p;
            return 'done';
        });

        $tool = new AgentTool($this->makeFactory($loop));
        $tool->call(['prompt' => 'Fix the bug'], $this->context());

        // general-purpose has no special system prompt prepended — just the prompt
        $this->assertStringContainsString('Fix the bug', $promptPassed);
    }

    // ─── tool metadata ────────────────────────────────────────────────────

    public function test_name_is_agent(): void
    {
        $tool = new AgentTool($this->createMock(AgentLoopFactory::class));
        $this->assertSame('Agent', $tool->name());
    }

    public function test_is_not_read_only(): void
    {
        $tool = new AgentTool($this->createMock(AgentLoopFactory::class));
        $this->assertFalse($tool->isReadOnly([]));
    }

    // ─── existing test ────────────────────────────────────────────────────

    public function test_it_runs_sub_agents_via_an_isolated_loop_from_the_factory(): void
    {
        $subLoop = $this->createMock(AgentLoop::class);
        $subLoop->expects($this->once())
            ->method('run')
            ->with($this->callback(function (string $prompt): bool {
                return str_contains($prompt, 'file search specialist')
                    && str_contains($prompt, 'Explore this repository');
            }))
            ->willReturn('sub-agent result');
        $subLoop->method('getTotalInputTokens')->willReturn(123);
        $subLoop->method('getTotalOutputTokens')->willReturn(45);
        $subLoop->method('getEstimatedCost')->willReturn(0.0123);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturn($subLoop);

        $tool = new AgentTool($factory);

        $result = $tool->call([
            'prompt' => 'Explore this repository',
            'subagent_type' => 'Explore',
        ], new ToolUseContext('/tmp', 'session-1'));

        $this->assertFalse($result->isError);
        $this->assertSame('sub-agent result', $result->output);
        $this->assertSame(123, $result->metadata['inputTokens'] ?? null);
        $this->assertSame(45, $result->metadata['outputTokens'] ?? null);
        $this->assertSame(0.0123, $result->metadata['cost'] ?? null);
    }

    public function test_it_inherits_the_parent_working_directory(): void
    {
        $loop = $this->makeLoop('done');
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->with(
                $this->anything(),
                '/tmp/parent-project',
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($loop);

        $tool = new AgentTool($factory);
        $result = $tool->call(
            ['prompt' => 'Inspect a relative file'],
            new ToolUseContext('/tmp/parent-project', 'session-1'),
        );

        $this->assertFalse($result->isError);
    }

    public function test_it_derives_the_child_from_the_parent_tool_registry(): void
    {
        $loop = $this->makeLoop('done');
        $parentRegistry = new ToolRegistry;
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturnCallback(function (...$arguments) use ($loop, $parentRegistry): AgentLoop {
                $this->assertSame($parentRegistry, $arguments[9] ?? null);

                return $loop;
            });

        $tool = new AgentTool($factory);
        $result = $tool->call(
            ['prompt' => 'Inspect the repository'],
            new ToolUseContext('/tmp/parent-project', 'session-1', toolRegistry: $parentRegistry),
        );

        $this->assertFalse($result->isError);
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
