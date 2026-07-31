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
