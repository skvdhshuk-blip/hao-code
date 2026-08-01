<?php

declare(strict_types=1);

namespace HaoCode\Tools\Bash;

use HaoCode\Support\Runtime\ProcessSupervisor;

/**
 * Process-local helper used by BashTool background jobs.
 *
 * @internal
 */
final class BackgroundBashSupervisor
{
    public const OUTPUT_LIMIT_EXIT_CODE = 1;

    public static function runFromPayloadFile(string $payloadFile): int
    {
        $raw = @file_get_contents($payloadFile);
        @unlink($payloadFile);
        if (! is_string($raw) || $raw === '') {
            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        if (! is_array($payload)) {
            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        $command = is_string($payload['command'] ?? null) ? $payload['command'] : '';
        $cwd = is_string($payload['cwd'] ?? null) ? $payload['cwd'] : '';
        $outFile = is_string($payload['outFile'] ?? null) ? $payload['outFile'] : '';
        $statusFile = is_string($payload['statusFile'] ?? null) ? $payload['statusFile'] : '';
        $env = is_array($payload['env'] ?? null) ? $payload['env'] : [];
        $timeoutSeconds = is_numeric($payload['timeoutSeconds'] ?? null)
            ? max(0.001, (float) $payload['timeoutSeconds'])
            : 120.0;
        $maxOutputBytes = is_numeric($payload['maxOutputBytes'] ?? null)
            ? max(1, (int) $payload['maxOutputBytes'])
            : 100_000;

        if ($command === '' || $cwd === '' || $outFile === '' || $statusFile === '') {
            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        $env = array_filter(
            $env,
            static fn ($value, $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        return self::run($command, $cwd, $env, $outFile, $statusFile, $timeoutSeconds, $maxOutputBytes);
    }

    /** @param array<string, string> $env */
    private static function run(
        string $command,
        string $cwd,
        array $env,
        string $outFile,
        string $statusFile,
        float $timeoutSeconds,
        int $maxOutputBytes,
    ): int {
        $out = @fopen($outFile, 'wb');
        if (! is_resource($out)) {
            self::writeStatus($statusFile, self::OUTPUT_LIMIT_EXIT_CODE);

            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $opened = ProcessSupervisor::open($command, $cwd, $env, $descriptors);
        } catch (\Throwable $e) {
            fwrite($out, 'Failed to execute command: '.$e->getMessage());
            fclose($out);
            self::writeStatus($statusFile, self::OUTPUT_LIMIT_EXIT_CODE);

            return self::OUTPUT_LIMIT_EXIT_CODE;
        }

        $process = $opened['process'];
        $pid = (int) $opened['pid'];
        $pipes = $opened['pipes'];
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = -1;
        $timedOut = false;
        $outputLimited = false;
        $bytesWritten = 0;

        while (true) {
            foreach ([1, 2] as $index) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = @stream_get_contents($pipes[$index]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $roomBefore = $maxOutputBytes - $bytesWritten;
                if (strlen($chunk) > $roomBefore) {
                    $outputLimited = true;
                    self::appendWithLimit(
                        $out,
                        $bytesWritten,
                        $maxOutputBytes,
                        $chunk,
                        "\n\n[Output truncated at {$maxOutputBytes} bytes; command terminated]",
                    );
                    ProcessSupervisor::terminateTree($pid, false);
                    break 2;
                }

                self::appendWithLimit($out, $bytesWritten, $maxOutputBytes, $chunk);
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
                ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            usleep(20_000);
        }

        foreach ([1, 2] as $index) {
            if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                continue;
            }
            $chunk = @stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '' && $bytesWritten < $maxOutputBytes) {
                self::appendWithLimit($out, $bytesWritten, $maxOutputBytes, $chunk);
            }
            fclose($pipes[$index]);
        }

        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $outputLimited) {
            $exitCode = $closed;
        }

        fclose($out);

        $finalCode = $timedOut ? 124 : ($outputLimited ? self::OUTPUT_LIMIT_EXIT_CODE : $exitCode);
        self::writeStatus($statusFile, $finalCode);

        return $finalCode;
    }

    private static function writeStatus(string $statusFile, int $exitCode): void
    {
        $tmp = $statusFile.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, (string) $exitCode, LOCK_EX) !== false) {
            @rename($tmp, $statusFile);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * @param resource $out
     */
    private static function appendWithLimit(
        $out,
        int &$bytesWritten,
        int $maxOutputBytes,
        string $chunk,
        string $notice = '',
    ): void {
        $room = $maxOutputBytes - $bytesWritten;
        if ($room <= 0) {
            return;
        }

        if ($notice !== '' && strlen($chunk) > $room) {
            $notice = strlen($notice) > $maxOutputBytes ? substr($notice, 0, $maxOutputBytes) : $notice;
            // The remaining room may be smaller than the notice itself.  Keep
            // the physical output cap authoritative even in that final slice.
            $notice = substr($notice, 0, $room);
            $roomForChunk = max(0, $room - strlen($notice));
            $data = substr($chunk, 0, $roomForChunk).$notice;
            $data = substr($data, 0, $room);
        } else {
            $data = strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk;
        }

        if ($data === '') {
            return;
        }

        $written = fwrite($out, $data);
        if (is_int($written) && $written > 0) {
            $bytesWritten += $written;
        }
    }
}
