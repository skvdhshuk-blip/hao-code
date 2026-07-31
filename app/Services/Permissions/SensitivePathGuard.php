<?php

declare(strict_types=1);

namespace HaoCode\Services\Permissions;

/**
 * Hard red-line guard for credential/secret material paths.
 *
 * This is the authoritative source of the sensitive-path pattern set. Both the
 * pre-permission gate (PermissionChecker::check) and the post-interrupt
 * classifier (HitlPolicy::classifyAction) consult these same constants so
 * the rule never drifts between layers.
 *
 * chatgpt 3rd-review background: the patterns used to live only inside
 * HitlPolicy::classifyAction, which never runs for tools that reach
 * PermissionDecision::allow on the isReadOnly fast path. As a result
 * Read/Grep/Glob of `~/.ssh/id_rsa`, `.env`, `~/.aws/credentials` etc.
 * bypassed the red line entirely. Centralizing here lets PermissionChecker
 * apply the same rule as a hard pre-check regardless of which tool
 * ultimately handles the input.
 */
final class SensitivePathGuard
{
    /**
     * Credential/secret material that no mode may touch automatically.
     *
     * Anchored regexes keyed by a human-readable label. Keys are evaluated
     * against any string extracted from {@see PATH_LIKE_KEYS}.
     */
    public const SENSITIVE_PATTERNS = [
        '/(^|[^A-Za-z0-9])\.ssh(?=$|[^A-Za-z0-9])/i' => 'SSH directory',
        '/(^|[^A-Za-z0-9])\.aws(?=$|[^A-Za-z0-9])/i' => 'AWS credentials directory',
        '/(^|[^A-Za-z0-9])\.gnupg(?=$|[^A-Za-z0-9])/i' => 'GnuPG directory',
        '/(^|[^A-Za-z0-9])\.env(?=$|[^A-Za-z0-9])/i' => 'dotenv file',
        '/(^|[^A-Za-z0-9])credentials?(?=$|[^A-Za-z0-9])/i' => 'credentials file',
        '/(^|[^A-Za-z0-9])id_(rsa|dsa|ecdsa|ed25519)(?=$|[^A-Za-z0-9])/i' => 'SSH private key',
        '/\.(pem|key|p12|pfx|jks|keystore)(?=$|[^A-Za-z0-9])/i' => 'key/certificate material',
        '/(^|[^A-Za-z0-9])keychains?(?=$|[^A-Za-z0-9])/i' => 'OS keychain',
        '/(^|[^A-Za-z0-9])\.netrc(?=$|[^A-Za-z0-9])/i' => 'netrc file',
        '/(^|[^A-Za-z0-9])\.npmrc(?=$|[^A-Za-z0-9])/i' => 'npmrc file',
        '/(^|[^A-Za-z0-9])\.pypirc(?=$|[^A-Za-z0-9])/i' => 'pypirc file',
        '/runtime-state\.json/i' => 'adapter runtime state holding secrets',
        '/\bsecurity\s+find-(generic|internet)-password\b/i' => 'macOS keychain extraction',
        '/\/proc\/[^\s\/]*\/(?:environ|cmdline)\b/i' => 'process environment/argument harvesting',
    ];

    /**
     * Input keys whose string values may reference paths or shell commands.
     * Strings under these keys are scanned against {@see SENSITIVE_PATTERNS}.
     */
    public const PATH_LIKE_KEYS = [
        'file_path', 'path', 'notebook_path', 'target_file', 'old_path', 'new_path',
        'directory', 'dir', 'pattern', 'command', 'patch',
    ];

    /** Shell commands whose dynamic arguments may resolve to sensitive paths. */
    private const PATH_READING_COMMANDS = [
        'ls', 'cat', 'head', 'tail', 'wc', 'file', 'stat', 'du', 'tree',
        'grep', 'rg', 'more', 'less', 'sort', 'uniq', 'comm', 'diff', 'cut',
        'jq', 'yq', 'awk', 'zipinfo', 'realpath', 'readlink', 'basename',
        'dirname', 'cd', 'find', 'sed',
    ];

    /**
     * Scan a tool call's input for credential/secret material references.
     *
     * @param array<string, mixed> $input
     * @return string|null Human-readable label of the first hit, or null when clean.
     */
    public static function check(string $toolName, array $input): ?string
    {
        foreach (self::PATH_LIKE_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $candidates = [$value];
            if ($key === 'command') {
                $candidates[] = self::normalizeShellStaticText($value);
            }

            foreach (array_unique($candidates) as $candidate) {
                $hit = self::matchSensitive($candidate);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * Scan a single string against {@see SENSITIVE_PATTERNS}.
     * Exposed so callers that have already resolved a path (e.g. realpath-ed)
     * can re-check the canonical form.
     */
    public static function matchSensitive(string $value): ?string
    {
        // CanonicalPathResolver emits native separators. Match the same
        // credential boundaries on Windows paths as on POSIX paths.
        $value = str_replace('\\', '/', $value);

        foreach (self::SENSITIVE_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $value) === 1) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Whether a path-reading shell command contains expansion that cannot be
     * resolved safely before Bash runs it.
     *
     * @internal Shared by the permission and HITL gates so the read-only fast
     *           path cannot bypass post-interrupt classification.
     */
    public static function requiresShellPathReview(string $command): bool
    {
        foreach (self::splitShellSegments($command) as $segment) {
            if (! self::hasDynamicShellExpansion($segment)) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($segment)) ?: [];
            $tokens = array_values(array_filter(array_map(
                static fn (string $token): string => trim($token, "\"'"),
                $tokens,
            ), static fn (string $token): bool => $token !== ''));
            while ($tokens !== [] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $tokens[0]) === 1) {
                array_shift($tokens);
            }
            if ($tokens === []) {
                continue;
            }

            if (in_array(basename($tokens[0]), self::PATH_READING_COMMANDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quote-aware shell segmentation shared with the HITL allowlist.
     *
     * @internal
     * @return string[]
     */
    public static function splitShellSegments(string $command): array
    {
        $segments = [];
        $current = '';
        $quote = null;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];
            if ($quote !== "'") {
                if ($character === '\\') {
                    $current .= substr($command, $index, 2);
                    $index++;
                    continue;
                }
                if ($quote === null && ($character === "'" || $character === '"')) {
                    $quote = $character;
                    $current .= $character;
                    continue;
                }
            }
            if ($quote !== null) {
                $current .= $character;
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($character === '&' || $character === '|')
                && ($command[$index + 1] ?? '') === $character
            ) {
                $segments[] = $current;
                $current = '';
                $index++;
                continue;
            }
            if ($character === '&'
                && ! in_array($command[$index - 1] ?? '', ['>', '<'], true)
                && ($command[$index + 1] ?? '') !== '>'
            ) {
                $segments[] = $current;
                $current = '';
                continue;
            }
            if ($character === ';' || $character === '|' || $character === "\n" || $character === "\r") {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $segments[] = $current;

        return array_values(array_filter(
            array_map('trim', $segments),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    private static function hasDynamicShellExpansion(string $value): bool
    {
        if (preg_match('/__hitl_subst_\d+__/', $value) === 1) {
            return true;
        }

        $quote = null;
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote === "'") {
                if ($character === "'") {
                    $quote = null;
                }
                continue;
            }
            if ($quote === '"') {
                if ($character === '\\') {
                    $index++;
                    continue;
                }
                if ($character === '"') {
                    $quote = null;
                    continue;
                }
                if ($character === '$' || $character === '`') {
                    return true;
                }
                continue;
            }

            if ($character === '\\') {
                $index++;
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '$' || $character === '`') {
                return true;
            }
            if ($character === '*' || $character === '?' || $character === '[') {
                return true;
            }
            if (($character === '<' || $character === '>')
                && ($value[$index + 1] ?? '') === '('
            ) {
                return true;
            }
            if ($character === '{') {
                $close = strpos($value, '}', $index + 1);
                if ($close !== false) {
                    $body = substr($value, $index + 1, $close - $index - 1);
                    if (str_contains($body, ',') || str_contains($body, '..')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Reconstruct the static text Bash passes after adjacent quoted/unquoted
     * fragments and ordinary backslash escapes are joined. Dynamic expansion
     * is intentionally left visible for HitlPolicy to fail closed separately.
     */
    /** @internal */
    public static function normalizeShellStaticText(string $command): string
    {
        $normalized = '';
        $quote = null;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];

            if ($quote === "'") {
                if ($character === "'") {
                    $quote = null;
                } else {
                    $normalized .= $character;
                }
                continue;
            }

            if ($quote === '"') {
                if ($character === '"') {
                    $quote = null;
                    continue;
                }
                if ($character === '\\' && $index + 1 < $length) {
                    $next = $command[$index + 1];
                    if ($next === "\n") {
                        $index++;
                        continue;
                    }
                    if (in_array($next, ['$', '`', '"', '\\'], true)) {
                        $normalized .= $next;
                        $index++;
                        continue;
                    }
                }
                $normalized .= $character;
                continue;
            }

            if ($character === '$'
                && in_array($command[$index + 1] ?? '', ["'", '"'], true)
            ) {
                $quote = $command[++$index];
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '\\' && $index + 1 < $length) {
                $next = $command[++$index];
                if ($next !== "\n") {
                    $normalized .= $next;
                }
                continue;
            }

            $normalized .= $character;
        }

        return $normalized;
    }

    /**
     * Pull candidate strings out of an input map.
     *
     * Only looks at top-level keys listed in {@see PATH_LIKE_KEYS} and only
     * when the value is a non-empty string. Nested structures are ignored on
     * purpose: deep traversal would explode the surface area and the
     * path-bearing keys are always top-level for the tools this project ships.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function extractPathValues(array $input): array
    {
        $values = [];
        foreach (self::PATH_LIKE_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
