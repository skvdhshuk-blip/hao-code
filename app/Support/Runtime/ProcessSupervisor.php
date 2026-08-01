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
        bool $loginShell = true,
    ): array {
        $path = $env['PATH'] ?? (getenv('PATH') ?: '');
        $bash = self::findExecutable('bash', $path);
        if ($bash === null) {
            throw new \RuntimeException('Bash executable was not found on PATH; Bash-based tools require bash to run commands.');
        }

        // Prefer setsid so the child is a session/group leader and can be
        // signalled as a whole tree via kill(-pid, sig). Without setsid,
        // enable job control and an EXIT trap that kills the shell's group.
        $setsid = self::findExecutable('setsid', $path);
        $shellFlag = $loginShell ? '-lc' : '-c';
        if ($setsid !== null) {
            $cmd = [$setsid, $bash, $shellFlag, $commandScript];
        } else {
            $guarded = 'set -m; '
                .'trap \'status=$?; kill -TERM -$$ 2>/dev/null; kill -KILL -$$ 2>/dev/null; exit $status\' EXIT INT TERM HUP; '
                .$commandScript;
            $cmd = [$bash, $shellFlag, $guarded];
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
            // Snapshot descendants before signalling the root. The fallback
            // launcher may not have its own process group; once the root exits
            // its children can be re-parented and no longer discoverable via
            // pgrep -P.
            $hasOwnProcessGroup = function_exists('posix_getpgid')
                && @posix_getpgid($pid) === $pid;
            $descendants = $hasOwnProcessGroup ? [] : self::collectDescendantPids($pid);
            self::signalPids($descendants, $sig);
            @posix_kill(-$pid, $sig);
            @posix_kill($pid, $sig);
            if (! $force) {
                usleep(150_000);
                self::signalPids($descendants, $killSig);
                @posix_kill(-$pid, $killSig);
                @posix_kill($pid, $killSig);
            }

            return;
        }

        // Best-effort without posix.
        if (PHP_OS_FAMILY === 'Windows') {
            self::runCommand(['taskkill', '/F', '/T', '/PID', (string) $pid]);
        } else {
            self::runCommand(['kill', '-'.(int) $sig, '-'.(int) $pid]);
            self::runCommand(['kill', '-'.(int) $sig, (string) $pid]);
            self::killDescendants($pid, $sig);
            if (! $force) {
                usleep(150_000);
                self::runCommand(['kill', '-9', '-'.(int) $pid]);
                self::runCommand(['kill', '-9', (string) $pid]);
                self::killDescendants($pid, $killSig);
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function collectDescendantPids(int $pid, array &$seen = []): array
    {
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows' || isset($seen[$pid])) {
            return [];
        }

        $seen[$pid] = true;
        $result = self::runCommand(['pgrep', '-P', (string) $pid]);
        $raw = $result['stdout'];
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $descendants = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $child) {
            if (! ctype_digit($child)) {
                continue;
            }
            $childPid = (int) $child;
            if ($childPid <= 0 || isset($seen[$childPid])) {
                continue;
            }
            $descendants[] = $childPid;
            $descendants = array_merge(
                $descendants,
                self::collectDescendantPids($childPid, $seen),
            );
        }

        return array_values(array_unique($descendants));
    }

    /** @param list<int> $pids */
    private static function signalPids(array $pids, int $signal): void
    {
        foreach (array_reverse($pids) as $pid) {
            if ($pid > 0) {
                @posix_kill($pid, $signal);
            }
        }
    }

    private static function killDescendants(int $pid, int $sig): void
    {
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $result = self::runCommand(['pgrep', '-P', (string) $pid]);
        $raw = $result['stdout'];
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
                self::runCommand(['kill', '-'.(int) $sig, (string) $childPid]);
            }
        }
    }

    /**
     * @param list<string> $argv
     * @return array{exitCode: int, stdout: string}
     */
    private static function runCommand(array $argv): array
    {
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            $argv,
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
            return ['exitCode' => -1, 'stdout' => ''];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $deadline = microtime(true) + 0.5;
        $stdout = '';
        $exitCode = -1;
        while (true) {
            foreach ([1, 2] as $index) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = stream_get_contents($pipes[$index]);
                if ($index === 1 && is_string($chunk) && $chunk !== '') {
                    $stdout .= $chunk;
                    if (strlen($stdout) > 8192) {
                        $stdout = substr($stdout, 0, 8192);
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
                        $stdout .= $chunk;
                        if (strlen($stdout) > 8192) {
                            $stdout = substr($stdout, 0, 8192);
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

        return ['exitCode' => $exitCode, 'stdout' => $stdout];
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
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return self::findExecutable('setsid', getenv('PATH') ?: '') !== null;
    }

    private static function findExecutable(string $name, string $path): ?string
    {
        $extensions = PHP_OS_FAMILY === 'Windows'
            ? array_values(array_unique(array_filter(array_merge([''], explode(';', getenv('PATHEXT') ?: '.EXE;.BAT;.CMD')))))
            : [''];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name.$extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
