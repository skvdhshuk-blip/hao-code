<?php

namespace HaoCode\Tools\FileRead;

use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\BoundedTextFileReader;
use HaoCode\Support\Filesystem\FileContentTypeDetector;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait FileReadToolRunProcessWithOutputCapConcern
{

    /**
     * @param list<string> $command
     * @param callable(): bool|null $shouldAbort
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool, aborted: bool, outputLimited: bool}
     */
    private function runProcessWithOutputCap(
        array $command,
        float $timeoutSeconds,
        int $stdoutByteCap,
        ?callable $shouldAbort = null,
    ): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [
            0 => ['file', $null, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false, 'aborted' => false, 'outputLimited' => false];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;
        $aborted = false;
        $outputLimited = false;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $target) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = @stream_get_contents($pipes[$index]);
                if (! is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($target === 'stdout') {
                    $room = $stdoutByteCap - strlen($stdout);
                    if (strlen($chunk) > $room) {
                        $stdout .= substr($chunk, 0, max(0, $room));
                        $outputLimited = true;
                        \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                        break 2;
                    }
                    $stdout .= $chunk;
                } else {
                    $stderr = substr($stderr.$chunk, -32_768);
                }
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = ($status['signaled'] ?? false)
                    ? 128 + (int) ($status['termsig'] ?? 0)
                    : (int) ($status['exitcode'] ?? -1);
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            usleep(20_000);
        }

        foreach ([1, 2] as $index) {
            if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                continue;
            }
            $chunk = @stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                if ($index === 1 && ! $outputLimited) {
                    $room = $stdoutByteCap - strlen($stdout);
                    if (strlen($chunk) > $room) {
                        $stdout .= substr($chunk, 0, max(0, $room));
                        $outputLimited = true;
                    } else {
                        $stdout .= $chunk;
                    }
                } elseif ($index === 2) {
                    $stderr = substr($stderr.$chunk, -32_768);
                }
            }
            fclose($pipes[$index]);
        }

        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $outputLimited) {
            $exitCode = $closed;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $aborted ? 130 : ($timedOut ? -1 : ($outputLimited ? 1 : $exitCode)),
            'timedOut' => $timedOut,
            'aborted' => $aborted,
            'outputLimited' => $outputLimited,
        ];
    }
}
