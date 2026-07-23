<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Task\TaskManager;
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
