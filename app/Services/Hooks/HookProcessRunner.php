<?php

namespace HaoCode\Services\Hooks;

/**
 * @internal
 */
final class HookProcessRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 10.0;
    private const DEFAULT_OUTPUT_LIMIT_BYTES = 1048576;

    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $stdoutLimitBytes = self::DEFAULT_OUTPUT_LIMIT_BYTES,
        private readonly int $stderrLimitBytes = self::DEFAULT_OUTPUT_LIMIT_BYTES,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Hook timeout must be greater than zero.');
        }
        if ($this->stdoutLimitBytes <= 0 || $this->stderrLimitBytes <= 0) {
            throw new \InvalidArgumentException('Hook output limits must be greater than zero.');
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{
     *     started: bool,
     *     aborted: bool,
     *     timedOut: bool,
     *     outputLimitExceeded: ?string,
     *     stdout: string,
     *     stderr: string,
     *     exitCode: ?int,
     *     error: ?string
     * }
     */
    public function run(
        string $command,
        string $stdin,
        ?string $workingDirectory,
        array $environment,
        ?callable $shouldAbort = null,
    ): array {
        if ($this->abortRequested($shouldAbort)) {
            return $this->abortedResult();
        }

        if ($workingDirectory !== null && ! is_dir($workingDirectory)) {
            return $this->notStarted(
                "Hook working directory does not exist: {$workingDirectory}",
            );
        }

        [$processCommand, $usesProcessGroup] = $this->isolateProcessTree($command);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];

        try {
            $process = @proc_open($processCommand, $descriptors, $pipes, $workingDirectory, $environment);
        } catch (\Throwable $e) {
            return $this->notStarted($e->getMessage());
        }

        if (! is_resource($process)) {
            return $this->notStarted('proc_open() did not return a process resource.');
        }

        $initialStatus = proc_get_status($process);
        $processId = is_int($initialStatus['pid'] ?? null) && $initialStatus['pid'] > 0
            ? $initialStatus['pid']
            : null;
        $processGroupId = $usesProcessGroup ? $processId : null;

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $stdinOffset = 0;
        $stdinLength = strlen($stdin);
        $aborted = false;
        $timedOut = false;
        $limitExceeded = null;
        $runnerError = null;
        $observedExitCode = null;
        $deadline = microtime(true) + $this->timeoutSeconds;

        if ($stdinLength === 0) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        while (true) {
            if ($this->abortRequested($shouldAbort)) {
                $aborted = true;
                break;
            }

            $status = proc_get_status($process);
            if (! $status['running'] && $status['exitcode'] >= 0) {
                $observedExitCode = $status['exitcode'];
            }

            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }

            $write = [];
            if (isset($pipes[0]) && is_resource($pipes[0]) && $stdinOffset < $stdinLength) {
                $write[] = $pipes[0];
            } elseif (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
                unset($pipes[0]);
            }

            if (! $status['running'] && $read === [] && $write === []) {
                break;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            if ($read === [] && $write === []) {
                usleep((int) min(10000, max(1000, $remaining * 1000000)));
                continue;
            }

            $seconds = (int) min(0.1, $remaining);
            $microseconds = (int) ((min(0.1, $remaining) - $seconds) * 1000000);
            $except = null;
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                $runnerError = 'stream_select() failed while reading hook output.';
                break;
            }

            foreach ($write as $pipe) {
                $chunk = substr($stdin, $stdinOffset, 8192);
                $written = @fwrite($pipe, $chunk);
                if ($written === false) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                    $runnerError = 'Failed to write hook context to stdin.';
                    break 2;
                }
                $stdinOffset += $written;
                if ($stdinOffset >= $stdinLength) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }

            foreach ($read as $pipe) {
                $index = $this->pipeIndex($pipes, $pipe);
                if ($index === null) {
                    continue;
                }

                $chunk = @fread($pipe, 8192);
                if ($chunk === false) {
                    $runnerError = 'Failed to read hook process output.';
                    break 2;
                }

                if ($chunk === '' && feof($pipe)) {
                    fclose($pipes[$index]);
                    unset($pipes[$index]);
                    continue;
                }

                if ($index === 1) {
                    if (strlen($stdout) + strlen($chunk) > $this->stdoutLimitBytes) {
                        $stdout .= substr($chunk, 0, $this->stdoutLimitBytes - strlen($stdout));
                        $limitExceeded = 'stdout';
                        break 2;
                    }
                    $stdout .= $chunk;
                } else {
                    if (strlen($stderr) + strlen($chunk) > $this->stderrLimitBytes) {
                        $stderr .= substr($chunk, 0, $this->stderrLimitBytes - strlen($stderr));
                        $limitExceeded = 'stderr';
                        break 2;
                    }
                    $stderr .= $chunk;
                }
            }
        }

        if ($aborted || $timedOut || $limitExceeded !== null || $runnerError !== null) {
            $this->terminate($process, $processId, $processGroupId);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $closeExitCode = proc_close($process);
        $exitCode = $observedExitCode;
        if ($exitCode === null && $closeExitCode >= 0) {
            $exitCode = $closeExitCode;
        }

        return [
            'started' => true,
            'aborted' => $aborted,
            'timedOut' => $timedOut,
            'outputLimitExceeded' => $limitExceeded,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
            'error' => $runnerError,
        ];
    }

    /**
     * @param  array<int, resource>  $pipes
     * @param  resource  $pipe
     */
    private function pipeIndex(array $pipes, $pipe): ?int
    {
        foreach ($pipes as $index => $candidate) {
            if ($candidate === $pipe) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  resource  $process
     */
    private function terminate($process, ?int $processId, ?int $processGroupId): void
    {
        if (PHP_OS_FAMILY === 'Windows' && $processId !== null && function_exists('exec')) {
            @exec('taskkill /PID '.$processId.' /T /F >NUL 2>&1');
        }

        $status = proc_get_status($process);
        if (! $status['running']) {
            if ($processGroupId !== null) {
                $this->signalProcessGroup($processGroupId, 9);
            }

            return;
        }

        if ($processGroupId !== null) {
            $this->signalProcessGroup($processGroupId, 15);
        }
        @proc_terminate($process, 15);
        $graceDeadline = microtime(true) + 0.25;
        do {
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $graceDeadline);

        // Always follow with a group SIGKILL: the direct hook process may have
        // exited on SIGTERM while a descendant ignored it and kept pipes open.
        if ($processGroupId !== null) {
            $this->signalProcessGroup($processGroupId, 9);
        }
        if ($status['running']) {
            @proc_terminate($process, 9);
            $killDeadline = microtime(true) + 0.25;
            do {
                usleep(10000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $killDeadline);
        }
    }

    /**
     * Run hooks in their own POSIX session so timeout cleanup can terminate
     * ordinary background descendants, not only the proc_open() process.
     *
     * @return array{string, bool}
     */
    private function isolateProcessTree(string $command): array
    {
        if (DIRECTORY_SEPARATOR !== '/'
            || ! function_exists('posix_setsid')
            || ! function_exists('posix_kill')) {
            return [$command, false];
        }

        $wrapper = <<<'PHP'
$command = base64_decode($argv[1] ?? '', true);
if (! is_string($command) || posix_setsid() === -1) {
    exit(125);
}
if (function_exists('pcntl_exec')) {
    pcntl_exec('/bin/sh', ['-c', $command]);
    exit(126);
}
passthru($command, $exitCode);
exit($exitCode);
PHP;

        return [
            'exec '.escapeshellarg(PHP_BINARY)
                .' -r '.escapeshellarg($wrapper)
                .' '.escapeshellarg(base64_encode($command)),
            true,
        ];
    }

    private function signalProcessGroup(int $processGroupId, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill(-$processGroupId, $signal);
        }
    }

    /**
     * @return array{
     *     started: bool,
     *     aborted: bool,
     *     timedOut: bool,
     *     outputLimitExceeded: ?string,
     *     stdout: string,
     *     stderr: string,
     *     exitCode: ?int,
     *     error: ?string
     * }
     */
    private function notStarted(string $error): array
    {
        return [
            'started' => false,
            'aborted' => false,
            'timedOut' => false,
            'outputLimitExceeded' => null,
            'stdout' => '',
            'stderr' => '',
            'exitCode' => null,
            'error' => $error,
        ];
    }

    /**
     * @return array{
     *     started: bool,
     *     aborted: bool,
     *     timedOut: bool,
     *     outputLimitExceeded: ?string,
     *     stdout: string,
     *     stderr: string,
     *     exitCode: ?int,
     *     error: ?string
     * }
     */
    private function abortedResult(): array
    {
        return [
            'started' => false,
            'aborted' => true,
            'timedOut' => false,
            'outputLimitExceeded' => null,
            'stdout' => '',
            'stderr' => '',
            'exitCode' => null,
            'error' => null,
        ];
    }

    private function abortRequested(?callable $shouldAbort): bool
    {
        if ($shouldAbort === null) {
            return false;
        }

        try {
            return (bool) $shouldAbort();
        } catch (\Throwable) {
            // A broken cancellation probe must fail closed before a hook can
            // create additional side effects.
            return true;
        }
    }
}
