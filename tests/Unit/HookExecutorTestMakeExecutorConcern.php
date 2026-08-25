<?php

namespace Tests\Unit;

use HaoCode\Services\Hooks\HookDefinition;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookProcessRunner;
use HaoCode\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

trait HookExecutorTestMakeExecutorConcern
{
    /**
     * Build an executor with no hooks loaded from disk, then inject hooks manually.
     */
    private function makeExecutor(
        array $hooks = [],
        ?HookProcessRunner $processRunner = null,
        ?string $workingDirectory = null,
    ): HookExecutor
    {
        $executor = new HookExecutor($workingDirectory);

        $ref = new \ReflectionClass($executor);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue($executor, $hooks);
        if ($processRunner !== null) {
            $runnerProp = $ref->getProperty('processRunner');
            $runnerProp->setAccessible(true);
            $runnerProp->setValue($executor, $processRunner);
        }

        return $executor;
    }

    private function makeHook(string $event, string $command): HookDefinition
    {
        return new HookDefinition(event: $event, command: $command);
    }

    public function test_execute_with_no_hooks_returns_allowed(): void
    {
        $executor = $this->makeExecutor();
        $result = $executor->execute('PreToolUse', ['tool' => 'Bash']);

        $this->assertTrue($result->allowed);
    }

    public function test_execute_with_no_hooks_returns_empty_output(): void
    {
        $executor = $this->makeExecutor();
        $result = $executor->execute('PreToolUse');

        $this->assertSame('', $result->output);
        $this->assertNull($result->modifiedInput);
    }

    public function test_invalid_project_settings_json_is_rejected(): void
    {
        $directory = sys_get_temp_dir().'/haocode_hook_settings_'.bin2hex(random_bytes(4));
        mkdir($directory.'/.haocode', 0755, true);
        $path = $directory.'/.haocode/settings.json';
        file_put_contents($path, '{"hooks":');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid JSON in settings file');
            new HookExecutor($directory);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            @rmdir($directory.'/.haocode');
            @rmdir($directory);
        }
    }

    public function test_hook_loader_honours_configured_global_settings_path(): void
    {
        $directory = sys_get_temp_dir().'/haocode_hook_global_'.bin2hex(random_bytes(4));
        $project = $directory.'/project';
        $globalPath = $directory.'/global/settings.json';
        mkdir($project, 0755, true);
        mkdir(dirname($globalPath), 0755, true);
        file_put_contents($globalPath, json_encode([
            'hooks' => [
                'ConfiguredEvent' => [
                    ['command' => 'echo configured-global-hook'],
                ],
            ],
        ]));
        $oldPath = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.global_settings_path');
        \HaoCode\Support\Runtime\SdkRuntime::config([
            'haocode.global_settings_path' => $globalPath,
        ]);

        try {
            $result = (new HookExecutor($project, $globalPath))->execute('ConfiguredEvent');
            $this->assertTrue($result->allowed);
            $this->assertSame('configured-global-hook', $result->output);
        } finally {
            \HaoCode\Support\Runtime\SdkRuntime::config([
                'haocode.global_settings_path' => $oldPath,
            ]);
            @unlink($globalPath);
            @unlink($globalPath.'.lock');
            @rmdir(dirname($globalPath));
            @rmdir($project);
            @rmdir($directory);
        }
    }

    public function test_successful_hook_returns_allowed(): void
    {
        $hook = $this->makeHook('PostToolUse', 'echo "hook ran"');
        $executor = $this->makeExecutor(['PostToolUse' => [$hook]]);

        $result = $executor->execute('PostToolUse');

        $this->assertTrue($result->allowed);
    }

    public function test_successful_hook_captures_stdout(): void
    {
        $hook = $this->makeHook('TestEvent', 'echo "hello from hook"');
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertStringContainsString('hello from hook', $result->output);
    }

    public function test_failing_hook_returns_not_allowed(): void
    {
        // `false` exits with code 1 on all POSIX shells
        $hook = $this->makeHook('PreToolUse', 'false');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_failing_hook_output_mentions_exit_code(): void
    {
        $hook = $this->makeHook('PreToolUse', 'false');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertStringContainsString('exit code', $result->output);
    }

    public function test_failing_hook_short_circuits_remaining_hooks(): void
    {
        // First hook fails, second should not run
        $hooks = [
            $this->makeHook('TestEvent', 'false'),
            $this->makeHook('TestEvent', 'echo "should not run"'),
        ];
        $executor = $this->makeExecutor(['TestEvent' => $hooks]);

        $result = $executor->execute('TestEvent');

        $this->assertFalse($result->allowed);
        $this->assertStringNotContainsString('should not run', $result->output);
    }

    public function test_pre_tool_use_hook_timeout_fails_closed_and_is_terminated(): void
    {
        $runner = new HookProcessRunner(timeoutSeconds: 0.15);
        $hook = $this->makeHook('PreToolUse', 'sleep 5');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]], $runner);

        $startedAt = microtime(true);
        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('timed out', $result->output);
        $this->assertLessThan(2.0, microtime(true) - $startedAt);
    }

    public function test_pre_aborted_hook_does_not_start_or_create_side_effects(): void
    {
        $marker = sys_get_temp_dir().'/haocode-hook-abort-'.bin2hex(random_bytes(8));
        $command = escapeshellarg(PHP_BINARY)
            .' -r '.escapeshellarg('file_put_contents($argv[1], "ran");')
            .' '.escapeshellarg($marker);
        $executor = $this->makeExecutor([
            'PreToolUse' => [$this->makeHook('PreToolUse', $command)],
        ]);

        try {
            $result = $executor->execute(
                'PreToolUse',
                ['tool' => 'Write'],
                static fn (): bool => true,
            );

            $this->assertFalse($result->allowed);
            $this->assertStringContainsString('aborted', $result->output);
            $this->assertFileDoesNotExist($marker);
        } finally {
            @unlink($marker);
        }
    }

    public function test_mid_hook_abort_terminates_process_promptly(): void
    {
        $runner = new HookProcessRunner(timeoutSeconds: 5.0);
        $executor = $this->makeExecutor(
            ['PreToolUse' => [$this->makeHook('PreToolUse', 'sleep 5')]],
            $runner,
        );
        $startedAt = microtime(true);

        $result = $executor->execute(
            'PreToolUse',
            [],
            static fn (): bool => microtime(true) - $startedAt >= 0.1,
        );

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('aborted', $result->output);
        $this->assertLessThan(2.0, microtime(true) - $startedAt);
    }

    public function test_hook_timeout_terminates_background_descendants(): void
    {
        if (DIRECTORY_SEPARATOR !== '/'
            || ! function_exists('posix_setsid')
            || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX process groups are required for hook tree cleanup coverage.');
        }

        $pidFile = sys_get_temp_dir().'/haocode-hook-child-'.bin2hex(random_bytes(8));
        $childScript = <<<'PHP'
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(15, SIG_IGN);
}
while (true) {
    usleep(10000);
}
PHP;
        $command = escapeshellarg(PHP_BINARY)
            .' -r '.escapeshellarg($childScript)
            .' & child_pid=$!; printf %s "$child_pid" > '.escapeshellarg($pidFile)
            .'; wait "$child_pid"';
        // Allow the PHP child to start and publish its PID before the
        // timeout path is exercised.  The assertion still covers prompt
        // process-tree cleanup; a sub-second deadline is not the contract
        // under test here.
        $runner = new HookProcessRunner(timeoutSeconds: 1.0);
        $executor = $this->makeExecutor(
            ['PreToolUse' => [$this->makeHook('PreToolUse', $command)]],
            $runner,
        );

        try {
            $result = $executor->execute('PreToolUse');
            $this->assertFalse($result->allowed);
            $this->assertStringContainsString('timed out', $result->output);
            $this->assertFileExists($pidFile);

            $childPid = (int) file_get_contents($pidFile);
            $deadline = microtime(true) + 1.0;
            while ($childPid > 0 && @posix_kill($childPid, 0) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $alive = $childPid > 0 && @posix_kill($childPid, 0);
            if ($alive) {
                @posix_kill($childPid, 9);
            }

            $this->assertFalse($alive, 'Timed-out Hook descendants must not outlive the runner.');
        } finally {
            @unlink($pidFile);
        }
    }

    public function test_hook_timeout_terminates_a_descendant_in_a_separate_session(): void
    {
        if (DIRECTORY_SEPARATOR !== '/'
            || ! function_exists('pcntl_fork')
            || ! function_exists('posix_setsid')
            || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX detached process coverage is unavailable.');
        }

        $pidFile = sys_get_temp_dir().'/haocode-hook-detached-child-'.bin2hex(random_bytes(8));
        $marker = sys_get_temp_dir().'/haocode-hook-detached-marker-'.bin2hex(random_bytes(8));
        $childScript = <<<'PHP'
$childPid = pcntl_fork();
if ($childPid === 0) {
    if (posix_setsid() === -1) {
        exit(71);
    }
    usleep(2_000_000);
    file_put_contents($argv[2], 'leaked');
    exit(0);
}
if ($childPid === -1) {
    exit(72);
}
file_put_contents($argv[1], (string) $childPid);
while (true) {
    usleep(10_000);
}
PHP;
        $command = escapeshellarg(PHP_BINARY)
            .' -r '.escapeshellarg($childScript)
            .' '.escapeshellarg($pidFile)
            .' '.escapeshellarg($marker);
        $runner = new HookProcessRunner(timeoutSeconds: 1.5);
        $executor = $this->makeExecutor(
            ['PreToolUse' => [$this->makeHook('PreToolUse', $command)]],
            $runner,
        );

        try {
            $result = $executor->execute('PreToolUse');
            $this->assertFalse($result->allowed);
            $this->assertStringContainsString('timed out', $result->output);
            $this->assertFileExists($pidFile);

            usleep(2_300_000);
            $this->assertFileDoesNotExist(
                $marker,
                'Timed-out Hook descendants that call setsid() must not outlive the runner.',
            );
        } finally {
            $childPid = (int) trim((string) @file_get_contents($pidFile));
            if ($childPid > 0 && @posix_kill($childPid, 0)) {
                @posix_kill($childPid, defined('SIGKILL') ? SIGKILL : 9);
            }
            @unlink($pidFile);
            @unlink($marker);
        }
    }

    public function test_hook_reads_large_stderr_without_deadlocking_stdout(): void
    {
        $script = <<<'PHP'
fwrite(STDERR, str_repeat('e', 256 * 1024));
fwrite(STDOUT, '{"allow":true,"message":"completed"}');
PHP;
        $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script);
        $hook = $this->makeHook('PreToolUse', $command);
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertTrue($result->allowed);
        $this->assertSame('completed', $result->output);
    }

    public function test_pre_tool_use_process_start_failure_fails_closed(): void
    {
        $missingDirectory = sys_get_temp_dir().'/missing-haocode-hook-'.bin2hex(random_bytes(8));
        $hook = $this->makeHook('PreToolUse', 'echo should-not-run');
        $executor = $this->makeExecutor(
            ['PreToolUse' => [$hook]],
            workingDirectory: $missingDirectory,
        );

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Failed to execute hook', $result->output);
    }

    public function test_non_gate_hook_process_start_failure_remains_non_blocking(): void
    {
        $missingDirectory = sys_get_temp_dir().'/missing-haocode-hook-'.bin2hex(random_bytes(8));
        $hook = $this->makeHook('PostToolUse', 'echo should-not-run');
        $executor = $this->makeExecutor(
            ['PostToolUse' => [$hook]],
            workingDirectory: $missingDirectory,
        );

        $result = $executor->execute('PostToolUse');

        $this->assertTrue($result->allowed);
        $this->assertStringContainsString('Failed to execute hook', $result->output);
    }

    public function test_pre_tool_use_output_limit_fails_closed(): void
    {
        $runner = new HookProcessRunner(
            // Keep process startup out of this output-bound test's deadline;
            // the production default is already bounded independently.
            timeoutSeconds: 5.0,
            stdoutLimitBytes: 32768,
            stderrLimitBytes: 32768,
        );
        $script = <<<'PHP'
fwrite(STDOUT, str_repeat('x', 65536));
PHP;
        $hook = $this->makeHook(
            'PreToolUse',
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
        );
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]], $runner);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('output limit', $result->output);
    }

    public function test_hook_output_deny_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo deny');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_output_block_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo block');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_output_no_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo no');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_json_output_with_allow_true(): void
    {
        $json = json_encode(['allow' => true, 'message' => 'all good']);
        $hook = $this->makeHook('TestEvent', "echo '{$json}'");
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertTrue($result->allowed);
        $this->assertSame('all good', $result->output);
    }

    public function test_hook_json_output_with_allow_false(): void
    {
        $json = json_encode(['allow' => false, 'message' => 'blocked by hook']);
        $hook = $this->makeHook('TestEvent', "echo '{$json}'");
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertFalse($result->allowed);
    }
}
