<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use PHPUnit\Framework\TestCase;

trait BackgroundAgentManagerTestTestSignalReaperRestoresHostHandlerAndAsyncModeConcern
{

    public function test_signal_reaper_restores_host_handler_and_async_mode(): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl signal support is required.');
        }

        $originalHandler = pcntl_signal_get_handler(SIGCHLD);
        $originalAsync = pcntl_async_signals();
        $hostHandler = static function (): void {};
        pcntl_signal(SIGCHLD, $hostHandler);
        pcntl_async_signals(false);

        $pid = null;
        try {
            $this->manager->create('agent_signal', 'Inspect repo', 'Explore');
            $pid = pcntl_fork();
            if ($pid === 0) {
                sleep(5);
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            $this->manager->attachProcess('agent_signal', $pid);
            $this->assertTrue(pcntl_async_signals());

            BackgroundAgentManager::resetSignalReaper();

            $this->assertSame($hostHandler, pcntl_signal_get_handler(SIGCHLD));
            $this->assertFalse(pcntl_async_signals());
        } finally {
            if (is_int($pid) && $pid > 0) {
                @posix_kill($pid, SIGTERM);
                @pcntl_waitpid($pid, $status);
            }
            pcntl_signal(SIGCHLD, $originalHandler);
            pcntl_async_signals($originalAsync);
        }
    }

    private function git(string $directory, string $arguments): void
    {
        $output = [];
        exec('cd '.escapeshellarg($directory).' && git '.$arguments.' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
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
