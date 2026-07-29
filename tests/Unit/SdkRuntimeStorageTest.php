<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\Runtime\SdkRuntime;
use PHPUnit\Framework\TestCase;

class SdkRuntimeStorageTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir().'/haocode-runtime-storage-test-'.bin2hex(random_bytes(4));
        SdkRuntime::reset();
        SdkRuntime::boot(dirname(__DIR__, 2), $this->storagePath);
    }

    protected function tearDown(): void
    {
        SdkRuntime::reset();
        $this->removeDirectory($this->storagePath);
    }

    public function test_agent_team_and_task_state_use_the_runtime_storage_path(): void
    {
        SdkRuntime::app(BackgroundAgentManager::class)->create('agent_demo', 'Inspect', 'Explore');
        SdkRuntime::app(TeamManager::class)->create('reviewers', [['role' => 'reviewer']]);
        SdkRuntime::app(TaskManager::class)->createWithId('task_demo', 'Task', 'Working');

        $this->assertFileExists($this->storagePath.'/app/haocode/background-agents/agent_demo.state.json');
        $this->assertFileExists($this->storagePath.'/app/haocode/teams/reviewers.team.json');
        $this->assertFileExists($this->storagePath.'/app/haocode/tasks/tasks.json');
    }

    public function test_runtime_rejects_storage_path_hot_switch_after_boot(): void
    {
        SdkRuntime::app(TaskManager::class);
        $newPath = $this->storagePath.'-other';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('storage path cannot be changed');

        SdkRuntime::boot(dirname(__DIR__, 2), $newPath);
    }

    public function test_file_history_follows_custom_session_path(): void
    {
        $sessionPath = $this->storagePath.'/custom-sessions';
        SdkRuntime::config(['haocode.session_path' => $sessionPath]);
        SdkRuntime::app()->forgetInstance(FileHistoryManager::class);
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        $file = $this->storagePath.'/tracked.txt';
        file_put_contents($file, 'tracked');

        SdkRuntime::app(FileHistoryManager::class)
            ->forSession('custom-session')
            ->recordBefore($file);

        $this->assertFileExists(
            $sessionPath.'/.file-history/'
                .hash('sha256', 'custom-session')
                .'/manifest.json',
        );
    }

    public function test_file_history_rejects_empty_session_path(): void
    {
        SdkRuntime::config(['haocode.session_path' => '']);
        SdkRuntime::app()->forgetInstance(FileHistoryManager::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session storage path must be a non-empty string.');

        SdkRuntime::app(FileHistoryManager::class);
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
