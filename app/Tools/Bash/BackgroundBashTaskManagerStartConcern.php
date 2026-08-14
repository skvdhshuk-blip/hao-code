<?php

namespace HaoCode\Tools\Bash;

use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolResult;

trait BackgroundBashTaskManagerStartConcern
{

    public static function start(
        string $command,
        string $cwd,
        array $warnings,
        float $timeoutSeconds,
        array $env,
    ): ToolResult
    {
        self::pruneBackgroundTasks();

        if (count(self::$backgroundTasks) >= self::BACKGROUND_TASK_MAX) {
            return ToolResult::error(
                'Too many in-process background Bash tasks (max '.self::BACKGROUND_TASK_MAX.'). '
                .'Wait for existing tasks to finish or call checkTask() to reclaim them.',
            );
        }

        $taskId = 'bg_' . bin2hex(random_bytes(4));
        $outFile = tempnam(sys_get_temp_dir(), 'haocode_bg_');
        $statusFile = tempnam(sys_get_temp_dir(), 'haocode_bgs_');
        if ($outFile === false || $statusFile === false) {
            if (is_string($outFile)) {
                @unlink($outFile);
            }
            if (is_string($statusFile)) {
                @unlink($statusFile);
            }

            return ToolResult::error("Failed to allocate output file for background command: {$command}");
        }
        // Status file is written only when the job exits; start empty so readers
        // can distinguish "still running" from "exit 0".
        @unlink($statusFile);

        $payloadFile = tempnam(sys_get_temp_dir(), 'haocode_bgp_');
        if ($payloadFile === false) {
            @unlink($outFile);
            @unlink($statusFile);

            return ToolResult::error("Failed to allocate supervisor payload for background command: {$command}");
        }
        $payload = [
            'command' => $command,
            'cwd' => $cwd,
            'env' => $env,
            'outFile' => $outFile,
            'statusFile' => $statusFile,
            'timeoutSeconds' => $timeoutSeconds,
            'maxOutputBytes' => self::MAX_CAPTURED_OUTPUT_BYTES,
        ];
        if (@file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            @unlink($payloadFile);
            @unlink($outFile);
            @unlink($statusFile);

            return ToolResult::error("Failed to write supervisor payload for background command: {$command}");
        }
        @chmod($payloadFile, 0600);

        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $supervisorCode = 'require '.var_export($autoload, true).'; '
            .'exit(\\HaoCode\\Tools\\Bash\\BackgroundBashSupervisor::runFromPayloadFile($argv[1] ?? ""));';

        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        ];

        $process = @proc_open([PHP_BINARY, '-r', $supervisorCode, $payloadFile], $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            @unlink($payloadFile);
            @unlink($outFile);
            @unlink($statusFile);

            return ToolResult::error("Failed to start background command: {$command}");
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);

        if ($pid <= 0) {
            @proc_close($process);
            @unlink($payloadFile);
            @unlink($outFile);
            @unlink($statusFile);

            return ToolResult::error("Failed to start background command: {$command}");
        }

        // Capture a process start token immediately so PID reuse can be detected later.
        $startToken = self::processStartToken($pid);

        self::$backgroundTasks[$taskId] = [
            'pid' => $pid,
            'process' => $process,
            'outFile' => $outFile,
            'statusFile' => $statusFile,
            'payloadFile' => $payloadFile,
            'startTime' => microtime(true),
            'deadline' => microtime(true) + $timeoutSeconds,
            'startToken' => $startToken,
            'command' => $command,
        ];

        $result = "Background task started: {$taskId} (PID: {$pid})\n";
        $result .= "Command: {$command}\n";
        $result .= 'Status is process-local (not durable across PHP restarts). '
            .'Use BashTool::checkTask() / checkAllTasks() to poll.';

        if (!empty($warnings)) {
            $result = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n" . $result;
        }

        return ToolResult::success($result, [
            'taskId' => $taskId,
            'pid' => $pid,
            'running' => true,
            'timedOut' => false,
            'outputLimited' => false,
        ]);
    }

    public static function checkTask(string $taskId): ?ToolResult
    {
        self::pruneBackgroundTasks();

        $task = self::$backgroundTasks[$taskId] ?? null;
        if ($task === null) {
            return ToolResult::error(
                "Unknown background task: {$taskId}. "
                .'It may have already been reaped, expired (TTL '
                .self::BACKGROUND_TASK_TTL_SECONDS.'s), or never started in this process.',
            );
        }

        $statusPath = is_string($task['statusFile'] ?? null) ? $task['statusFile'] : '';
        $statusInfo = self::readBackgroundStatus($statusPath);
        $exitCode = $statusInfo['exitCode'] ?? null;
        $running = self::isBackgroundProcessAlive($task);
        $deadline = (float) ($task['deadline'] ?? 0.0);
        $now = microtime(true);
        // A late poll must not turn an already completed non-zero command into
        // a timeout. The supervisor records the authoritative outcome; the
        // wall-clock deadline is only a fallback while it is still running.
        $timedOut = (bool) ($statusInfo['timedOut'] ?? false);
        if ($exitCode === null) {
            $timedOut = $deadline > 0.0 && $now >= $deadline;
        }

        // The supervisor owns the exact timeout and writes 124 after it has
        // terminated the command tree. Give it a short grace window before the
        // poller treats the supervisor itself as stuck.
        if ($exitCode === null && $running === true && $timedOut && $now >= $deadline + 1.0) {
            ProcessSupervisor::terminateTree((int) $task['pid'], false);
            $running = self::isBackgroundProcessAlive($task);
            if ($running !== true) {
                $statusInfo = self::readBackgroundStatus($statusPath);
                $exitCode = $statusInfo['exitCode'] ?? 124;
                // Fall through to harvest as a timed-out completion.
                $timedOut = true;
            }
        }

        if ($exitCode === null && $running === true) {
            $elapsed = round(microtime(true) - $task['startTime'], 1);

            return ToolResult::success(
                "Task {$taskId} still running (PID: {$task['pid']}, {$elapsed}s elapsed)",
                [
                    'taskId' => $taskId,
                    'pid' => $task['pid'],
                    'running' => true,
                    'elapsed' => $elapsed,
                    'timedOut' => false,
                    'outputLimited' => false,
                ],
            );
        }

        if ($exitCode === null && $running === null) {
            // No posix probe and no status file yet — do not pretend completed.
            return ToolResult::error(
                "Task {$taskId} status is unknown: ext-posix is unavailable and the exit status "
                .'file has not been written yet. Cannot treat the task as completed.',
                ['taskId' => $taskId, 'pid' => $task['pid'], 'running' => null, 'status' => 'unknown'],
            );
        }

        if ($exitCode === null && $running === false) {
            // The supervisor writes the status atomically immediately before
            // it exits. proc_get_status() can observe that exit a few ticks
            // earlier than the parent sees the renamed file; never turn that
            // race into a fabricated exit code -1 or timeout.
            for ($attempt = 0; $attempt < 5 && $exitCode === null; $attempt++) {
                usleep(10_000);
                $statusInfo = self::readBackgroundStatus($statusPath);
                $exitCode = $statusInfo['exitCode'] ?? null;
            }
            if ($exitCode === null) {
                return ToolResult::error(
                    "Task {$taskId} status is unknown: supervisor exited before its authoritative "
                    .'status file became visible. Retry checkTask() to harvest the final result.',
                    ['taskId' => $taskId, 'pid' => $task['pid'], 'running' => false, 'status' => 'unknown'],
                );
            }
            // The status file is authoritative once it becomes visible. In
            // particular, do not retain the wall-clock fallback above when a
            // successful supervisor completion wins this race.
            $timedOut = (bool) ($statusInfo['timedOut'] ?? false);
        }

        // Process finished (or status file present) — harvest output + exit code.
        $output = '(no output)';
        if (is_string($task['outFile']) && file_exists($task['outFile'])) {
            $size = @filesize($task['outFile']);
            $maxBytes = self::MAX_CAPTURED_OUTPUT_BYTES;
            if ($size !== false && $size > $maxBytes) {
                $fh = @fopen($task['outFile'], 'rb');
                $raw = $fh !== false ? (string) fread($fh, $maxBytes) : false;
                if (is_resource($fh)) {
                    fclose($fh);
                }
                $output = ($raw === false || $raw === '')
                    ? '(failed to read task output file)'
                    : $raw."\n\n[Output truncated at 100,000 bytes]";
            } else {
                $raw = @file_get_contents($task['outFile']);
                if ($raw === false) {
                    $output = '(failed to read task output file)';
                } elseif ($raw !== '') {
                    $output = $raw;
                }
            }
            @unlink($task['outFile']);
        }
        if ($statusPath !== '' && file_exists($statusPath)) {
            @unlink($statusPath);
        }
        if (is_string($task['payloadFile'] ?? null) && file_exists($task['payloadFile'])) {
            @unlink($task['payloadFile']);
        }
        if (isset($task['process']) && is_resource($task['process'])) {
            @proc_close($task['process']);
        }
        unset(self::$backgroundTasks[$taskId]);

        $code = $timedOut ? 124 : ($exitCode ?? -1);
        $meta = [
            'taskId' => $taskId,
            'pid' => $task['pid'],
            'running' => false,
            'exitCode' => $code,
            'timedOut' => $timedOut && $code !== 0,
            'outputLimited' => (bool) ($statusInfo['outputLimited'] ?? false),
        ];

        if ($timedOut && $code !== 0) {
            return ToolResult::error(
                "Task {$taskId} timed out:\n{$output}",
                $meta,
            );
        }

        if ($code === 0) {
            return ToolResult::success("Task {$taskId} completed:\n{$output}", $meta);
        }

        return ToolResult::error(
            "Task {$taskId} failed with exit code {$code}:\n{$output}",
            $meta,
        );
    }

    /**
     * Check all background tasks.
     * @return array<string, ToolResult>
     */
    public static function checkAllTasks(): array
    {
        self::pruneBackgroundTasks();
        $results = [];
        foreach (array_keys(self::$backgroundTasks) as $taskId) {
            $results[$taskId] = self::checkTask($taskId);
        }
        return $results;
    }

    /**
     * List tracked background tasks (after TTL / PID-reuse pruning).
     *
     * @return array<string, array{pid: int, outFile: string, statusFile: string, startTime: float, startToken: ?string, command: string}>
     */
    public static function listTasks(): array
    {
        self::pruneBackgroundTasks();

        return array_map(
            static fn (array $task): array => [
                'pid' => (int) ($task['pid'] ?? 0),
                'outFile' => (string) ($task['outFile'] ?? ''),
                'statusFile' => (string) ($task['statusFile'] ?? ''),
                'startTime' => (float) ($task['startTime'] ?? 0.0),
                'startToken' => is_string($task['startToken'] ?? null) ? $task['startToken'] : null,
                'command' => (string) ($task['command'] ?? ''),
            ],
            self::$backgroundTasks,
        );
    }

    /**
     * Drop only TTL-expired bookkeeping. Finished-but-uncollected tasks stay
     * until {@see checkTask()} harvests their output.
     */
    private static function pruneBackgroundTasks(): void
    {
        $now = microtime(true);
        foreach (self::$backgroundTasks as $taskId => $task) {
            $age = $now - (float) ($task['startTime'] ?? 0);
            if ($age <= self::BACKGROUND_TASK_TTL_SECONDS) {
                continue;
            }

            $alive = self::isBackgroundProcessAlive($task);
            if ($alive === true) {
                // Soft-stop the orphan process group, not just the launcher PID.
                ProcessSupervisor::terminateTree((int) $task['pid'], false);
            }

            if (is_string($task['outFile'] ?? null) && file_exists($task['outFile'])) {
                @unlink($task['outFile']);
            }
            if (is_string($task['statusFile'] ?? null) && file_exists($task['statusFile'])) {
                @unlink($task['statusFile']);
            }
            if (is_string($task['payloadFile'] ?? null) && file_exists($task['payloadFile'])) {
                @unlink($task['payloadFile']);
            }
            if (isset($task['process']) && is_resource($task['process'])) {
                @proc_close($task['process']);
            }
            unset(self::$backgroundTasks[$taskId]);
        }
    }

    /**
     * @return array{exitCode: int, timedOut: bool, outputLimited: bool}|null
     */
    private static function readBackgroundStatus(string $statusPath): ?array
    {
        if ($statusPath === '' || ! is_file($statusPath)) {
            return null;
        }
        $raw = @file_get_contents($statusPath);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && is_numeric($decoded['exitCode'] ?? null)) {
            return [
                'exitCode' => (int) $decoded['exitCode'],
                'timedOut' => (bool) ($decoded['timedOut'] ?? false),
                'outputLimited' => (bool) ($decoded['outputLimited'] ?? false),
            ];
        }

        // Read status files written by an older in-process supervisor. Code
        // 124 was the only timeout marker in that format.
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            return null;
        }
        $exitCode = (int) $raw;

        return [
            'exitCode' => $exitCode,
            'timedOut' => $exitCode === 124,
            'outputLimited' => false,
        ];
    }

    /**
     * @param  array{pid: int, process?: resource, startToken?: ?string, startTime?: float}  $task
     * @return bool|null true=alive, false=dead, null=unknown (no posix probe)
     */
    private static function isBackgroundProcessAlive(array $task): ?bool
    {
        $pid = (int) ($task['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }

        if (isset($task['process']) && is_resource($task['process'])) {
            $status = @proc_get_status($task['process']);
            if (is_array($status) && ! ($status['running'] ?? false)) {
                return false;
            }
        }

        if (! function_exists('posix_kill')) {
            return null;
        }

        // Signal 0 probes existence without delivering a signal.
        if (! @posix_kill($pid, 0)) {
            return false;
        }

        $expected = $task['startToken'] ?? null;
        if (! is_string($expected) || $expected === '') {
            // No token captured at spawn — fall back to signal-0 only.
            return true;
        }

        $current = self::processStartToken($pid);
        // If we can no longer read a token but the PID still signals alive,
        // keep treating it as alive (platform may hide process metadata).
        if ($current === null) {
            return true;
        }

        // PID reuse: same pid number, different start identity.
        return $current === $expected;
    }

    /**
     * Platform-specific process start identity used to detect PID reuse.
     */
    private static function processStartToken(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }

        $statPath = "/proc/{$pid}/stat";
        if (@is_readable($statPath)) {
            $stat = @file_get_contents($statPath);
            if (is_string($stat) && $stat !== '') {
                // Format: pid (comm) state ppid ... starttime is field 22 after comm.
                $closeParen = strrpos($stat, ')');
                if ($closeParen !== false) {
                    $rest = trim(substr($stat, $closeParen + 1));
                    $fields = preg_split('/\s+/', $rest) ?: [];
                    // After comm: state(1) ... starttime is the 20th remaining field (index 19).
                    if (isset($fields[19]) && ctype_digit($fields[19])) {
                        return 'proc:'.$fields[19];
                    }
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $line = self::processStartLineWithPs($pid);
            if (is_string($line) && trim($line) !== '') {
                return 'ps:'.trim($line);
            }
        }

        return null;
    }
}
