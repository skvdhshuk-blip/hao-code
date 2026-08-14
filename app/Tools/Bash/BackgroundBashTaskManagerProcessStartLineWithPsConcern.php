<?php

namespace HaoCode\Tools\Bash;

use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolResult;

trait BackgroundBashTaskManagerProcessStartLineWithPsConcern
{

    private static function processStartLineWithPs(int $pid): ?string
    {
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            ['ps', '-p', (string) $pid, '-o', 'lstart='],
            $descriptors,
            $pipes,
            getcwd() ?: null,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'LANG' => 'C',
                'LC_ALL' => 'C',
            ],
        );
        if (! is_resource($process)) {
            return null;
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $deadline = microtime(true) + 0.5;
        $output = '';
        $exitCode = -1;
        while (true) {
            foreach ([1, 2] as $index) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = stream_get_contents($pipes[$index]);
                if (! is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($index === 1) {
                    $output .= $chunk;
                    if (strlen($output) > 4096) {
                        $output = substr($output, 0, 4096);
                    }
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
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                if ($index === 1) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if (is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                        if (strlen($output) > 4096) {
                            $output = substr($output, 0, 4096);
                        }
                    }
                }
                fclose($pipes[$index]);
            }
        }
        $closed = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }

        return $exitCode === 0 ? trim($output) : null;
    }
}
