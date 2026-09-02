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

trait BashToolNameConcern
{

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
 - Use the `run_in_background` parameter for long-running commands. Its result is delivered to you automatically when the command finishes; you can also fetch it with the BashOutput tool.
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
                    'minimum' => 1000,
                    'maximum' => 600000,
                    'description' => 'Optional timeout in milliseconds (max 600000)',
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Run the command in the background (process-local; not durable across PHP restarts). The output is delivered automatically once it finishes, or on demand via the BashOutput tool.',
                ],
            ],
            'required' => ['command'],
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
            return $this->runInBackground($command, $cwd, $warnings, $timeout, $env, $context->sessionId);
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
}
