<?php

namespace Tests\Unit;

use HaoCode\Support\Runtime\ProcessSupervisor;
use PHPUnit\Framework\TestCase;

class ProcessSupervisorTest extends TestCase
{
    public function test_tree_termination_helpers_do_not_shell_out(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProcessSupervisor::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('@exec', $source);
    }

    public function test_open_reports_missing_bash_before_starting_command(): void
    {
        $emptyPath = sys_get_temp_dir().'/haocode-empty-path-'.bin2hex(random_bytes(4));
        mkdir($emptyPath, 0700, true);

        try {
            $this->expectOpenToFailWithMissingBash($emptyPath);
        } finally {
            @rmdir($emptyPath);
        }
    }

    public function test_tree_termination_kills_a_descendant_that_starts_its_own_session(): void
    {
        if (PHP_OS_FAMILY === 'Windows'
            || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')
            || ! function_exists('posix_setsid')) {
            $this->markTestSkipped('POSIX process-tree coverage is unavailable.');
        }

        $started = tempnam(sys_get_temp_dir(), 'haocode-detached-start-');
        $completed = tempnam(sys_get_temp_dir(), 'haocode-detached-complete-');
        $childPidFile = tempnam(sys_get_temp_dir(), 'haocode-detached-pid-');
        $this->assertNotFalse($started);
        $this->assertNotFalse($completed);
        $this->assertNotFalse($childPidFile);
        @unlink($started);
        @unlink($completed);
        @unlink($childPidFile);

        $rootPid = pcntl_fork();
        $this->assertNotSame(-1, $rootPid, 'Unable to fork the process-tree probe.');

        if ($rootPid === 0) {
            if (@posix_setsid() === -1) {
                exit(70);
            }

            $detachedPid = pcntl_fork();
            if ($detachedPid === 0) {
                if (@posix_setsid() === -1) {
                    exit(71);
                }
                file_put_contents($started, 'started');
                usleep(1_000_000);
                file_put_contents($completed, 'completed');
                exit(0);
            }
            if ($detachedPid === -1) {
                exit(72);
            }

            file_put_contents($childPidFile, (string) $detachedPid);
            sleep(30);
            exit(0);
        }

        try {
            $deadline = microtime(true) + 2.0;
            while (! is_file($started) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($started, 'Detached child did not start before termination.');

            ProcessSupervisor::terminateTree($rootPid);
            pcntl_waitpid($rootPid, $status);

            usleep(1_300_000);
            $this->assertFileDoesNotExist(
                $completed,
                'Terminating a process group must also kill descendants that call setsid().',
            );
        } finally {
            if (@posix_kill($rootPid, 0)) {
                ProcessSupervisor::terminateTree($rootPid, true);
                @pcntl_waitpid($rootPid, $status);
            }
            $childPid = (int) trim((string) @file_get_contents($childPidFile));
            if ($childPid > 0) {
                @posix_kill($childPid, defined('SIGKILL') ? SIGKILL : 9);
            }
            @unlink($started);
            @unlink($completed);
            @unlink($childPidFile);
        }
    }

    private function expectOpenToFailWithMissingBash(string $path): void
    {
        try {
            $opened = ProcessSupervisor::open('echo should-not-run', sys_get_temp_dir(), [
                'PATH' => $path,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ]);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Bash executable was not found on PATH', $e->getMessage());

            return;
        }

        if (isset($opened['pid'])) {
            ProcessSupervisor::terminateTree((int) $opened['pid']);
        }
        foreach ($opened['pipes'] ?? [] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (isset($opened['process']) && is_resource($opened['process'])) {
            proc_close($opened['process']);
        }

        $this->fail('ProcessSupervisor::open() should fail before spawning when bash is missing.');
    }
}
