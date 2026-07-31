<?php

namespace HaoCode\Tools\Bash;

use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Support\Runtime\SpawnEnvironment;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class BashTool extends BaseTool
{
    /** Default TTL for in-process background task bookkeeping (seconds). */
    private const BACKGROUND_TASK_TTL_SECONDS = 6 * 3600;

    /** Hard cap so a runaway table cannot grow without bound. */
    private const BACKGROUND_TASK_MAX = 64;

    private const MAX_CAPTURED_OUTPUT_BYTES = 100_000;

    /**
     * @var array<string, array{
     *   pid: int,
     *   process?: resource,
     *   outFile: string,
     *   statusFile: string,
     *   payloadFile?: string,
     *   startTime: float,
     *   deadline?: float,
     *   startToken: ?string,
     *   command: string
     * }>
     */
    private static array $backgroundTasks = [];
    /** @var array<string, string> */
    private static array $sessionWorkingDirectories = [];

    public function name(): string
    {
        return 'Bash';
    }

    /** @internal */
    public static function setSessionWorkingDirectory(string $sessionId, string $workingDirectory): void
    {
        self::$sessionWorkingDirectories[$sessionId] = $workingDirectory;
    }

    public function description(): string
    {
        return <<<DESC
Executes a given bash command and returns its output.

The working directory persists between commands, but shell state does not.
Always quote file paths that contain spaces with double quotes.

IMPORTANT: Avoid using this tool to run `find`, `grep`, `cat`, `head`, `tail`, `sed`, `awk`, or `echo` commands, unless explicitly instructed or after you have verified that a dedicated tool cannot accomplish your task.

Usage notes:
 - If your command will create new directories or files, first use this tool to run `ls` to verify the parent directory exists.
 - Always quote file paths that contain spaces with double quotes.
 - Try to maintain your current working directory throughout the session by using absolute paths.
 - Do not spend tool calls on availability probes or shell no-ops like `: > /dev/null 2>&1` or `true`, and do not start commands with `:`; run the real command directly.
 - Keep Bash commands short and concrete. Do not embed large heredocs, inline python/node scripts, base64 blobs, or long printf file-generation payloads in a single Bash call.
 - You may specify an optional timeout in milliseconds (up to 600000ms / 10 minutes). Default timeout is 120000ms (2 minutes).
 - Use the `run_in_background` parameter to run the command in the background.
 - Write a clear, concise description of what your command does.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The command to execute',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise description of what this command does (5-10 words)',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Optional timeout in milliseconds (max 600000)',
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Run the command in the background (process-local; not durable across PHP restarts)',
                ],
            ],
            'required' => ['command'],
        ], [
            'command' => 'required|string',
            'description' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1000|max:600000',
            'run_in_background' => 'nullable|boolean',
        ]);
    }

    public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision
    {
        $command = $input['command'] ?? '';
        if (! is_string($command)) {
            return PermissionDecision::allow();
        }

        if (SensitivePathGuard::requiresShellPathReview($command)) {
            return PermissionDecision::ask(
                'Read command uses dynamic shell path expansion and requires approval.',
            );
        }
        foreach (SensitivePathGuard::splitShellSegments($command) as $segment) {
            $reason = ReadOnlyCommandSafety::mutationReason($segment);
            if ($reason !== null) {
                return PermissionDecision::ask($reason);
            }
        }

        return PermissionDecision::allow();
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $command = $input['command'];
        $background = $input['run_in_background'] ?? false;

        // Removed from the public schema: accepting it as a no-op would be
        // misleading; reject explicitly if a client still sends it.
        if (array_key_exists('dangerouslyDisableSandbox', $input)) {
            return ToolResult::error(
                'dangerouslyDisableSandbox is not supported. Bash always runs under the '
                .'active permission mode / sandbox boundary; use permissionMode or SandboxConfig instead.',
            );
        }

        if ($context->isAborted()) {
            return ToolResult::error('Command interrupted by user.', [
                'exitCode' => 130,
                'aborted' => true,
            ]);
        }

        // Check for dangerous patterns
        $warnings = $this->detectDangerousPatterns($command);

        $timeout = ($input['timeout'] ?? 120000) / 1000;
        $cwd = self::$sessionWorkingDirectories[$context->sessionId] ?? $context->workingDirectory;
        try {
            $env = SpawnEnvironment::forCommand(
                $context->runContext?->settings,
                $this->name(),
                $command,
                $cwd,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to construct policy-safe command environment: '.$e->getMessage());
        }

        if ($background) {
            return $this->runInBackground($command, $cwd, $warnings, $timeout, $env);
        }

        $stdoutFile = tempnam(sys_get_temp_dir(), 'haocode_bash_stdout_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'haocode_bash_stderr_');

        if ($stdoutFile === false || $stderrFile === false) {
            if (is_string($stdoutFile) && file_exists($stdoutFile)) {
                @unlink($stdoutFile);
            }
            if (is_string($stderrFile) && file_exists($stderrFile)) {
                @unlink($stderrFile);
            }

            return ToolResult::error('Failed to allocate temporary files for command output.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            // Use files instead of pipes so foreground commands that launch
            // background children with `&` do not keep the tool waiting for EOF.
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $cwdMarker = '__HAOCODE_CWD__' . bin2hex(random_bytes(8)) . '__';
        $wrappedCommand = $this->wrapCommandWithWorkingDirectoryCapture($command, $cwdMarker);
        try {
            $opened = ProcessSupervisor::open($wrappedCommand, $cwd, $env, $descriptors);
        } catch (\Throwable) {
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return ToolResult::error("Failed to execute command: {$command}");
        }

        $process = $opened['process'];
        $pid = $opened['pid'];

        $stdoutHandle = fopen($stdoutFile, 'r');
        $stderrHandle = fopen($stderrFile, 'r');

        if (!is_resource($stdoutHandle) || !is_resource($stderrHandle)) {
            if (is_resource($stdoutHandle)) {
                fclose($stdoutHandle);
            }
            if (is_resource($stderrHandle)) {
                fclose($stderrHandle);
            }

            ProcessSupervisor::terminateTree($pid, true);
            @proc_close($process);
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return ToolResult::error("Failed to capture command output: {$command}");
        }

        $stdout = '';
        $stderr = '';
        $deadline  = microtime(true) + $timeout;
        $timedOut  = false;
        $aborted = false;
        $drainFailed = false;
        $outputTruncated = false;
        $maxOutputChars = self::MAX_CAPTURED_OUTPUT_BYTES;
        $status = ['running' => true, 'exitcode' => -1];

        while (true) {
            if ($context->isAborted()) {
                $aborted = true;
                ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            [$stdoutChunk, $stdoutFailed] = $this->drainPipe($stdoutHandle);
            [$stderrChunk, $stderrFailed] = $this->drainPipe($stderrHandle);
            // Cap retained output during the run to bound PHP memory; still drain pipes.
            if (mb_strlen($stdout) < $maxOutputChars) {
                $room = $maxOutputChars - mb_strlen($stdout);
                if (mb_strlen($stdoutChunk) > $room) {
                    $stdout .= mb_substr($stdoutChunk, 0, $room);
                    $outputTruncated = true;
                } else {
                    $stdout .= $stdoutChunk;
                }
            } elseif ($stdoutChunk !== '') {
                $outputTruncated = true;
            }
            if (mb_strlen($stderr) < $maxOutputChars) {
                $room = $maxOutputChars - mb_strlen($stderr);
                if (mb_strlen($stderrChunk) > $room) {
                    $stderr .= mb_substr($stderrChunk, 0, $room);
                    $outputTruncated = true;
                } else {
                    $stderr .= $stderrChunk;
                }
            } elseif ($stderrChunk !== '') {
                $outputTruncated = true;
            }
            $drainFailed = $drainFailed || $stdoutFailed || $stderrFailed;

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            // Poll up to 200 ms so we stay responsive without busy waiting.
            usleep((int) min($remaining * 1_000_000, 200_000));
        }

        [$stdoutChunk, $stdoutFailed] = $this->drainPipe($stdoutHandle);
        [$stderrChunk, $stderrFailed] = $this->drainPipe($stderrHandle);
        $stdout .= $stdoutChunk;
        $stderr .= $stderrChunk;
        $drainFailed = $drainFailed || $stdoutFailed || $stderrFailed;

        [$stdout, $capturedWorkingDirectory] = $this->extractWorkingDirectoryMarker($stdout, $cwdMarker);

        fclose($stdoutHandle);
        fclose($stderrHandle);

        // On PHP < 8.4, proc_get_status() reaps the child via waitpid(WNOHANG),
        // so a subsequent proc_close() returns -1.  Capture the exit code from
        // the status array while it is still available.
        $exitCode = ($status['signaled'] ?? false)
            ? 128 + (int) ($status['termsig'] ?? 0)
            : (int) ($status['exitcode'] ?? -1);
        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $aborted) {
            $exitCode = $closed;
        }
        @unlink($stdoutFile);
        @unlink($stderrFile);

        if ($aborted) {
            $partial = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            $message = 'Command interrupted by user.';
            if ($partial !== '') {
                $message .= "\nPartial output:\n{$partial}";
            }

            return ToolResult::error($message, [
                'exitCode' => 130,
                'aborted' => true,
            ]);
        }

        if ($timedOut) {
            $partial = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            $partialNote = $partial ? "\nPartial output:\n{$partial}" : '';
            return ToolResult::error(
                "Command timed out after {$timeout}s.{$partialNote}",
                ['exitCode' => -1, 'timedOut' => true],
            );
        }

        if ($capturedWorkingDirectory !== null && is_dir($capturedWorkingDirectory)) {
            self::$sessionWorkingDirectories[$context->sessionId] = $capturedWorkingDirectory;
        }

        $output = '';
        if (!empty($stdout)) {
            $output .= $stdout;
        }
        if (!empty($stderr)) {
            if (!empty($output)) {
                $output .= "\n";
            }
            $output .= $stderr;
        }

        if (empty($output)) {
            $output = '(no output)';
        }

        if ($drainFailed) {
            $output .= "\n\n[warning: one or more stream reads failed while capturing command output]";
        }

        // Truncate very long output (also covered during drain; keep final guard).
        if ($outputTruncated || mb_strlen($output) > self::MAX_CAPTURED_OUTPUT_BYTES) {
            $output = mb_substr($output, 0, self::MAX_CAPTURED_OUTPUT_BYTES) . "\n\n[Output truncated at 100,000 characters]";
        }

        // Prepend warnings
        if (!empty($warnings)) {
            $warningText = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n";
            $output = $warningText . $output;
        }

        if ($exitCode !== 0) {
            // Check if this exit code is semantically non-error for the command
            $exitContext = $this->interpretExitCode($command, $exitCode, $output);

            if ($exitContext['isExpected'] ?? false) {
                // Not a real error, just a semantic exit (e.g., grep found no matches)
                return ToolResult::success(
                    $output . "\n" . ($exitContext['note'] ?? ''),
                    ['exitCode' => $exitCode]
                );
            }

            return ToolResult::error(
                "Command exited with code {$exitCode}\n{$output}" . ($exitContext['note'] ? "\n" . $exitContext['note'] : ''),
                ['exitCode' => $exitCode]
            );
        }

        return ToolResult::success($output, ['exitCode' => $exitCode]);
    }

    private function wrapCommandWithWorkingDirectoryCapture(string $command, string $cwdMarker): string
    {
        $script = $command . "\n" .
            "__haocode_status=\$?\n" .
            "printf '\\n{$cwdMarker}%s' \"\$PWD\"\n" .
            "exit \$__haocode_status";

        return 'bash -lc ' . escapeshellarg($script);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function extractWorkingDirectoryMarker(string $stdout, string $cwdMarker): array
    {
        $markerPos = strrpos($stdout, $cwdMarker);
        if ($markerPos === false) {
            return [$stdout, null];
        }

        $capturedWorkingDirectory = substr($stdout, $markerPos + strlen($cwdMarker));
        $stdout = substr($stdout, 0, $markerPos);
        if (str_ends_with($stdout, "\n")) {
            $stdout = substr($stdout, 0, -1);
        }

        return [$stdout, trim($capturedWorkingDirectory)];
    }

    /**
     * Run a command in the background.
     */
    private function runInBackground(
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

        return ToolResult::success($result, ['taskId' => $taskId, 'pid' => $pid]);
    }

    /**
     * Check if a background task has completed.
     */
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
        $exitCode = self::readBackgroundExitCode($statusPath);
        $running = self::isBackgroundProcessAlive($task);
        $deadline = (float) ($task['deadline'] ?? 0.0);
        $now = microtime(true);
        $timedOut = $deadline > 0.0 && $now >= $deadline;

        // The supervisor owns the exact timeout and writes 124 after it has
        // terminated the command tree. Give it a short grace window before the
        // poller treats the supervisor itself as stuck.
        if ($exitCode === null && $running === true && $timedOut && $now >= $deadline + 1.0) {
            ProcessSupervisor::terminateTree((int) $task['pid'], false);
            $running = self::isBackgroundProcessAlive($task);
            if ($running !== true) {
                $exitCode = self::readBackgroundExitCode($statusPath) ?? -1;
                // Fall through to harvest as a timed-out completion.
                $timedOut = true;
            }
        }

        if ($exitCode === null && $running === true) {
            $elapsed = round(microtime(true) - $task['startTime'], 1);

            return ToolResult::success(
                "Task {$taskId} still running (PID: {$task['pid']}, {$elapsed}s elapsed)",
                ['taskId' => $taskId, 'pid' => $task['pid'], 'running' => true, 'elapsed' => $elapsed],
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

        $code = $exitCode ?? -1;
        $meta = [
            'taskId' => $taskId,
            'pid' => $task['pid'],
            'running' => false,
            'exitCode' => $code,
            'timedOut' => $timedOut && $code !== 0,
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
     * Drain any output already written to a capture stream without waiting for EOF.
     *
     * @param  resource  $pipe
     * @return array{0: string, 1: bool} [chunk, readFailed]
     */
    private function drainPipe($pipe): array
    {
        if (! is_resource($pipe)) {
            return ['', true];
        }

        $chunk = @stream_get_contents($pipe);
        if ($chunk === false) {
            return ['', true];
        }

        return [$chunk, false];
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

    private static function readBackgroundExitCode(string $statusPath): ?int
    {
        if ($statusPath === '' || ! is_file($statusPath)) {
            return null;
        }
        $raw = @file_get_contents($statusPath);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^-?\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
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
            $line = @shell_exec('ps -p '.((int) $pid).' -o lstart= 2>/dev/null');
            if (is_string($line) && trim($line) !== '') {
                return 'ps:'.trim($line);
            }
        }

        return null;
    }

    /**
     * Detect dangerous command patterns and return warnings.
     * @return string[]
     */
    private function detectDangerousPatterns(string $command): array
    {
        $warnings = [];

        // Patterns: [regex, warning message]
        $patterns = [
            '/\brm\s+(-[a-zA-Z]*f[a-zA-Z]*\s+|.*--recursive\b)/i' => 'WARNING: Recursive/force delete detected. Ensure paths are correct.',
            '/\bgit\s+push\s+.*--force/i' => 'WARNING: Force push can overwrite remote history. Consider --force-with-lease.',
            '/\bgit\s+reset\s+--hard/i' => 'WARNING: Hard reset will discard uncommitted changes.',
            '/\bgit\s+checkout\s+\./' => 'WARNING: This will discard all working directory changes.',
            '/\bgit\s+clean\s+(-[a-zA-Z]*f|-fd)/i' => 'WARNING: This will permanently delete untracked files.',
            '/\bDROP\s+(TABLE|DATABASE|SCHEMA)/i' => 'WARNING: Destructive SQL operation detected.',
            '/\bsudo\s+/' => 'WARNING: Command requires elevated privileges.',
            '/\bchmod\s+(000|777)\b/' => 'WARNING: Insecure file permissions.',
            '/\bdd\s+/' => 'WARNING: dd command can destroy data.',
            '/\b(:\(\)\{.*;\}\s*;)/' => 'WARNING: Potential fork bomb detected.',
            '/\b>\s*\/dev\/(s|h)d/' => 'WARNING: Writing directly to disk device.',
            '/\bcurl\s+.*\|\s*(ba)?sh/' => 'WARNING: Piping curl output to shell is potentially dangerous.',
            '/\brm\s+--no-preserve-root/' => 'WARNING: Attempting to remove root filesystem.',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $command)) {
                $warnings[] = $message;
            }
        }

        return $warnings;
    }

    /**
     * Interpret exit code semantics for known commands.
     * Returns context about whether the exit code is expected or has special meaning.
     */
    private function interpretExitCode(string $command, int $exitCode, string $output): array
    {
        // Extract the base command (first word)
        $baseCommand = preg_split('/\s+/', ltrim($command))[0] ?? '';
        $baseCommand = basename($baseCommand);

        // grep/rg exit code 1 = no matches found (not an error)
        if (in_array($baseCommand, ['grep', 'rg']) && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means no matches were found — this is not an error.]',
            ];
        }

        // diff exit code 1 = files differ (not an error)
        if ($baseCommand === 'diff' && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means the files differ — this is not an error.]',
            ];
        }

        // test exit code 1 = condition evaluated to false
        if ($baseCommand === 'test' && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means the test condition was false — this is not an error.]',
            ];
        }

        // which/where/whereis exit code 1 = command not found
        if (in_array($baseCommand, ['which', 'where', 'whereis', 'command']) && $exitCode === 1) {
            return [
                'isExpected' => false,
                'note' => '[Note: The command was not found in PATH.]',
            ];
        }

        // git merge exit code 1 = merge conflict
        if ($baseCommand === 'git' && $exitCode === 1) {
            if (preg_match('/\bmerge\b/', $command)) {
                return [
                    'isExpected' => false,
                    'note' => '[Note: Merge conflict detected. Resolve conflicts and commit.]',
                ];
            }
        }

        // timeout exit code 124 = timed out
        if ($baseCommand === 'timeout' && $exitCode === 124) {
            return [
                'isExpected' => false,
                'note' => '[Note: Command timed out.]',
            ];
        }

        // curl exit code 7 = connection refused, 22 = HTTP error
        if ($baseCommand === 'curl' && $exitCode === 7) {
            return [
                'isExpected' => false,
                'note' => '[Note: Connection refused. Is the server running?]',
            ];
        }

        if ($baseCommand === 'curl' && $exitCode === 22) {
            return [
                'isExpected' => false,
                'note' => '[Note: Server returned an HTTP error (4xx/5xx). Check the URL and authentication.]',
            ];
        }

        return ['isExpected' => false, 'note' => null];
    }

    /**
     * Detect if a command is read-only (safe for auto-approval).
     */
    public function isReadOnlyCommand(string $command): bool
    {
        if ($this->hasWriteSideEffects($command)) {
            return false;
        }

        $readOnlyPatterns = [
            '/^\s*(cat|head|tail|less|more|wc|sort|uniq|cut|tr|tee)\b/',
            '/^\s*(ls|find|locate|which|whereis|file|stat|du|df)\b/',
            '/^\s*(grep|rg|ag|ack|fgrep|egrep)\b/',
            '/^\s*(git\s+(status|log|diff|branch|tag|remote|show|blame|rev-parse|ls-files|ls-tree))\b/',
            '/^\s*(echo|print|printf)\b/',
            '/^\s*(php\s+(-v|-m|-i|-r\s+echo|artisan\s+(--version|about|env|list|route:list)))\b/',
            '/^\s*(composer\s+(show|info|outdated|check))\b/',
            '/^\s*(node\s+(-v|--version))\b/',
            '/^\s*(npm\s+(list|ls|view|info|outdated))\b/',
            '/^\s*(curl\s+-s\b.*\b(-I|--head|-o\s*\/dev\/null))\b/',
            '/^\s*(date|uname|hostname|whoami|id|pwd|basename|dirname|realpath)\b/',
            '/^\s*(test\s)/',
        ];

        $segments = SensitivePathGuard::splitShellSegments($command);
        if ($segments === []) {
            return false;
        }

        foreach ($segments as $segment) {
            if (ReadOnlyCommandSafety::mutationReason($segment) !== null) {
                return false;
            }
            $readOnly = false;
            foreach ($readOnlyPatterns as $pattern) {
                if (preg_match($pattern.'i', $segment) === 1) {
                    $readOnly = true;
                    break;
                }
            }
            if (! $readOnly) {
                return false;
            }
        }

        return true;
    }

    private function hasWriteSideEffects(string $command): bool
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return false;
        }

        $normalized = SensitivePathGuard::normalizeShellStaticText($trimmed);
        if (preg_match(
            '/(?:^|[;&|\r\n]\s*)find\b[^;&|\r\n]*(?:\s)-(?:delete|exec(?:dir)?|ok(?:dir)?|fprint(?:0)?|fprintf|fls)\b/i',
            $normalized,
        ) === 1) {
            return true;
        }

        // Treat output redirection as a write so commands like
        // `printf foo > file.txt` still require approval.
        if (preg_match(
            '/(?:^|[\s;&(])(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?>>?(?![&(])\s*\S+/i',
            $trimmed,
        ) === 1) {
            return true;
        }
        if (preg_match(
            '/(?:^|[\s;&(])(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?<>\s*\S+/i',
            $trimmed,
        ) === 1) {
            return true;
        }
        if (preg_match(
            '/(?:^|[\s;&(])(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?>&\s*(?!\d+(?:\s|$)|-(?:\s|$))\S+/i',
            $trimmed,
        ) === 1) {
            return true;
        }

        if (preg_match('/^\s*tee\b/i', $trimmed) === 1) {
            $parts = preg_split('/\s+/', $trimmed) ?: [];
            array_shift($parts);

            $expectingLiteralTargets = false;
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if ($expectingLiteralTargets) {
                    return true;
                }

                if ($part === '--') {
                    $expectingLiteralTargets = true;
                    continue;
                }

                if (str_starts_with($part, '-')) {
                    continue;
                }

                return true;
            }

            return false;
        }

        return false;
    }

    public function isReadOnly(array $input): bool
    {
        return $this->isReadOnlyCommand($input['command'] ?? '');
    }

    public function isConcurrencySafe(array $input): bool
    {
        $command = $input['command'] ?? '';
        // Read-only commands that only pipe to other read commands are safe
        return $this->isReadOnly($input) && CommandClassifier::isConcurrencySafe($command);
    }

    /**
     * Classify this command for UI display (collapsible search/read results).
     *
     * @return array{isSearch: bool, isRead: bool, isList: bool}
     */
    public function classifyCommand(string $command): array
    {
        return CommandClassifier::classify($command);
    }

    public function maxResultSizeChars(): int
    {
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        $desc = $input['description'] ?? null;
        if ($desc !== null && trim($desc) !== '') {
            return $desc;
        }

        $cmd = $input['command'] ?? '';
        $base = preg_split('/\s+/', trim($cmd))[0] ?? '';

        return 'Running ' . basename($base);
    }

    public function isSearchOrReadCommand(array $input): array
    {
        $classification = CommandClassifier::classify($input['command'] ?? '');

        return $classification;
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $command = trim((string) ($input['command'] ?? ''));

        if ($command === '') {
            return 'command must not be empty.';
        }

        if (preg_match('/^:\d*(?::\d+)?$/', $command) === 1) {
            return 'command must be a real shell command, not a placeholder like ":" or ":2".';
        }

        if ($this->hasLeadingColonPrefix($command)) {
            return 'command must not start with ":"; that is a shell no-op or malformed placeholder prefix. Run the real command directly.';
        }

        if ($this->isNoOpProbeCommand($command)) {
            return 'command must materially advance the task; do not use Bash for availability probes or shell no-ops like ":" or "true".';
        }

        $newlineCount = substr_count($command, "\n");
        if ($newlineCount > 20 || strlen($command) > 1200) {
            return 'command is too large for a single Bash call. Split it into smaller concrete commands; do not send giant heredocs, inline scripts, or long printf/base64 payloads.';
        }

        // Git safety: prevent force push to main/master
        if (preg_match('/\bgit\s+push\b.*(--force\b|-f\b)/i', $command)) {
            // Check if targeting main/master
            if (preg_match('/\b(main|master)\b/i', $command)) {
                return 'Force-pushing to main/master is not allowed. This can overwrite upstream history and affect other developers.';
            }

            // Suggest --force-with-lease
            if (!preg_match('/--force-with-lease/i', $command)) {
                return 'Consider using --force-with-lease instead of --force for safer force pushing.';
            }
        }

        // Git safety: prevent pushing directly to main/master (even without force)
        if (preg_match('/\bgit\s+push\b/i', $command)) {
            if (preg_match('/\borigin\s+(main|master)\b/i', $command) ||
                preg_match('/\borigin\b.*\b(main|master)\b/i', $command)) {
                // Allow if there's an explicit branch reference that's not main
                if (!preg_match('/\bHEAD:/i', $command)) {
                    // Soft warning - just a note, not blocking
                    return null;
                }
            }
        }

        // Git safety: prevent destructive clean on entire repo
        if (preg_match('/\bgit\s+clean\s+(-[a-zA-Z]*f[a-zA-Z]*|--force)/i', $command)) {
            if (!preg_match('/(-e\b|--exclude\b|-n\b|--dry-run\b)/i', $command)) {
                return 'git clean with force will permanently delete untracked files. Add -e to exclude important files, or use -n for dry-run first.';
            }
        }

        // Git safety: prevent reset --hard HEAD~ on public branches
        if (preg_match('/\bgit\s+reset\s+--hard\b/i', $command)) {
            return 'Hard reset will discard all uncommitted changes. Consider --soft or --mixed first, or make a backup branch.';
        }

        return null;
    }

    public function userFacingName(array $input): string
    {
        return $input['description'] ?? $input['command'] ?? 'Bash';
    }

    private function isNoOpProbeCommand(string $command): bool
    {
        return preg_match('/^(?::|true)(?:\s+(?:[12]?>{1,2}\s*\S+|[12]>&\d+))*$/i', trim($command)) === 1;
    }

    private function hasLeadingColonPrefix(string $command): bool
    {
        return str_starts_with(ltrim($command), ':');
    }
}
