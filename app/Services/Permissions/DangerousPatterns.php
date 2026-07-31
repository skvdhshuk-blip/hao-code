<?php

namespace HaoCode\Services\Permissions;

/**
 * Dangerous command patterns that require elevated permission.
 */
class DangerousPatterns
{
    /** Commands that can execute arbitrary code */
    public const CODE_EXEC_COMMANDS = [
        'python', 'python3', 'node', 'deno', 'tsx', 'ruby', 'perl', 'php', 'lua',
        'npx', 'bunx', 'npm run', 'yarn run', 'pnpm run', 'bun run',
        'bash', 'sh', 'zsh', 'fish', 'eval', 'exec', 'env', 'xargs', 'sudo',
        'ssh', 'kubectl', 'aws', 'gcloud',
    ];

    /**
     * Check if a command starts with a dangerous code-exec prefix.
     */
    public static function isCodeExecCommand(string $command): bool
    {
        $trimmed = ltrim($command);
        foreach (self::CODE_EXEC_COMMANDS as $prefix) {
            if (str_starts_with($trimmed, $prefix . ' ') || $trimmed === $prefix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check for shell obfuscation patterns.
     * @return string|null Warning message or null if safe
     */
    public static function checkObfuscation(string $command): ?string
    {
        $patterns = [
            '/\$\(/' => 'Command substitution $() detected.',
            '/(?:<|>)\(/' => 'Process substitution detected.',
            '/\$\{/' => 'Parameter expansion ${} detected.',
            '/`/' => 'Backtick command substitution detected.',
            '/\$IFS\b/' => 'IFS variable manipulation detected.',
            '/\$(?:\{)?(?=[A-Za-z_][A-Za-z0-9_]*(?:\}|[^A-Za-z0-9_]|$))(?=[A-Za-z0-9_]*(?:API_KEY|TOKEN|SECRET|PASSWORD|PASSWD|PRIVATE_KEY|ACCESS_KEY|CREDENTIAL|DATABASE_URL|DSN))[A-Za-z_][A-Za-z0-9_]*(?:\})?/i'
                => 'Sensitive environment variable expansion detected.',
            '/\/proc\/\*\/environ/' => 'Accessing /proc/*/environ exposes environment variables.',
            '/[\x00-\x08\x7F]/' => 'Non-printable control characters detected.',
        ];

        foreach (array_unique([
            $command,
            SensitivePathGuard::normalizeShellStaticText($command),
        ]) as $candidate) {
            foreach ($patterns as $pattern => $message) {
                if (preg_match($pattern.'u', $candidate)) {
                    return $message;
                }
            }
        }
        return null;
    }

    /**
     * Get all dangerous Bash patterns with their warning messages.
     * @return array<string, string>
     */
    public static function getBashDangerPatterns(): array
    {
        return [
            '/\brm\s+(-[a-zA-Z]*f[a-zA-Z]*\s+|.*--recursive\b)/i' => 'Recursive/force delete detected.',
            '/\bgit\s+push\s+.*--force/i' => 'Force push detected. Consider --force-with-lease.',
            '/\bgit\s+reset\s+--hard/i' => 'Hard reset will discard uncommitted changes.',
            '/\bgit\s+clean\s+(-[a-zA-Z]*f|-fd)/i' => 'git clean permanently deletes untracked files.',
            '/\bgit\s+checkout\s+\./' => 'Discards all working directory changes.',
            '/\bDROP\s+(TABLE|DATABASE|SCHEMA)/i' => 'Destructive SQL operation.',
            '/\bsudo\s+/' => 'Requires elevated privileges.',
            '/\bchmod\s+(000|777)\b/' => 'Insecure file permissions.',
            '/\bdd\s+/' => 'dd can destroy data.',
            '/\b>\s*\/dev\/(s|h)d/' => 'Writing directly to disk device.',
            '/\bcurl\s+.*\|\s*(ba)?sh/' => 'Piping curl output to shell.',
            '/\brm\s+--no-preserve-root/' => 'Attempting to remove root filesystem.',
            '/\beval\s+/' => 'eval executes arbitrary code.',
            '/\bexec\s+/' => 'exec replaces the current process.',
        ];
    }
}
