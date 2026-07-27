<?php

declare(strict_types=1);

namespace HaoCode\Support\Runtime;

/**
 * Local process launch + tree termination helpers shared by Bash paths.
 *
 * @internal
 */
final class ProcessSupervisor
{
    /**
     * @param  array<string, string>  $env
     * @return array{process: resource, pid: int, pipes: array}
     */
    public static function open(
        string $commandScript,
        string $cwd,
        array $env,
        array $descriptors,
    ): array {
        // Prefer setsid so the child is a session/group leader and can be
        // signalled as a whole tree via kill(-pid, sig). Without setsid,
        // enable job control and an EXIT trap that kills the shell's group.
        if (self::hasSetsid()) {
            $cmd = ['setsid', 'bash', '-lc', $commandScript];
        } else {
            $guarded = 'set -m; '
                .'trap \'status=$?; kill -TERM -$$ 2>/dev/null; kill -KILL -$$ 2>/dev/null; exit $status\' EXIT INT TERM HUP; '
                .$commandScript;
            $cmd = ['bash', '-lc', $guarded];
        }

        $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to start process.');
        }

        // Close inherited stdin pipe handle if present.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);

        return ['process' => $process, 'pid' => $pid, 'pipes' => $pipes];
    }

    /**
     * Terminate a process and its descendants / process group.
     */
    public static function terminateTree(int $pid, bool $force = false): void
    {
        if ($pid <= 0) {
            return;
        }

        $sig = $force
            ? (defined('SIGKILL') ? SIGKILL : 9)
            : (defined('SIGTERM') ? SIGTERM : 15);
        $killSig = defined('SIGKILL') ? SIGKILL : 9;

        if (function_exists('posix_kill')) {
            // Negative PID = process group (setsid / job-control leader).
            @posix_kill(-$pid, $sig);
            @posix_kill($pid, $sig);
            self::killDescendants($pid, $sig);
            if (! $force) {
                usleep(150_000);
                @posix_kill(-$pid, $killSig);
                @posix_kill($pid, $killSig);
                self::killDescendants($pid, $killSig);
            }

            return;
        }

        // Best-effort without posix.
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID '.(int) $pid.' 2>NUL');
        } else {
            @exec('kill -'.(int) $sig.' -'.(int) $pid.' 2>/dev/null');
            @exec('kill -'.(int) $sig.' '.(int) $pid.' 2>/dev/null');
            self::killDescendants($pid, $sig);
            if (! $force) {
                usleep(150_000);
                @exec('kill -9 -'.(int) $pid.' 2>/dev/null');
                @exec('kill -9 '.(int) $pid.' 2>/dev/null');
                self::killDescendants($pid, $killSig);
            }
        }
    }

    private static function killDescendants(int $pid, int $sig): void
    {
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $raw = @shell_exec('pgrep -P '.(int) $pid.' 2>/dev/null');
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $child) {
            if (! ctype_digit($child)) {
                continue;
            }
            $childPid = (int) $child;
            self::killDescendants($childPid, $sig);
            if (function_exists('posix_kill')) {
                @posix_kill($childPid, $sig);
            } else {
                @exec('kill -'.(int) $sig.' '.(int) $childPid.' 2>/dev/null');
            }
        }
    }

    /**
     * @param  resource  $process
     * @param  callable(): bool|null  $shouldAbort
     * @return array{exitCode: int, timedOut: bool, aborted: bool}
     */
    public static function wait(
        $process,
        int $pid,
        float $timeoutSeconds,
        ?callable $shouldAbort = null,
    ): array {
        $deadline = microtime(true) + max(0.001, $timeoutSeconds);
        $timedOut = false;
        $aborted = false;
        $exitCode = -1;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                self::terminateTree($pid, false);
                break;
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
                self::terminateTree($pid, false);
                break;
            }

            usleep(50_000);
        }

        $closed = proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $aborted) {
            $exitCode = $closed;
        }
        if ($aborted) {
            $exitCode = 130;
        }
        if ($timedOut) {
            $exitCode = -1;
        }

        return ['exitCode' => $exitCode, 'timedOut' => $timedOut, 'aborted' => $aborted];
    }

    public static function hasSetsid(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return $cached = false;
        }
        $path = @shell_exec('command -v setsid 2>/dev/null');
        $cached = is_string($path) && trim($path) !== '';

        return $cached;
    }
}
