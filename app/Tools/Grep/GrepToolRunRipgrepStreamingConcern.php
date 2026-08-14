<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Filesystem\GitignoreMatcher;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait GrepToolRunRipgrepStreamingConcern
{

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: list<string>, 2: string, 3: bool}
     */
    private function runRipgrepStreaming(array $argv, string $path, int $lineLimit, ?callable $shouldAbort = null): array
    {
        $process = @proc_open($argv, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, is_dir($path) ? $path : dirname($path));
        if (! is_resource($process)) {
            return [2, [], 'Failed to start ripgrep.', false];
        }
        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $deadline = microtime(true) + self::RIPGREP_TIMEOUT_SECONDS;
        $stdoutBuffer = '';
        $stderr = '';
        $lines = [];
        $capturedOutputBytes = 0;
        $truncated = false;
        $exitCode = -1;
        $processExited = false;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                ProcessSupervisor::terminateTree($pid, false);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [130, $lines, 'ripgrep aborted.', true];
            }

            $chunk = is_resource($pipes[1] ?? null) ? @fread($pipes[1], 65536) : false;
            if (is_string($chunk) && $chunk !== '') {
                $stdoutBuffer .= $chunk;
                while (($newline = strpos($stdoutBuffer, "\n")) !== false) {
                    $line = substr($stdoutBuffer, 0, $newline);
                    $stdoutBuffer = substr($stdoutBuffer, $newline + 1);
                    $capturedOutputBytes += strlen($line) + 1;
                    if ($capturedOutputBytes > self::RIPGREP_OUTPUT_MAX) {
                        ProcessSupervisor::terminateTree($pid, false);
                        foreach ([1, 2] as $index) {
                            if (is_resource($pipes[$index] ?? null)) {
                                fclose($pipes[$index]);
                            }
                        }
                        @proc_close($process);

                        return [
                            2,
                            $lines,
                            'ripgrep output exceeded '.self::RIPGREP_OUTPUT_MAX.' bytes.',
                            true,
                        ];
                    }
                    $lines[] = $line;
                    if (count($lines) >= $lineLimit) {
                        $truncated = true;
                        ProcessSupervisor::terminateTree($pid, false);
                        break 2;
                    }
                }
                if (strlen($stdoutBuffer) > self::MAX_LINE_BYTES) {
                    ProcessSupervisor::terminateTree($pid, false);
                    foreach ([1, 2] as $index) {
                        if (is_resource($pipes[$index] ?? null)) {
                            fclose($pipes[$index]);
                        }
                    }
                    @proc_close($process);

                    return [
                        2,
                        $lines,
                        'ripgrep output line exceeded '.self::MAX_LINE_BYTES.' bytes.',
                        true,
                    ];
                }
            }

            $err = is_resource($pipes[2] ?? null) ? @fread($pipes[2], 8192) : false;
            if (is_string($err) && $err !== '') {
                $stderr .= $err;
                if (strlen($stderr) > self::RIPGREP_STDERR_MAX) {
                    $stderr = substr($stderr, -self::RIPGREP_STDERR_MAX);
                }
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                if (! $processExited) {
                    $exitCode = ($status['signaled'] ?? false)
                        ? 128 + (int) ($status['termsig'] ?? 0)
                        : (int) ($status['exitcode'] ?? -1);
                    $processExited = true;
                }
            }

            // proc_get_status() can report that the child exited while the
            // pipe still contains unread bytes. Keep draining both pipes so
            // the output limits apply to the complete process output rather
            // than only to the first kernel-buffer-sized chunk.
            if ($processExited
                && is_resource($pipes[1] ?? null)
                && is_resource($pipes[2] ?? null)
                && feof($pipes[1])
                && feof($pipes[2])) {
                break;
            }
            if (microtime(true) >= $deadline) {
                ProcessSupervisor::terminateTree($pid, false);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [2, $lines, 'ripgrep timed out.', true];
            }

            usleep(10_000);
        }

        if ($stdoutBuffer !== '' && count($lines) < $lineLimit) {
            if (strlen($stdoutBuffer) > self::MAX_LINE_BYTES) {
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [
                    2,
                    $lines,
                    'ripgrep output line exceeded '.self::MAX_LINE_BYTES.' bytes.',
                    true,
                ];
            }
            $capturedOutputBytes += strlen($stdoutBuffer);
            if ($capturedOutputBytes > self::RIPGREP_OUTPUT_MAX) {
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [
                    2,
                    $lines,
                    'ripgrep output exceeded '.self::RIPGREP_OUTPUT_MAX.' bytes.',
                    true,
                ];
            }
            $lines[] = rtrim($stdoutBuffer, "\r\n");
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                fclose($pipes[$index]);
            }
        }
        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $truncated) {
            $exitCode = $closed;
        }

        return [$truncated ? 0 : $exitCode, $lines, trim($stderr), $truncated];
    }
}
