<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\Bash\BackgroundBashSupervisor;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait BashToolTestTestForegroundOutputLimitTerminatesCommandBeforeLaterSideEffectsConcern
{

    public function test_foreground_output_limit_terminates_command_before_later_side_effects(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_fg_output_limit_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $command = sprintf(
            'python3 -c %s',
            escapeshellarg(
                'import sys, time; '
                .'sys.stdout.write("x" * 200000); sys.stdout.flush(); '
                .'time.sleep(1); '
                .'open('.var_export($marker, true).', "w").write("leaked")'
            ),
        );

        $start = microtime(true);
        $result = $this->tool->call([
            'command' => $command,
            'timeout' => 5000,
        ], $this->context);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isError, $result->output);
        $this->assertSame(1, $result->metadata['exitCode'] ?? null);
        $this->assertTrue($result->metadata['outputLimited'] ?? false);
        $this->assertStringContainsString('Output truncated', $result->output);
        $this->assertLessThanOrEqual(101_000, strlen($result->output));
        $this->assertLessThan(3.0, $elapsed, 'Output limit should terminate promptly');

        usleep(1_100_000);
        $this->assertFileDoesNotExist($marker, 'Output limit must terminate before delayed side effects run');
    }

    public function test_foreground_capture_never_falls_back_to_unbounded_regular_files(): void
    {
        $method = new \ReflectionMethod(BashTool::class, 'allocateForegroundCaptureFiles');
        $capture = $method->invoke($this->tool);

        $this->assertIsArray($capture);
        try {
            if (($capture['usePipes'] ?? false) === true) {
                $this->assertNull($capture['stdoutFile'] ?? null);
                $this->assertNull($capture['stderrFile'] ?? null);

                return;
            }

            $this->assertTrue(function_exists('posix_mkfifo'));
            $this->assertTrue(is_string($capture['stdoutFile'] ?? null));
            $this->assertTrue(is_string($capture['stderrFile'] ?? null));
            $this->assertSame('fifo', filetype($capture['stdoutFile']));
            $this->assertSame('fifo', filetype($capture['stderrFile']));
        } finally {
            foreach (['stdoutHandle', 'stderrHandle'] as $key) {
                if (is_resource($capture[$key] ?? null)) {
                    fclose($capture[$key]);
                }
            }
            foreach (['stdoutFile', 'stderrFile'] as $key) {
                if (is_string($capture[$key] ?? null)) {
                    @unlink($capture[$key]);
                }
            }
        }
    }

    public function test_call_times_out_long_running_command(): void
    {
        // Use a 500 ms timeout against a command that would otherwise sleep for 10 s.
        $start = microtime(true);

        $result = $this->tool->call([
            'command' => 'sleep 10',
            'timeout' => 500, // 500 ms
        ], $this->context);

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isError, 'Timed-out command must return an error result');
        $this->assertStringContainsString('timed out', strtolower($result->output));
        $this->assertLessThan(3.0, $elapsed, 'Tool should return well within 3 s (timeout was 0.5 s)');
        $this->assertSame(124, $result->metadata['exitCode'] ?? null);
        $this->assertTrue($result->metadata['timedOut'] ?? false);
        $this->assertFalse($result->metadata['outputLimited'] ?? true);
    }

    public function test_timeout_kills_process_group_children(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_tree_kill_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        // Parent shell backgrounds a delayed writer then waits. Timeout must
        // kill the whole process group so the marker is never written.
        $command = sprintf(
            '(sleep 1; printf leaked > %s) & wait',
            escapeshellarg($marker),
        );

        $result = $this->tool->call([
            'command' => $command,
            'timeout' => 200,
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertTrue($result->metadata['timedOut'] ?? false);

        // Give a leaked child time to write if the kill tree failed.
        usleep(1_200_000);
        $this->assertFileDoesNotExist($marker, 'Timeout must terminate the process group, not only the wrapper shell');
    }

    public function test_call_aborts_long_running_command_when_context_is_interrupted(): void
    {
        $checks = 0;
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
            shouldAbort: function () use (&$checks): bool {
                $checks++;

                return $checks >= 2;
            },
        );

        $start = microtime(true);
        $result = $this->tool->call([
            'command' => 'sleep 10',
            'timeout' => 30000,
        ], $context);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('interrupted by user', strtolower($result->output));
        $this->assertSame(130, $result->metadata['exitCode'] ?? null);
        $this->assertTrue($result->metadata['aborted'] ?? false);
        $this->assertLessThan(3.0, $elapsed);
    }

    public function test_call_does_not_time_out_fast_command(): void
    {
        $result = $this->tool->call([
            'command' => 'echo hello',
            'timeout' => 5000, // 5 s — plenty of time
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_call_returns_promptly_when_shell_backgrounds_a_child(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_bg_marker_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $command = sprintf(
            'sh -c %s & echo started',
            escapeshellarg('sleep 1.2; echo late; echo done > ' . escapeshellarg($marker))
        );

        $start = microtime(true);

        $result = $this->tool->call([
            'command' => $command,
            'timeout' => 1000,
        ], $this->context);

        $elapsed = microtime(true) - $start;

        $this->assertFalse($result->isError, 'Shell should finish even if a background child keeps running');
        $this->assertStringContainsString('started', $result->output);
        $this->assertFalse($result->metadata['timedOut'] ?? false);
        $this->assertLessThan(1.5, $elapsed, 'Foreground shell should return without waiting for the background child');

        usleep(1_500_000);

        $this->assertFileExists($marker, 'Background child should keep running after the shell exits');
        $this->assertSame('done', trim((string) file_get_contents($marker)));

        @unlink($marker);
    }

    public function test_call_prepends_warning_for_rm_rf(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bash_rm_test_');

        $result = $this->tool->call([
            'command' => 'rm -f ' . escapeshellarg($file),
        ], $this->context);

        $this->assertStringContainsString('<warnings>', $result->output);
    }

    public function test_call_persists_working_directory_between_commands(): void
    {
        $baseDir = sys_get_temp_dir() . '/bash-tool-cwd-' . uniqid();
        $childDir = $baseDir . '/child';
        mkdir($childDir, 0777, true);

        $context = new ToolUseContext(
            workingDirectory: $baseDir,
            sessionId: 'cwd-session-' . uniqid(),
        );

        $first = $this->tool->call([
            'command' => 'cd child && pwd',
        ], $context);
        $second = $this->tool->call([
            'command' => 'pwd',
        ], $context);

        $this->assertFalse($first->isError);
        $this->assertFalse($second->isError);
        $this->assertStringContainsString($childDir, trim($first->output));
        $this->assertStringContainsString($childDir, trim($second->output));
    }

    public function test_call_persists_working_directory_after_cd_then_failing_command(): void
    {
        $baseDir = sys_get_temp_dir() . '/bash-tool-cwd-fail-' . uniqid();
        $childDir = $baseDir . '/child';
        mkdir($childDir, 0777, true);

        $context = new ToolUseContext(
            workingDirectory: $baseDir,
            sessionId: 'cwd-session-fail-' . uniqid(),
        );

        $result = $this->tool->call([
            'command' => 'cd child && nonexistent_command_haocode_test',
        ], $context);
        $followUp = $this->tool->call([
            'command' => 'pwd',
        ], $context);

        $this->assertTrue($result->isError);
        $this->assertFalse($followUp->isError);
        $this->assertStringContainsString($childDir, trim($followUp->output));
    }

    /**
     * @dataProvider envDenyKeyProvider
     */
    public function test_required_env_deny_keys_are_stripped_from_subprocess(string $envKey): void
    {
        // Set the env var on the PHP process (the way an attacker-controlled
        // parent shell or systemd unit would), then ask BashTool to print
        // the child env. The key must NOT appear in the child output.
        putenv($envKey . '=haocode-should-not-leak');
        try {
            $result = $this->tool->call([
                'command' => 'env',
                'description' => 'dump env for test',
            ], $this->context);

            $this->assertFalse($result->isError, $result->output);
            $this->assertStringNotContainsString(
                $envKey . '=haocode-should-not-leak',
                $result->output,
                "{$envKey} must be stripped before the subprocess sees it",
            );
        } finally {
            putenv($envKey);
        }
    }

    public static function envDenyKeyProvider(): array
    {
        return [
            'LD_PRELOAD'              => ['LD_PRELOAD'],
            'DYLD_INSERT_LIBRARIES'   => ['DYLD_INSERT_LIBRARIES'],
            'DYLD_LIBRARY_PATH'       => ['DYLD_LIBRARY_PATH'],
            'PYTHONPATH'              => ['PYTHONPATH'],
            'NODE_OPTIONS'            => ['NODE_OPTIONS'],
            'PERL5OPT'                => ['PERL5OPT'],
        ];
    }

    public function test_custom_policy_env_deny_is_stripped_from_foreground_and_background_commands(): void
    {
        $project = sys_get_temp_dir().'/haocode-bash-policy-'.bin2hex(random_bytes(4));
        mkdir($project.'/.haocode', 0777, true);
        $policy = $project.'/policy.yml';
        file_put_contents($policy, <<<'YAML'
rules:
  - name: bash-env
    tool: Bash
    cmd: env
    allow_auto: true
    env_deny:
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT
      - HAOCODE_CUSTOM_DENY
YAML);
        file_put_contents($project.'/.haocode/settings.json', json_encode([
            'permissions' => ['policy_files' => [$policy]],
        ], JSON_THROW_ON_ERROR));
        $context = new ToolUseContext(
            workingDirectory: $project,
            sessionId: 'policy-env-'.bin2hex(random_bytes(4)),
            runContext: AgentRunContextFactory::make(new HaoCodeConfig(cwd: $project)),
        );
        putenv('HAOCODE_CUSTOM_DENY=must-not-leak');

        try {
            $foreground = $this->tool->call(['command' => 'env'], $context);
            $this->assertFalse($foreground->isError, $foreground->output);
            $this->assertStringNotContainsString('HAOCODE_CUSTOM_DENY=must-not-leak', $foreground->output);

            $started = $this->tool->call([
                'command' => 'env',
                'run_in_background' => true,
                'timeout' => 5000,
            ], $context);
            $taskId = $started->metadata['taskId'] ?? null;
            $this->assertIsString($taskId);
            $completed = null;
            $deadline = microtime(true) + 5.0;
            do {
                usleep(50_000);
                $completed = BashTool::checkTask($taskId);
            } while (($completed?->metadata['running'] ?? false) && microtime(true) < $deadline);

            $this->assertNotNull($completed);
            $this->assertFalse($completed->isError, $completed->output);
            $this->assertStringNotContainsString('HAOCODE_CUSTOM_DENY=must-not-leak', $completed->output);
        } finally {
            putenv('HAOCODE_CUSTOM_DENY');
            @unlink($project.'/.haocode/settings.json');
            @unlink($policy);
            @rmdir($project.'/.haocode');
            @rmdir($project);
        }
    }

    public function test_dangerously_disable_sandbox_is_rejected_as_unsupported(): void
    {
        $result = $this->tool->call([
            'command' => 'echo hi',
            'dangerouslyDisableSandbox' => true,
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('dangerouslyDisableSandbox is not supported', $result->output);
    }

    public function test_input_schema_does_not_advertise_dangerously_disable_sandbox(): void
    {
        $schema = $this->tool->inputSchema()->toJsonSchema();
        $this->assertArrayNotHasKey('dangerouslyDisableSandbox', $schema['properties'] ?? []);
    }

    public function test_run_in_background_and_check_task_lifecycle(): void
    {
        $result = $this->tool->call([
            'command' => 'printf bg-ok',
            'run_in_background' => true,
        ], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $final = $this->awaitBackgroundTask($taskId);

        $this->assertFalse($final->isError, $final->output);
        $this->assertStringContainsString('bg-ok', $final->output);
        $this->assertStringContainsString('completed', $final->output);
        $this->assertSame(0, $final->metadata['exitCode'] ?? null);

        $missing = BashTool::checkTask($taskId);
        $this->assertTrue($missing->isError);
        $this->assertStringContainsString('Unknown background task', $missing->output);
    }

    public function test_run_in_background_returns_promptly_without_waiting_for_command_output(): void
    {
        $start = microtime(true);

        $result = $this->tool->call([
            'command' => 'sleep 2; printf done',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);

        $elapsed = microtime(true) - $start;
        $taskId = $result->metadata['taskId'] ?? null;

        try {
            $this->assertFalse($result->isError, $result->output);
            $this->assertIsString($taskId);
            $this->assertLessThan(0.75, $elapsed, 'Background launch must return without waiting for the user command');
        } finally {
            if (is_string($taskId)) {
                $this->awaitBackgroundTask($taskId);
            }
        }
    }

    public function test_background_timeout_kills_command_before_later_side_effects(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_bg_timeout_marker_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $result = $this->tool->call([
            'command' => 'sleep 1; printf leaked > '.escapeshellarg($marker),
            'run_in_background' => true,
            'timeout' => 250,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;

        $this->assertFalse($result->isError, $result->output);
        $this->assertIsString($taskId);

        $final = $this->awaitBackgroundTask($taskId);

        $this->assertTrue($final->isError, $final->output);
        $this->assertSame(124, $final->metadata['exitCode'] ?? null);
        $this->assertTrue($final->metadata['timedOut'] ?? false);

        usleep(1_100_000);
        $this->assertFileDoesNotExist($marker, 'Background timeout must terminate the command before delayed side effects run');
    }

    public function test_background_output_file_has_physical_size_limit(): void
    {
        $result = $this->tool->call([
            'command' => 'python3 -c "print(\'x\' * 200000)"',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;

        $this->assertFalse($result->isError, $result->output);
        $this->assertIsString($taskId);

        $task = $this->awaitBackgroundStatusFile($taskId);
        $outFile = $task['outFile'];
        $this->assertFileExists($outFile);
        $this->assertLessThanOrEqual(100_000, (int) filesize($outFile));

        $final = BashTool::checkTask($taskId);
        $this->assertTrue($final->isError, $final->output);
        $this->assertSame(1, $final->metadata['exitCode'] ?? null);
        $this->assertStringContainsString('Output truncated', $final->output);
    }
}
