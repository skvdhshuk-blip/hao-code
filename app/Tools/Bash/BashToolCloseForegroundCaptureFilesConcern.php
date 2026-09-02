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

trait BashToolCloseForegroundCaptureFilesConcern
{

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
        ?string $owner = null,
    ): ToolResult
    {
        return BackgroundBashTaskManager::start($command, $cwd, $warnings, $timeoutSeconds, $env, $owner);
    }

    /**
     * Check if a background task has completed.
     *
     * Naming an owner restricts the lookup to that session's tasks.
     */
    public static function checkTask(string $taskId, ?string $owner = null): ?ToolResult
    {
        return BackgroundBashTaskManager::checkTask($taskId, $owner);
    }

    /**
     * Collect finished-but-unreported background commands for one owner.
     *
     * @return list<array{taskId: string, command: string, result: ?ToolResult, outputBytes: int}>
     */
    public static function harvestCompleted(?string $owner, int $inlineLimitBytes = 8000): array
    {
        return BackgroundBashTaskManager::harvestCompleted($owner, $inlineLimitBytes);
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
     * @return array<string, array{pid: int, outFile: string, statusFile: string, startTime: float, startToken: ?string, command: string, owner: ?string, notified: bool}>
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
}
