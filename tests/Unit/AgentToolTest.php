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
            null,
            null,
        );

        $this->assertInstanceOf(\HaoCode\Tools\ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('already exists', $result->output);
        $this->assertSame('Original prompt', $agents->get('agent_demo')['prompt']);
        $this->assertNull($tasks->get('agent_demo'));

        $this->removeDirectory($root);
    }

    public function test_background_claim_failure_cleans_up_a_new_worktree(): void
    {
        $root = $this->makeGitRepository();
        $agents = new BackgroundAgentManager($root.'/agent-state');
        $tasks = new TaskManager($root.'/task-state');
        $agents->create('agent_demo', 'Original prompt', 'general-purpose');

        try {
            $result = (new AgentTool($this->makeFactory(), $agents, $tasks))->call([
                'prompt' => 'Inspect the repository',
                'description' => 'Inspect repository',
                'name' => 'agent_demo',
                'run_in_background' => true,
                'isolation' => 'worktree',
            ], new ToolUseContext($root, 'session-1'));

            $this->assertTrue($result->isError);
            $this->assertStringContainsString('already exists', $result->output);
            exec('git -C '.escapeshellarg($root)." branch --list 'agent-*'", $branches);
            $this->assertSame([], $branches);
        } finally {
            $this->removeDirectory($root);
        }
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
            ->with('Explore this repository')
            ->willReturn('sub-agent result');
        $subLoop->method('getTotalInputTokens')->willReturn(123);
        $subLoop->method('getTotalOutputTokens')->willReturn(45);
        $subLoop->method('getEstimatedCost')->willReturn(0.0123);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturnCallback(function (...$arguments) use ($subLoop): AgentLoop {
                $this->assertSame('claude-haiku-4-20250514', $arguments[10] ?? null);
                $this->assertStringContainsString('file search specialist', $arguments[11] ?? '');
                $this->assertTrue($arguments[12] ?? false);

                return $subLoop;
            });

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

    public function test_call_model_overrides_agent_definition_model(): void
    {
        $loop = $this->makeLoop();
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturnCallback(function (...$arguments) use ($loop): AgentLoop {
                $this->assertSame('claude-opus-4-20250514', $arguments[10] ?? null);

                return $loop;
            });

        $result = (new AgentTool($factory))->call([
            'prompt' => 'Inspect this repository',
            'subagent_type' => 'Explore',
            'model' => 'opus',
        ], new ToolUseContext('/tmp', 'session-1'));

        $this->assertFalse($result->isError);
    }

    public function test_explicit_unknown_agent_type_is_rejected(): void
    {
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->never())->method('createIsolated');

        $result = (new AgentTool($factory))->call([
            'prompt' => 'Inspect this repository',
            'subagent_type' => 'Exlpore',
        ], new ToolUseContext('/tmp', 'session-1'));

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown agent type: Exlpore', $result->output);
    }

    public function test_custom_read_only_agent_is_classified_from_the_call_project(): void
    {
        $root = sys_get_temp_dir().'/haocode-custom-agent-test-'.bin2hex(random_bytes(4));
        mkdir($root.'/.claude/agents', 0755, true);
        file_put_contents($root.'/.claude/agents/security-reader.md', <<<'MD'
---
name: security-reader
description: Read security-sensitive code
readOnly: true
---
Review the code without changing it.
MD);

        try {
            $tool = new AgentTool($this->createMock(AgentLoopFactory::class));
            $input = $tool->backfillObservableInput(
                ['subagent_type' => 'security-reader'],
                new ToolUseContext($root, 'session-1'),
            );

            $this->assertTrue($tool->isReadOnly($input));
            $this->assertFalse($tool->isReadOnly($input + ['isolation' => 'worktree']));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_worktree_isolation_is_never_classified_as_read_only(): void
    {
        $tool = new AgentTool($this->createMock(AgentLoopFactory::class));

        $this->assertFalse($tool->isReadOnly([
            'subagent_type' => 'Explore',
            'isolation' => 'worktree',
        ]));
    }

    public function test_clean_worktree_is_removed_with_its_temporary_branch(): void
    {
        $root = $this->makeGitRepository();
        try {
            $tool = new AgentTool($this->makeFactory($this->makeLoop('done')));
            $result = $tool->call([
                'prompt' => 'Inspect the repository',
                'isolation' => 'worktree',
            ], new ToolUseContext($root, 'session-1'));

            $this->assertFalse($result->isError);
            exec('cd '.escapeshellarg($root)." && git branch --list 'agent-*'", $branches);
            $this->assertSame([], $branches);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_changed_worktree_preserves_original_error_result(): void
    {
        $root = $this->makeGitRepository();
        $worktreePath = null;
        $branch = null;
        try {
            $loop = $this->createMock(AgentLoop::class);
            $loop->method('run')->willThrowException(new \RuntimeException('sub crashed'));
            $factory = $this->createMock(AgentLoopFactory::class);
            $factory->method('createIsolated')
                ->willReturnCallback(function (...$arguments) use ($loop, &$worktreePath): AgentLoop {
                    $worktreePath = $arguments[1];
                    file_put_contents($worktreePath.'/change.txt', 'changed');

                    return $loop;
                });

            $result = (new AgentTool($factory))->call([
                'prompt' => 'Change the repository',
                'isolation' => 'worktree',
            ], new ToolUseContext($root, 'session-1'));

            $this->assertTrue($result->isError);
            $this->assertStringContainsString('sub crashed', $result->output);
            $this->assertStringContainsString('Worktree with changes', $result->output);
            $this->assertDirectoryExists($worktreePath);
            $branch = basename($worktreePath);
        } finally {
            if (is_string($worktreePath) && is_dir($worktreePath)) {
                exec('cd '.escapeshellarg($root).' && git worktree remove '.escapeshellarg($worktreePath).' --force');
            }
            if (is_string($branch)) {
                exec('cd '.escapeshellarg($root).' && git branch -D '.escapeshellarg($branch));
            }
            $this->removeDirectory($root);
        }
    }

    public function test_committed_worktree_changes_are_retained(): void
    {
        $root = $this->makeGitRepository();
        $worktreePath = null;
        $branch = null;
        try {
            $loop = $this->makeLoop('committed');
            $factory = $this->createMock(AgentLoopFactory::class);
            $factory->method('createIsolated')
                ->willReturnCallback(function (...$arguments) use ($loop, &$worktreePath): AgentLoop {
                    $worktreePath = $arguments[1];
                    file_put_contents($worktreePath.'/committed.txt', "agent work\n");
                    exec('git -C '.escapeshellarg($worktreePath).' add committed.txt');
                    exec('git -C '.escapeshellarg($worktreePath).' commit -qm agent-change');

                    return $loop;
                });

            $result = (new AgentTool($factory))->call([
                'prompt' => 'Commit a repository change',
                'isolation' => 'worktree',
            ], new ToolUseContext($root, 'session-1'));

            $this->assertFalse($result->isError);
            $this->assertStringContainsString('Worktree with changes', $result->output);
            $this->assertDirectoryExists($worktreePath);
            $branch = basename($worktreePath);
        } finally {
            if (is_string($worktreePath) && is_dir($worktreePath)) {
                exec('git -C '.escapeshellarg($root).' worktree remove '.escapeshellarg($worktreePath).' --force');
            }
            if (is_string($branch)) {
                exec('git -C '.escapeshellarg($root).' branch -D '.escapeshellarg($branch));
            }
            $this->removeDirectory($root);
        }
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

    private function makeGitRepository(): string
    {
        $root = sys_get_temp_dir().'/haocode-agent-worktree-test-'.bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        exec('git -C '.escapeshellarg($root).' init -q');
        exec('git -C '.escapeshellarg($root).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($root).' config user.name Test');
        file_put_contents($root.'/README.md', "test\n");
        exec('git -C '.escapeshellarg($root).' add README.md');
        exec('git -C '.escapeshellarg($root).' commit -qm initial');

        return $root;
    }
}
