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
    private const MAX_CAPTURED_OUTPUT_BYTES = 100_000;
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

        $capture = $this->allocateForegroundCaptureFiles();
        if ($capture === null) {
            return ToolResult::error('Failed to allocate temporary files for command output.');
        }

        $captureWithPipes = ($capture['usePipes'] ?? false) === true;
        $descriptors = [
            0 => ['pipe', 'r'],
            // Use file descriptors rather than proc_open pipes so foreground
            // commands that launch background children with `&` do not keep the
            // tool waiting for EOF. On POSIX the files are FIFOs, letting the
            // parent enforce the capture byte cap before data reaches disk.
            1 => $captureWithPipes ? ['pipe', 'w'] : ['file', $capture['stdoutFile'], 'w'],
            2 => $captureWithPipes ? ['pipe', 'w'] : ['file', $capture['stderrFile'], 'w'],
        ];

        $cwdMarker = '__HAOCODE_CWD__' . bin2hex(random_bytes(8)) . '__';
        $wrappedCommand = $this->wrapCommandWithWorkingDirectoryCapture($command, $cwdMarker);
        try {
            $opened = ProcessSupervisor::open($wrappedCommand, $cwd, $env, $descriptors);
        } catch (\Throwable $e) {
            $this->closeForegroundCaptureFiles($capture);

            return ToolResult::error("Failed to execute command: {$command}\n".$e->getMessage());
        }

        $process = $opened['process'];
        $pid = $opened['pid'];

        if ($captureWithPipes) {
            $capture['stdoutHandle'] = $opened['pipes'][1] ?? null;
            $capture['stderrHandle'] = $opened['pipes'][2] ?? null;
            if (! is_resource($capture['stdoutHandle']) || ! is_resource($capture['stderrHandle'])) {
                ProcessSupervisor::terminateTree($pid, true);
                foreach ($opened['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                @proc_close($process);
                $this->closeForegroundCaptureFiles($capture);

                return ToolResult::error('Failed to allocate bounded command output pipes.');
            }
            stream_set_blocking($capture['stdoutHandle'], false);
            stream_set_blocking($capture['stderrHandle'], false);
        }

        $stdout = '';
        $stderr = '';
        $deadline  = microtime(true) + $timeout;
        $timedOut  = false;
        $aborted = false;
        $drainFailed = false;
        $outputTruncated = false;
        $capturedOutputBytes = 0;
        $status = ['running' => true, 'exitcode' => -1];

        while (true) {
            if ($context->isAborted()) {
                $aborted = true;
                ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            [$stdoutChunk, $stdoutFailed] = $this->drainPipe($capture['stdoutHandle']);
            [$stderrChunk, $stderrFailed] = $this->drainPipe($capture['stderrHandle']);
            $receivedOutput = $stdoutChunk !== '' || $stderrChunk !== '';
            $limitNotice = "\n\n[Output truncated at ".self::MAX_CAPTURED_OUTPUT_BYTES.' bytes; command terminated]';
            if ($this->appendCapturedChunk($stdout, $stdoutChunk, $capturedOutputBytes, self::MAX_CAPTURED_OUTPUT_BYTES, $limitNotice)
                || $this->appendCapturedChunk($stderr, $stderrChunk, $capturedOutputBytes, self::MAX_CAPTURED_OUTPUT_BYTES, $limitNotice)) {
                $outputTruncated = true;
                ProcessSupervisor::terminateTree($pid, false);
                break;
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

            // When output is flowing, drain again immediately so a FIFO's
            // small reads cannot turn a hard byte cap into seconds of delay.
            // With no data, retain a bounded poll interval to avoid spinning.
            if ($receivedOutput) {
                usleep(1_000);
            } else {
                usleep((int) min($remaining * 1_000_000, 200_000));
            }
        }

        if (! $outputTruncated) {
            [$stdoutChunk, $stdoutFailed] = $this->drainPipe($capture['stdoutHandle']);
            [$stderrChunk, $stderrFailed] = $this->drainPipe($capture['stderrHandle']);
            $limitNotice = "\n\n[Output truncated at ".self::MAX_CAPTURED_OUTPUT_BYTES.' bytes; command terminated]';
            if ($this->appendCapturedChunk($stdout, $stdoutChunk, $capturedOutputBytes, self::MAX_CAPTURED_OUTPUT_BYTES, $limitNotice)
                || $this->appendCapturedChunk($stderr, $stderrChunk, $capturedOutputBytes, self::MAX_CAPTURED_OUTPUT_BYTES, $limitNotice)) {
                $outputTruncated = true;
                ProcessSupervisor::terminateTree($pid, false);
            }
            $drainFailed = $drainFailed || $stdoutFailed || $stderrFailed;
        }

        [$stdout, $capturedWorkingDirectory] = $this->extractWorkingDirectoryMarker($stdout, $cwdMarker);

        $this->closeForegroundCaptureFiles($capture);

        // On PHP < 8.4, proc_get_status() reaps the child via waitpid(WNOHANG),
        // so a subsequent proc_close() returns -1.  Capture the exit code from
        // the status array while it is still available.
        $exitCode = ($status['signaled'] ?? false)
            ? 128 + (int) ($status['termsig'] ?? 0)
            : (int) ($status['exitcode'] ?? -1);
        $closed = @proc_close($process);
        if ($outputTruncated) {
            $exitCode = 1;
        } elseif ($exitCode < 0 && ! $timedOut && ! $aborted) {
            $exitCode = $closed;
        }

        if ($aborted) {
            $partial = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            $message = 'Command interrupted by user.';
            if ($partial !== '') {
                $message .= "\nPartial output:\n{$partial}";
            }

            return ToolResult::error($message, [
                'exitCode' => 130,
                'aborted' => true,
                'timedOut' => false,
                'outputLimited' => false,
            ]);
        }

        if ($timedOut) {
            $partial = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            $partialNote = $partial ? "\nPartial output:\n{$partial}" : '';
            return ToolResult::error(
                "Command timed out after {$timeout}s.{$partialNote}",
                ['exitCode' => 124, 'timedOut' => true, 'outputLimited' => false],
            );
        }

        if ($outputTruncated) {
            $partial = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            if (!empty($warnings)) {
                $partial = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n" . $partial;
            }

            return ToolResult::error(
                "Command output exceeded ".self::MAX_CAPTURED_OUTPUT_BYTES." bytes and was terminated.\nPartial output:\n{$partial}",
                ['exitCode' => 1, 'timedOut' => false, 'outputLimited' => true],
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

        // Final guard for multibyte accounting differences; normal capture is byte-capped.
        if (strlen($output) > self::MAX_CAPTURED_OUTPUT_BYTES) {
            $output = substr($output, 0, self::MAX_CAPTURED_OUTPUT_BYTES) . "\n\n[Output truncated at 100,000 bytes]";
        }

        // Prepend warnings
        if (!empty($warnings)) {
            $warningText = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n";
            $output = $warningText . $output;
        }

        if ($exitCode !== 0) {
            $metadata = [
                'exitCode' => $exitCode,
                'timedOut' => false,
                'outputLimited' => false,
            ];
            // Check if this exit code is semantically non-error for the command
            $exitContext = $this->interpretExitCode($command, $exitCode, $output);

            if ($exitContext['isExpected'] ?? false) {
                // Not a real error, just a semantic exit (e.g., grep found no matches)
                return ToolResult::success(
                    $output . "\n" . ($exitContext['note'] ?? ''),
                    $metadata,
                );
            }

            return ToolResult::error(
                "Command exited with code {$exitCode}\n{$output}" . ($exitContext['note'] ? "\n" . $exitContext['note'] : ''),
                $metadata,
            );
        }

        return ToolResult::success($output, [
            'exitCode' => $exitCode,
            'timedOut' => false,
            'outputLimited' => false,
        ]);
    }

    private function wrapCommandWithWorkingDirectoryCapture(string $command, string $cwdMarker): string
    {
        $script = $command . "\n" .
            "__haocode_status=\$?\n" .
            "printf '\\n{$cwdMarker}%s' \"\$PWD\"\n" .
            "exit \$__haocode_status";

        // ProcessSupervisor::open() already launches this value as the
        // argument to bash -lc. Do not wrap it in a second shell command:
        // on Windows, the extra escapeshellarg() layer strips quotes from
        // otherwise valid commands such as `php -r 'echo "x";'`.
        return $script;
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
     * Allocate stdout/stderr capture endpoints for foreground Bash.
     *
     * @return array{stdoutFile: ?string, stderrFile: ?string, stdoutHandle: resource|null, stderrHandle: resource|null, usePipes: bool}|null
     */
    private function allocateForegroundCaptureFiles(): ?array
    {
        // Windows has no POSIX FIFO equivalent. Use proc_open pipes there so
        // the parent can enforce the combined byte cap before output reaches a
        // regular file; a fast `yes`/build log must not fill the temp volume.
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'stdoutFile' => null,
                'stderrFile' => null,
                'stdoutHandle' => null,
                'stderrHandle' => null,
                'usePipes' => true,
            ];
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

            return null;
        }

        $useFifo = PHP_OS_FAMILY !== 'Windows' && function_exists('posix_mkfifo');
        if ($useFifo) {
            @unlink($stdoutFile);
            @unlink($stderrFile);
            $stdoutFifo = @posix_mkfifo($stdoutFile, 0600);
            $stderrFifo = @posix_mkfifo($stderrFile, 0600);
            $useFifo = $stdoutFifo && $stderrFifo;
        }

        // Never fall back to ordinary files: the child could fill them faster
        // than the parent drains them, defeating the physical output cap.
        if (! $useFifo) {
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return [
                'stdoutFile' => null,
                'stderrFile' => null,
                'stdoutHandle' => null,
                'stderrHandle' => null,
                'usePipes' => true,
            ];
        }

        $stdoutHandle = @fopen($stdoutFile, 'r+');
        $stderrHandle = @fopen($stderrFile, 'r+');

        if (! is_resource($stdoutHandle) || ! is_resource($stderrHandle)) {
            if (is_resource($stdoutHandle)) {
                fclose($stdoutHandle);
            }
            if (is_resource($stderrHandle)) {
                fclose($stderrHandle);
            }
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return null;
        }

        stream_set_blocking($stdoutHandle, false);
        stream_set_blocking($stderrHandle, false);

        return [
            'stdoutFile' => $stdoutFile,
            'stderrFile' => $stderrFile,
            'stdoutHandle' => $stdoutHandle,
            'stderrHandle' => $stderrHandle,
            'usePipes' => false,
        ];
    }

    /** @param array{stdoutFile: ?string, stderrFile: ?string, stdoutHandle: resource|null, stderrHandle: resource|null, usePipes: bool} $capture */
    private function closeForegroundCaptureFiles(array $capture): void
    {
        if (is_resource($capture['stdoutHandle'])) {
            fclose($capture['stdoutHandle']);
        }
        if (is_resource($capture['stderrHandle'])) {
            fclose($capture['stderrHandle']);
        }
        if (is_string($capture['stdoutFile'] ?? null)) {
            @unlink($capture['stdoutFile']);
        }
        if (is_string($capture['stderrFile'] ?? null)) {
            @unlink($capture['stderrFile']);
        }
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
        return BackgroundBashTaskManager::start($command, $cwd, $warnings, $timeoutSeconds, $env);
    }

    /**
     * Check if a background task has completed.
     */
    public static function checkTask(string $taskId): ?ToolResult
    {
        return BackgroundBashTaskManager::checkTask($taskId);
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
     * Append command output while enforcing one combined stdout+stderr byte cap.
     *
     * @return bool true when the output limit was reached
     */
    private function appendCapturedChunk(
        string &$target,
        string $chunk,
        int &$capturedOutputBytes,
        int $maxOutputBytes,
        string $limitNotice,
    ): bool {
        if ($chunk === '') {
            return false;
        }

        $room = $maxOutputBytes - $capturedOutputBytes;
        if ($room <= 0) {
            return true;
        }

        if (strlen($chunk) > $room) {
            $roomForNotice = min(strlen($limitNotice), $room);
            $roomForChunk = max(0, $room - $roomForNotice);
            $data = substr($chunk, 0, $roomForChunk).substr($limitNotice, 0, $roomForNotice);
            $target .= $data;
            $capturedOutputBytes += strlen($data);

            return true;
        }

        $target .= $chunk;
        $capturedOutputBytes += strlen($chunk);

        return false;
    }

    /**
     * Check all background tasks.
     * @return array<string, ToolResult>
     */
    public static function checkAllTasks(): array
    {
        return BackgroundBashTaskManager::checkAllTasks();
    }

    /**
     * List tracked background tasks (after TTL / PID-reuse pruning).
     *
     * @return array<string, array{pid: int, outFile: string, statusFile: string, startTime: float, startToken: ?string, command: string}>
     */
    public static function listTasks(): array
    {
        return BackgroundBashTaskManager::listTasks();
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
