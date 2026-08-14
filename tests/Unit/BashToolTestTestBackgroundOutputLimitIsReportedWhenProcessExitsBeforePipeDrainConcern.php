<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\Bash\BackgroundBashSupervisor;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait BashToolTestTestBackgroundOutputLimitIsReportedWhenProcessExitsBeforePipeDrainConcern
{

    public function test_background_output_limit_is_reported_when_process_exits_before_pipe_drain(): void
    {
        $result = $this->tool->call([
            'command' => 'python3 -c "import sys; sys.stdout.write(\'x\' * 200000)"',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;

        $this->assertFalse($result->isError, $result->output);
        $this->assertIsString($taskId);

        $final = $this->awaitBackgroundTask($taskId);

        $this->assertTrue($final->isError, $final->output);
        $this->assertSame(1, $final->metadata['exitCode'] ?? null);
        $this->assertStringContainsString('Output truncated', $final->output);
    }

    public function test_background_output_limit_never_writes_truncation_notice_past_cap(): void
    {
        $method = (new \ReflectionClass(BackgroundBashSupervisor::class))->getMethod('appendWithLimit');
        $method->setAccessible(true);
        $stream = fopen('php://temp', 'w+b');
        $bytesWritten = 95;
        $arguments = [
            $stream,
            &$bytesWritten,
            100,
            '0123456789',
            "\n\n[Output truncated at 100 bytes; command terminated]",
        ];

        $method->invokeArgs(null, $arguments);
        fflush($stream);
        $this->assertLessThanOrEqual(100, $bytesWritten);
        $this->assertLessThanOrEqual(100, fstat($stream)['size'] ?? PHP_INT_MAX);
        fclose($stream);
    }

    public function test_background_nonzero_exit_is_error_and_runs_once(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_bg_once_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $command = sprintf(
            'printf x >> %s; exit 7',
            escapeshellarg($marker),
        );

        $result = $this->tool->call([
            'command' => $command,
            'run_in_background' => true,
        ], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $final = $this->awaitBackgroundTask($taskId);

        $this->assertTrue($final->isError, $final->output);
        $this->assertSame(7, $final->metadata['exitCode'] ?? null);
        $this->assertFileExists($marker);
        $this->assertSame('x', file_get_contents($marker), 'Command must run exactly once despite non-zero exit');
        @unlink($marker);
    }

    public function test_late_poll_does_not_relabel_completed_nonzero_exit_as_timeout(): void
    {
        $result = $this->tool->call([
            'command' => 'exit 7',
            'run_in_background' => true,
            'timeout' => 1000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;

        $this->assertFalse($result->isError, $result->output);
        $this->assertIsString($taskId);

        // Let the command finish, then deliberately poll after its configured
        // deadline. Completion status must still come from the supervisor.
        usleep(1_100_000);
        $final = $this->awaitBackgroundTask($taskId);

        $this->assertTrue($final->isError, $final->output);
        $this->assertSame(7, $final->metadata['exitCode'] ?? null);
        $this->assertFalse($final->metadata['timedOut'] ?? false);
        $this->assertStringContainsString('failed with exit code 7', $final->output);
    }

    /** @return array{outFile: string, statusFile: string} */
    private function awaitBackgroundStatusFile(string $taskId): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            $tasks = BashTool::listTasks();
            $task = $tasks[$taskId] ?? null;
            $this->assertIsArray($task);
            if (is_file((string) ($task['statusFile'] ?? ''))) {
                return [
                    'outFile' => (string) $task['outFile'],
                    'statusFile' => (string) $task['statusFile'],
                ];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('Background task did not write a status file before the test deadline');
    }

    private function awaitBackgroundTask(string $taskId): \HaoCode\Tools\ToolResult
    {
        $deadline = microtime(true) + 5.0;
        $final = null;
        while (microtime(true) < $deadline) {
            $final = BashTool::checkTask($taskId);
            $this->assertNotNull($final);
            if (($final->metadata['running'] ?? null) === false
                || str_contains($final->output, 'completed')
                || str_contains($final->output, 'failed with exit code')
                || $final->isError && ! str_contains($final->output, 'still running')
                    && ! str_contains($final->output, 'status is unknown')) {
                break;
            }
            usleep(50_000);
        }

        $this->assertNotNull($final);

        return $final;
    }
}
