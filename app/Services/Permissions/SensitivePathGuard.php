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
        '/(^|[\/\s])\.ssh([\/:]|$)/i' => 'SSH directory',
        '/(^|[\/\s])\.aws([\/:]|$)/i' => 'AWS credentials directory',
        '/(^|[\/\s])\.gnupg([\/:]|$)/i' => 'GnuPG directory',
        '/(^|[\/\s])\.env([._:-]|$)/i' => 'dotenv file',
        '/(^|[\/\s])credentials?([._\/:-]|$)/i' => 'credentials file',
        '/(^|[\/\s])id_(rsa|dsa|ecdsa|ed25519)([.:]|$)/i' => 'SSH private key',
        '/\.(pem|key|p12|pfx|jks|keystore)($|\/|:)/i' => 'key/certificate material',
        '/keychains?([\/\.:]|$)/i' => 'OS keychain',
        '/(^|[\/\s])\.netrc($|\/|:)/i' => 'netrc file',
        '/(^|[\/\s])\.npmrc($|\/|:)/i' => 'npmrc file',
        '/(^|[\/\s])\.pypirc($|\/|:)/i' => 'pypirc file',
        '/runtime-state\.json/i' => 'adapter runtime state holding secrets',
        '/\bsecurity\s+find-(generic|internet)-password\b/i' => 'macOS keychain extraction',
        '/\/proc\/[^\s\/]*\/environ\b/i' => 'process environment harvesting',
    ];

    /**
     * Input keys whose string values may reference paths or shell commands.
     * Strings under these keys are scanned against {@see SENSITIVE_PATTERNS}.
     */
    public const PATH_LIKE_KEYS = [
        'file_path', 'path', 'notebook_path', 'target_file', 'old_path', 'new_path',
        'directory', 'dir', 'pattern', 'command', 'patch',
    ];

    /**
     * Scan a tool call's input for credential/secret material references.
     *
     * @param array<string, mixed> $input
     * @return string|null Human-readable label of the first hit, or null when clean.
     */
    public static function check(string $toolName, array $input): ?string
    {
        foreach (self::extractPathValues($input) as $candidate) {
            $hit = self::matchSensitive($candidate);
            if ($hit !== null) {
                return $hit;
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
