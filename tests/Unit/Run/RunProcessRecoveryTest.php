<?php

declare(strict_types=1);

namespace Tests\Unit\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunProcessRecoveryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (! function_exists('proc_open') || DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX process termination is required.');
        }
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $this->directory = sys_get_temp_dir().'/haocode-process-recovery-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    /** @return iterable<string, array{string, bool, string, int}> */
    public static function crashBoundaryProvider(): iterable
    {
        yield 'claim persisted before tool start' => ['after_claim', true, 'completed', 1];
        yield 'side effect escaped before result commit' => ['after_effect', false, 'unknown', 1];
        yield 'result committed before process death' => ['after_commit', false, 'completed', 1];
    }

    #[DataProvider('crashBoundaryProvider')]
    public function test_independent_process_recovers_after_sigkill_without_duplicate_side_effect(
        string $scenario,
        bool $shouldExecute,
        string $expectedState,
        int $expectedEffectCount,
    ): void {
        $runStore = $this->directory.'/run-state.sqlite';
        $effectStore = $this->directory.'/effects.sqlite';
        $readyFile = $this->directory.'/ready.json';
        [$owner, $pipes] = $this->startProcess('owner', $scenario, $runStore, $effectStore, $readyFile);

        try {
            $this->waitForReadyFile($readyFile, $owner, $pipes);
            $status = $this->killProcess($owner);
            self::assertTrue($status['signaled']);
            self::assertSame(9, $status['termsig']);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($owner)) {
                proc_close($owner);
            }
        }

        $recovered = $this->runProcess('recover', $scenario, $runStore, $effectStore, $readyFile);

        self::assertTrue($recovered['run_acquired']);
        self::assertSame(2, $recovered['run_fencing_token']);
        self::assertSame($shouldExecute, $recovered['execute']);
        self::assertSame($expectedState, $recovered['tool_state']);
        self::assertSame($shouldExecute ? 2 : 1, $recovered['tool_fencing_token']);
        self::assertSame($expectedEffectCount, $recovered['effect_count']);
    }

    /** @return array{resource, array<int, resource>} */
    private function startProcess(
        string $mode,
        string $scenario,
        string $runStore,
        string $effectStore,
        string $readyFile,
    ): array {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/fixtures/run-state-process-worker.php',
                $mode, $scenario, $runStore, $effectStore, $readyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            self::fail('Could not start the run-state process fixture.');
        }
        fclose($pipes[0]);
        unset($pipes[0]);

        return [$process, $pipes];
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function waitForReadyFile(string $readyFile, $process, array $pipes): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($readyFile) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail('Owner process exited early: '.$this->readPipe($pipes[2] ?? null));
            }
            usleep(10_000);
        }
        self::assertFileExists($readyFile, 'Owner process did not reach the requested crash boundary.');
    }

    /** @param resource $process @return array<string, mixed> */
    private function killProcess($process): array
    {
        self::assertTrue(proc_terminate($process, 9), 'Could not send SIGKILL to owner process.');
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                return $status;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Owner process remained alive after SIGKILL.');
    }

    /** @return array<string, mixed> */
    private function runProcess(
        string $mode,
        string $scenario,
        string $runStore,
        string $effectStore,
        string $readyFile,
    ): array {
        [$process, $pipes] = $this->startProcess($mode, $scenario, $runStore, $effectStore, $readyFile);
        $stdout = $this->readPipe($pipes[1] ?? null);
        $stderr = $this->readPipe($pipes[2] ?? null);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, $stderr);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, $stdout);

        return $decoded;
    }

    /** @param resource|null $pipe */
    private function readPipe($pipe): string
    {
        return is_resource($pipe) ? (string) stream_get_contents($pipe) : '';
    }
}
