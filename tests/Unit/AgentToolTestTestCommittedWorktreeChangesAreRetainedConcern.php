<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\BuiltInAgents;
use HaoCode\Tools\Agent\AgentTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait AgentToolTestTestCommittedWorktreeChangesAreRetainedConcern
{

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

            $result = (new AgentTool(
                $factory,
                new BackgroundAgentManager($root.'/agent-state'),
                new TaskManager($root.'/task-state'),
            ))->call([
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
