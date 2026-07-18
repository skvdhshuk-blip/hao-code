<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

/**
 * Deterministic risk classifier for the smart HITL mode.
 *
 * Ports rules R0-R5 from the hao-work bridge layer (hitl-policy.php) plus the
 * codex guardian red lines (credential access, exfiltration, persistent
 * security weakening) into the SDK. Everything is fail-closed: unknown tools,
 * malformed input, unresolvable paths, and classifier errors all degrade to
 * manual human review, never to silent approval.
 *
 * Levels:
 * - auto_allow: rule-approved without a model review.
 * - gray:       not allowlisted, not a red line; eligible for model review.
 * - red_line:   hard rule hit; always escalates to a human, no model review.
 * - ask:        unknown/malformed/fail-closed; always escalates to a human.
 */
final class HitlPolicy
{
    public const AUTO_ALLOW = 'auto_allow';
    public const GRAY = 'gray';
    public const RED_LINE = 'red_line';
    public const ASK = 'ask';

    private const MAX_WRITE_BYTES = 1048576; // 1 MiB

    private const KNOWN_TOOLS = [
        'Read', 'Glob', 'Grep', 'LSP', 'Write', 'Edit', 'apply_patch', 'Bash',
        'TodoWrite', 'MemoryRead', 'MemoryWrite', 'Skill', 'AskUserQuestion',
    ];

    /** Input keys whose string values may reference paths or shell commands. */
    private const PATH_LIKE_KEYS = [
        'file_path', 'path', 'notebook_path', 'target_file', 'old_path', 'new_path',
        'directory', 'dir', 'pattern', 'command', 'patch',
    ];

    /** Credential/secret material that no mode may touch automatically (R1). */
    private const SENSITIVE_PATTERNS = [
        '/(^|[\/\s])\.ssh(\/|$)/i' => 'SSH directory',
        '/(^|[\/\s])\.aws(\/|$)/i' => 'AWS credentials directory',
        '/(^|[\/\s])\.gnupg(\/|$)/i' => 'GnuPG directory',
        '/(^|[\/\s])\.env([._-]|$)/i' => 'dotenv file',
        '/(^|[\/\s])credentials?([._\/-]|$)/i' => 'credentials file',
        '/(^|[\/\s])id_(rsa|dsa|ecdsa|ed25519)(\.|$)/i' => 'SSH private key',
        '/\.(pem|key|p12|pfx|jks|keystore)($|\/)/i' => 'key/certificate material',
        '/keychains?([\/\.]|$)/i' => 'OS keychain',
        '/(^|[\/\s])\.netrc($|\/)/i' => 'netrc file',
        '/(^|[\/\s])\.npmrc($|\/)/i' => 'npmrc file',
        '/(^|[\/\s])\.pypirc($|\/)/i' => 'pypirc file',
        '/runtime-state\.json/i' => 'adapter runtime state holding secrets',
        '/\bsecurity\s+find-(generic|internet)-password\b/i' => 'macOS keychain extraction',
        '/\/proc\/[^\s\/]*\/environ\b/i' => 'process environment harvesting',
    ];

    /**
     * Shell obfuscation / arbitrary-execution markers.
     *
     * $() and backticks are intentionally absent: command substitutions are
     * extracted and rated recursively (codex-style) instead of being rejected
     * wholesale. ${} expansion, $IFS manipulation, and control characters stay
     * red lines.
     */
    private const OBFUSCATION_PATTERNS = [
        '/\$\{/' => 'parameter expansion ${}',
        '/\$IFS/' => 'IFS manipulation',
        '/[\x00-\x08\x0B\x0C\x7F]/' => 'control characters',
    ];

    /** Maximum nesting depth for recursive command substitution rating. */
    private const MAX_SUBST_DEPTH = 2;

    /** Commands that only read state and are safe to auto-allow (R4 allowlist). */
    private const SIMPLE_ALLOWLIST = [
        'pwd', 'ls', 'cat', 'head', 'tail', 'wc', 'file', 'stat', 'du', 'df',
        'tree', 'which', 'echo', 'printf', 'date', 'grep', 'rg', 'true', 'false',
        'hostname', 'whoami', 'uname', 'basename', 'dirname', 'realpath', 'readlink',
        'sort', 'uniq', 'comm', 'diff', 'cut', 'tr', 'jq', 'yq', 'more', 'less',
        'awk', 'zipinfo', 'nproc', 'cd',
    ];

    /** Commands that are always a red line regardless of arguments. */
    private const HARD_RED_COMMANDS = [
        'sudo' => 'privilege escalation',
        'su' => 'privilege escalation',
        'doas' => 'privilege escalation',
        'dd' => 'raw disk writes',
        'mkfs' => 'filesystem formatting',
        'shred' => 'secure data destruction',
        'fdisk' => 'partition table modification',
        'mount' => 'filesystem mount',
        'umount' => 'filesystem unmount',
        'eval' => 'dynamic code execution',
        'exec' => 'process replacement',
        'source' => 'executes file contents',
        'xargs' => 'argument-driven execution',
        'base64' => 'encoded payload handling',
        'env' => 'environment dumping',
        'printenv' => 'environment dumping',
        'history' => 'shell history exposure',
        'crontab' => 'scheduled task modification',
        'launchctl' => 'service manager control',
        'systemctl' => 'service manager control',
        'chown' => 'ownership change',
        'security' => 'keychain access',
        'curl' => 'network egress',
        'wget' => 'network egress',
        'nc' => 'raw network access',
        'ncat' => 'raw network access',
        'scp' => 'network file transfer',
        'rsync' => 'network file transfer',
        'ssh' => 'remote shell',
        'sftp' => 'network file transfer',
        'ftp' => 'network file transfer',
        'telnet' => 'remote shell',
    ];

    /**
     * Classify one interrupted action. Never throws.
     *
     * @return array{level: string, reason: string}
     */
    public static function classifyAction(string $toolName, mixed $input, string $cwd): array
    {
        try {
            return self::doClassify($toolName, $input, $cwd);
        } catch (\Throwable $exception) {
            return self::verdict(self::ASK, 'Classifier failed closed: '.$exception->getMessage());
        }
    }

    /** @return array{level: string, reason: string} */
    private static function verdict(string $level, string $reason): array
    {
        return ['level' => $level, 'reason' => $reason];
    }

    /** @return array{level: string, reason: string} */
    private static function doClassify(string $toolName, mixed $input, string $cwd): array
    {
        $root = realpath($cwd);
        if ($root === false || ! is_dir($root)) {
            return self::verdict(self::ASK, 'Workspace root could not be resolved.');
        }

        // R0: structure and source red lines.
        $toolName = trim($toolName);
        if (! in_array($toolName, self::KNOWN_TOOLS, true)) {
            return self::verdict(self::ASK, "Unknown tool '{$toolName}' requires manual review.");
        }
        if ($toolName === 'AskUserQuestion') {
            return self::verdict(self::ASK, 'AskUserQuestion always requires a human answer.');
        }
        if (! is_array($input)) {
            return self::verdict(self::ASK, 'Action input has an unexpected shape.');
        }

        // R1: credential/secret material is a red line for every tool.
        foreach (self::sensitiveStrings($input) as $candidate) {
            $hit = self::matchSensitive($candidate);
            if ($hit !== null) {
                return self::verdict(self::RED_LINE, "Touches sensitive material ({$hit}).");
            }
        }

        return match ($toolName) {
            // R2/R5: read-only tools (still subject to R1 above).
            'Read', 'Glob', 'Grep', 'LSP', 'TodoWrite', 'MemoryRead'
                => self::verdict(self::AUTO_ALLOW, 'Read-only tool.'),
            'MemoryWrite', 'Skill'
                => self::verdict(self::ASK, "{$toolName} changes persistent state outside the workspace scope."),
            // R3: file writes.
            'Write', 'Edit' => self::classifyWrite($toolName, $input, $root),
            'apply_patch' => self::classifyPatch($input, $root),
            // R4: shell commands.
            'Bash' => self::classifyBash($input, $root),
            default => self::verdict(self::ASK, "Tool '{$toolName}' requires manual review."),
        };
    }

    /** @return string[] */
    private static function sensitiveStrings(array $input): array
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

    private static function matchSensitive(string $value): ?string
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $value) === 1) {
                return $label;
            }
        }
        return null;
    }

    /** R3: Write/Edit must stay inside the workspace. @return array{level: string, reason: string} */
    private static function classifyWrite(string $toolName, array $input, string $root): array
    {
        $filePath = $input['file_path'] ?? null;
        if (! is_string($filePath) || trim($filePath) === '') {
            return self::verdict(self::ASK, "{$toolName} input is missing a file_path string.");
        }
        $resolved = self::resolvePath($filePath, $root);
        if ($resolved === null) {
            return self::verdict(self::ASK, "Path '{$filePath}' could not be resolved safely.");
        }
        $sensitive = self::matchSensitive($resolved);
        if ($sensitive !== null) {
            return self::verdict(self::RED_LINE, "Resolved path touches sensitive material ({$sensitive}).");
        }
        if (! self::isWithinWorkspace($resolved, $root)) {
            return self::verdict(self::ASK, "Path '{$filePath}' escapes the workspace (resolved to '{$resolved}').");
        }
        $bytes = 0;
        foreach (['content', 'old_string', 'new_string'] as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $bytes += strlen($value);
            }
        }
        if ($bytes > self::MAX_WRITE_BYTES) {
            return self::verdict(self::ASK, "{$toolName} payload exceeds 1 MiB.");
        }
        return self::verdict(self::AUTO_ALLOW, "Workspace-local {$toolName}.");
    }

    /** R3: every file an apply_patch envelope touches must stay inside the workspace. */
    private static function classifyPatch(array $input, string $root): array
    {
        $patch = $input['patch'] ?? null;
        if (! is_string($patch) || trim($patch) === '') {
            return self::verdict(self::ASK, 'apply_patch input is missing a patch string.');
        }
        if (strlen($patch) > self::MAX_WRITE_BYTES) {
            return self::verdict(self::ASK, 'apply_patch payload exceeds 1 MiB.');
        }
        $paths = [];
        if (preg_match_all('/^\*\*\*\s*(?:Add|Update|Delete|Move)\s+File:\s*(.+?)\s*$/m', $patch, $m1) > 0) {
            $paths = array_merge($paths, $m1[1]);
        }
        if (preg_match_all('/^\*\*\*\s*Move to:\s*(.+?)\s*$/m', $patch, $m2) > 0) {
            $paths = array_merge($paths, $m2[1]);
        }
        if ($paths === []) {
            return self::verdict(self::ASK, 'Could not identify apply_patch target files.');
        }
        foreach ($paths as $path) {
            $resolved = self::resolvePath($path, $root);
            if ($resolved === null) {
                return self::verdict(self::ASK, "Patch target '{$path}' could not be resolved safely.");
            }
            $sensitive = self::matchSensitive($resolved);
            if ($sensitive !== null) {
                return self::verdict(self::RED_LINE, "Patch target touches sensitive material ({$sensitive}).");
            }
            if (! self::isWithinWorkspace($resolved, $root)) {
                return self::verdict(self::ASK, "Patch target '{$path}' escapes the workspace (resolved to '{$resolved}').");
            }
        }
        return self::verdict(self::AUTO_ALLOW, 'Workspace-local apply_patch.');
    }

    /** R4: static text analysis of the shell command; every segment must pass. */
    private static function classifyBash(array $input, string $root): array
    {
        $command = $input['command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            return self::verdict(self::ASK, 'Bash input is missing a command string.');
        }

        return self::classifyBashCommand($command, $root, 0);
    }

    /** @return array{level: string, reason: string} */
    private static function classifyBashCommand(string $command, string $root, int $depth): array
    {
        foreach (self::OBFUSCATION_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $command) === 1) {
                return self::verdict(self::RED_LINE, "Shell obfuscation pattern: {$label}.");
            }
        }

        // Codex-style recursive rating: extract every effective command
        // substitution, rate each inner command on its own, then rate the
        // outer command with placeholder tokens standing in for the
        // substitution spans.
        $substVerdicts = [];
        $rewritten = self::extractSubstitutions($command, $root, $depth, $substVerdicts);
        if (is_array($rewritten)) {
            return $rewritten; // parse failure (fail-closed) or red-lined inner command.
        }

        $gray = null;
        foreach (self::splitSegments($rewritten) as $segment) {
            $bare = preg_replace('/\d?>&\d/', ' ', $segment) ?? $segment;
            $bare = preg_replace('/\d?>>?\s*\/dev\/null/', ' ', $bare) ?? $bare;
            if (preg_match('/^__hitl_subst_\d+__$/', trim($bare)) === 1) {
                continue; // substitution in command position; its own verdict governs.
            }
            $verdict = self::classifySegment($segment, $root);
            if ($verdict['level'] === self::RED_LINE) {
                return $verdict; // one red-line segment poisons the whole command.
            }
            if ($verdict['level'] !== self::AUTO_ALLOW && $gray === null) {
                $gray = $verdict;
            }
        }

        // A substitution rated gray/ask raises the outer command to at least
        // that level; auto-allowed substitutions never do.
        foreach ($substVerdicts as $substVerdict) {
            if ($substVerdict['level'] !== self::AUTO_ALLOW) {
                $substGray = self::verdict(
                    $substVerdict['level'],
                    'Shell command substitution requires review: '.$substVerdict['reason'],
                );

                return $gray ?? $substGray;
            }
        }

        return $gray ?? self::verdict(self::AUTO_ALLOW, 'Read-only shell command allowlist.');
    }

    /**
     * Extract effective command substitutions ($(...) and backticks) from a
     * command string. Single-quoted spans never execute and are skipped;
     * backslash escapes (outside single quotes) skip the next character;
     * $((...)) arithmetic does not execute by itself but its content is still
     * scanned for nested substitutions. Every effective substitution is rated
     * recursively and its span is replaced with a __hitl_subst_N__ token.
     *
     * Returns the rewritten command string, or a red-line verdict array when
     * parsing fails (fail-closed) or an inner command hits a red line.
     *
     * @param  array<string, array{level: string, reason: string}>  $substVerdicts
     * @return string|array{level: string, reason: string}
     */
    private static function extractSubstitutions(string $command, string $root, int $depth, array &$substVerdicts): string|array
    {
        $out = '';
        $len = strlen($command);
        $i = 0;
        while ($i < $len) {
            $ch = $command[$i];
            if ($ch === '\\') {
                $out .= substr($command, $i, 2);
                $i += 2;
                continue;
            }
            if ($ch === "'") {
                $end = strpos($command, "'", $i + 1);
                if ($end === false) {
                    $out .= substr($command, $i); // unbalanced quote; the shell will reject it.

                    break;
                }
                $out .= substr($command, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }
            $isSubst = $ch === '$' && ($command[$i + 1] ?? '') === '(';
            $isArithmetic = $isSubst && ($command[$i + 2] ?? '') === '(';
            if ($isSubst || $ch === '`') {
                if ($isArithmetic) {
                    $start = $i + 3;
                    $close = self::findClosingParen($command, $start, 2);
                    if ($close === null) {
                        return self::verdict(self::RED_LINE, 'Unbalanced arithmetic expansion $((...)).');
                    }
                    // Arithmetic content is scanned for nested substitutions
                    // but never rated as a command itself.
                    $scanned = self::extractSubstitutions(
                        substr($command, $start, $close - 1 - $start),
                        $root,
                        $depth,
                        $substVerdicts,
                    );
                    if (is_array($scanned)) {
                        return $scanned;
                    }
                    $token = '__hitl_subst_'.count($substVerdicts).'__';
                    $substVerdicts[$token] = self::verdict(self::AUTO_ALLOW, 'Arithmetic expansion.');
                    $out .= $token;
                    $i = $close + 1;
                    continue;
                }
                if ($isSubst) {
                    $start = $i + 2;
                    $close = self::findClosingParen($command, $start, 1);
                    if ($close === null) {
                        return self::verdict(self::RED_LINE, 'Unbalanced command substitution $(...).');
                    }
                } else {
                    $start = $i + 1;
                    $close = self::findClosingBacktick($command, $start);
                    if ($close === null) {
                        return self::verdict(self::RED_LINE, 'Unbalanced backtick command substitution.');
                    }
                }
                if ($depth + 1 > self::MAX_SUBST_DEPTH) {
                    return self::verdict(self::RED_LINE, 'Command substitution nesting exceeds depth limit.');
                }
                $verdict = self::classifyBashCommand(substr($command, $start, $close - $start), $root, $depth + 1);
                if ($verdict['level'] === self::RED_LINE) {
                    return self::verdict(
                        self::RED_LINE,
                        'Shell command substitution runs red-lined inner command: '.$verdict['reason'],
                    );
                }
                $token = '__hitl_subst_'.count($substVerdicts).'__';
                $substVerdicts[$token] = $verdict;
                $out .= $token;
                $i = $close + 1;
                continue;
            }
            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Find the position where a paren group opened before $start balances,
     * honoring backslash escapes and single/double quotes. $depth is 1 for
     * $(...) and 2 for $((...)) so arithmetic consumes its closing "))".
     */
    private static function findClosingParen(string $command, int $start, int $depth): ?int
    {
        $len = strlen($command);
        for ($i = $start; $i < $len; $i++) {
            $ch = $command[$i];
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $end = strpos($command, $ch, $i + 1);
                if ($end === false) {
                    return null;
                }
                $i = $end;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Find the next unescaped backtick at or after $start. */
    private static function findClosingBacktick(string $command, int $start): ?int
    {
        $len = strlen($command);
        for ($i = $start; $i < $len; $i++) {
            $ch = $command[$i];
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === '`') {
                return $i;
            }
        }

        return null;
    }

    /** @return string[] */
    private static function splitSegments(string $command): array
    {
        // Split on &&, ||, ;, |, and newlines — but only outside quotes, so a
        // quoted "a|b" argument no longer shatters into bogus segments.
        $segments = [];
        $current = '';
        $quote = null;
        $len = strlen($command);
        for ($i = 0; $i < $len; $i++) {
            $ch = $command[$i];
            if ($quote !== "'") {
                if ($ch === '\\') {
                    $current .= substr($command, $i, 2);
                    $i++;
                    continue;
                }
                if ($quote === null && ($ch === "'" || $ch === '"')) {
                    $quote = $ch;
                    $current .= $ch;
                    continue;
                }
            }
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($ch === '&' || $ch === '|') && ($command[$i + 1] ?? '') === $ch) {
                $segments[] = $current;
                $current = '';
                $i++;
                continue;
            }
            if ($ch === ';' || $ch === '|' || $ch === "\n" || $ch === "\r") {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $segments[] = $current;

        return array_values(array_filter(array_map('trim', $segments), static fn (string $s): bool => $s !== ''));
    }

    /** @return array{level: string, reason: string} */
    private static function classifySegment(string $segment, string $root): array
    {
        $redirect = self::checkRedirects($segment, $root);
        if ($redirect !== null) {
            return $redirect;
        }

        $tokens = preg_split('/\s+/', trim($segment)) ?: [];
        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => trim($token, "\"'"),
            $tokens,
        ), static fn (string $token): bool => $token !== ''));
        while ($tokens !== [] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $tokens[0]) === 1) {
            array_shift($tokens); // leading VAR=value environment assignments
        }
        if ($tokens === []) {
            return self::verdict(self::GRAY, 'Unrecognized shell segment.');
        }

        $command = basename($tokens[0]);
        $args = array_slice($tokens, 1);
        $first = $args[0] ?? null;

        if (isset(self::HARD_RED_COMMANDS[$command])) {
            return self::verdict(self::RED_LINE, "Red-line command '{$command}': ".self::HARD_RED_COMMANDS[$command].'.');
        }
        if ($command === 'rm') {
            return self::classifyRm($args, $root);
        }
        if ($command === 'chmod') {
            foreach ($args as $arg) {
                if (in_array($arg, ['000', '777', '0000', '0777'], true)) {
                    return self::verdict(self::RED_LINE, "chmod {$arg} weakens permissions broadly.");
                }
            }
            return self::verdict(self::GRAY, 'chmod changes file permissions.');
        }
        if ($command === 'openssl' && $first === 's_client') {
            return self::verdict(self::RED_LINE, 'openssl s_client opens a TLS probe channel.');
        }
        if (in_array($command, ['npm', 'yarn', 'pnpm', 'bun'], true) && $first === 'publish') {
            return self::verdict(self::RED_LINE, "{$command} publish ships artifacts externally.");
        }
        if ($command === 'npm' && in_array($first, ['unpublish', 'token'], true)) {
            return self::verdict(self::RED_LINE, "npm {$first} mutates registry state or credentials.");
        }
        if (in_array($command, ['pip', 'pip3'], true) && $first === 'install') {
            return self::verdict(self::RED_LINE, "{$command} install mutates the global Python environment.");
        }
        if ($command === 'git') {
            $red = self::classifyGitRedLine($args);
            if ($red !== null) {
                return $red;
            }
        }
        if ($command === 'sysctl') {
            foreach ($args as $arg) {
                if ($arg === '-w' || preg_match('/^[A-Za-z0-9_.-]+=/', $arg) === 1) {
                    return self::verdict(self::RED_LINE, 'sysctl with -w or key=value writes kernel parameters.');
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Read-only sysctl query.');
        }
        if ($command === 'make') {
            foreach ($args as $arg) {
                if ($arg === 'install') {
                    return self::verdict(self::GRAY, 'make install writes to system paths.');
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Workspace-local make build.');
        }
        if ($command === 'ninja') {
            return self::verdict(self::AUTO_ALLOW, 'Workspace-local ninja build.');
        }
        if ($command === 'cmake') {
            return in_array('--install', $args, true)
                ? self::verdict(self::GRAY, 'cmake --install writes to system paths.')
                : self::verdict(self::AUTO_ALLOW, 'Workspace-local cmake invocation.');
        }

        return self::matchAllowlist($command, $args, $root)
            ?? self::verdict(self::GRAY, "Command '{$command}' is not on the read-only allowlist.");
    }

    /** Redirect sinks are writes: every target must resolve inside the workspace. */
    private static function checkRedirects(string $segment, string $root): ?array
    {
        $cleaned = preg_replace('/\d?>&\d/', ' ', $segment) ?? $segment;
        $cleaned = preg_replace('/\d?>>?\s*\/dev\/null/', ' ', $cleaned) ?? $cleaned;
        $cleaned = str_replace('&>', '>', $cleaned);

        $targets = [];
        if (preg_match_all('/>>?\s*([^\s|&;<>]+)/', $cleaned, $matches) > 0) {
            $targets = $matches[1];
        }
        if (preg_match_all('/\btee\s+(?:-\w+\s+)*([^\s|&;<>]+)/', $cleaned, $teeMatches) > 0) {
            $targets = array_merge($targets, $teeMatches[1]);
        }
        foreach ($targets as $target) {
            $target = trim($target, "\"'");
            if ($target === '') {
                continue;
            }
            $resolved = self::resolvePath($target, $root);
            if ($resolved === null) {
                return self::verdict(self::RED_LINE, "Redirect target '{$target}' cannot be resolved safely.");
            }
            if (! self::isWithinWorkspace($resolved, $root)) {
                return self::verdict(self::RED_LINE, "Redirect writes outside the workspace ('{$resolved}').");
            }
            $sensitive = self::matchSensitive($resolved);
            if ($sensitive !== null) {
                return self::verdict(self::RED_LINE, "Redirect targets sensitive material ({$sensitive}).");
            }
        }
        return null;
    }

    /** @return array{level: string, reason: string} */
    private static function classifyRm(array $args, string $root): array
    {
        $destructive = false;
        $targets = [];
        foreach ($args as $arg) {
            if ($arg === '--') {
                continue;
            }
            if (str_starts_with($arg, '--')) {
                if (in_array($arg, ['--recursive', '--force', '--no-preserve-root'], true)) {
                    $destructive = true;
                }
                continue;
            }
            if (str_starts_with($arg, '-') && strlen($arg) > 1) {
                if (strpbrk(substr($arg, 1), 'rRf') !== false) {
                    $destructive = true;
                }
                continue;
            }
            $targets[] = $arg;
        }

        $home = getenv('HOME');
        $home = is_string($home) && $home !== '' ? realpath($home) : false;
        foreach ($targets as $target) {
            $resolved = self::resolvePath($target, $root);
            if ($resolved === null) {
                continue; // unresolvable targets stay gray below.
            }
            $broad = $resolved === '/'
                || $resolved === $root
                || ($home !== false && $resolved === $home)
                || ! self::isWithinWorkspace($resolved, $root);
            if ($destructive && $broad) {
                return self::verdict(self::RED_LINE, "rm with recursive/force flags targets '{$resolved}', a broad or out-of-workspace scope.");
            }
        }
        return self::verdict(self::GRAY, 'rm deletes files; left for guardian review.');
    }

    /** git subcommands that publish, rewrite history, or discard work. */
    private static function classifyGitRedLine(array $args): ?array
    {
        $parsed = self::gitArgs($args);
        if ($parsed === null) {
            return null;
        }
        [$subcommand, $rest] = $parsed;
        switch ($subcommand) {
            case 'push':
                return self::verdict(self::RED_LINE, 'git push publishes history to a remote.');
            case 'rebase':
                return self::verdict(self::RED_LINE, 'git rebase rewrites history.');
            case 'remote':
                return self::verdict(self::RED_LINE, 'git remote modifies remote configuration.');
            case 'filter-branch':
                return self::verdict(self::RED_LINE, 'git filter-branch rewrites history broadly.');
            case 'update-ref':
                if (in_array('-d', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git update-ref -d deletes a ref.');
                }
                return null;
            case 'reset':
                if (in_array('--hard', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git reset --hard discards uncommitted changes.');
                }
                return null;
            case 'clean':
                foreach ($rest as $arg) {
                    if (str_starts_with($arg, '-') && strpbrk($arg, 'fd') !== false) {
                        return self::verdict(self::RED_LINE, 'git clean permanently deletes untracked files.');
                    }
                }
                return null;
            case 'checkout':
                if (in_array('.', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git checkout . discards working tree changes.');
                }
                if (in_array('--', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git checkout -- discards working tree changes.');
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Strip benign global git flags and split off the subcommand.
     *
     * @return array{0: string, 1: string[]}|null [subcommand, remaining args]; null when scope-changing flags appear.
     */
    private static function gitArgs(array $args): ?array
    {
        while ($args !== [] && str_starts_with($args[0], '-')) {
            if ($args[0] !== '--no-pager') {
                return null; // -C / --git-dir / --work-tree change scope; fail to gray.
            }
            array_shift($args);
        }
        if ($args === []) {
            return null;
        }
        $subcommand = array_shift($args);
        return [$subcommand, $args];
    }

    /** @return array{level: string, reason: string}|null auto_allow/gray verdict, null when not allowlisted */
    private static function matchAllowlist(string $command, array $args, string $root): ?array
    {
        if (in_array($command, self::SIMPLE_ALLOWLIST, true)) {
            return self::verdict(self::AUTO_ALLOW, "Read-only command '{$command}'.");
        }
        if ($command === 'find') {
            foreach (['-exec', '-execdir', '-ok', '-okdir', '-delete'] as $flag) {
                if (in_array($flag, $args, true)) {
                    return null;
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Read-only find without -exec/-delete.');
        }
        if ($command === 'sed') {
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--in-place') || preg_match('/^-[a-zA-Z]*i/', $arg) === 1) {
                    return self::verdict(self::GRAY, 'sed -i edits files in place.');
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Read-only sed stream edit.');
        }
        if ($command === 'tar') {
            $letters = ltrim($args[0] ?? '', '-');
            if ($letters !== '' && preg_match('/^[a-z]+$/', $letters) === 1
                && str_contains($letters, 't') && strpbrk($letters, 'xc') === false) {
                return self::verdict(self::AUTO_ALLOW, 'Read-only tar listing.');
            }
            return null; // tar create/extract writes files; stays gray.
        }
        if ($command === 'unzip') {
            return ($args[0] ?? '') === '-l'
                ? self::verdict(self::AUTO_ALLOW, 'Read-only unzip listing.')
                : null;
        }
        if ($command === 'git') {
            $parsed = self::gitArgs($args);
            if ($parsed === null) {
                return null;
            }
            [$subcommand, $rest] = $parsed;
            if (in_array($subcommand, ['status', 'log', 'diff', 'show', 'rev-parse', 'ls-files', 'blame'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Read-only git {$subcommand}.");
            }
            // Local, reversible git operations are routine development work.
            if (in_array($subcommand, ['add', 'commit', 'switch', 'stash'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Local reversible git {$subcommand}.");
            }
            if ($subcommand === 'restore') {
                return in_array('--staged', $rest, true)
                    ? self::verdict(self::AUTO_ALLOW, 'git restore --staged only unstages files.')
                    : null; // plain git restore discards working tree changes; stays gray.
            }
            if ($subcommand === 'tag') {
                foreach ($rest as $arg) {
                    if ($arg === '--delete' || preg_match('/^-[a-zA-Z]*d/', $arg) === 1) {
                        return null; // tag deletion stays gray.
                    }
                }
                return self::verdict(self::AUTO_ALLOW, 'Local git tag operation.');
            }
            if ($subcommand === 'branch') {
                return self::verdict(self::AUTO_ALLOW, 'Local git branch operation (recoverable via reflog).');
            }
            return null;
        }
        if ($command === 'php') {
            return in_array($args[0] ?? '', ['-v', '--version', '-l'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only php invocation.')
                : null;
        }
        if ($command === 'node') {
            return in_array($args[0] ?? '', ['-v', '--version'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only node invocation.')
                : null;
        }
        // Package managers: workspace-local dependency/build/test workflows.
        if (in_array($command, ['npm', 'yarn', 'pnpm', 'bun'], true)) {
            $sub = $args[0] ?? '';
            if (in_array($sub, ['-v', '--version'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Read-only {$command} invocation.");
            }
            if (in_array($sub, [
                'install', 'add', 'remove', 'uninstall', 'update', 'test', 'run',
                'ci', 'exec', 'dlx', 'ls', 'list', 'outdated', 'audit',
            ], true)) {
                return self::verdict(self::AUTO_ALLOW, "Workspace-local {$command} {$sub}.");
            }
            return null;
        }
        if (in_array($command, ['npx', 'bunx'], true)) {
            return self::verdict(self::AUTO_ALLOW, "Package runner {$command}.");
        }
        if ($command === 'composer') {
            return in_array($args[0] ?? '', ['show', 'validate'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only composer invocation.')
                : null;
        }
        if ($command === 'tsc') {
            $onlyFlags = true;
            foreach ($args as $arg) {
                if (! str_starts_with($arg, '-')) {
                    $onlyFlags = false;
                    break;
                }
            }
            return ($onlyFlags && in_array('--noEmit', $args, true))
                ? self::verdict(self::AUTO_ALLOW, 'Read-only tsc --noEmit.')
                : null;
        }
        if (in_array($command, ['mkdir', 'touch', 'cp', 'mv'], true)) {
            $paths = [];
            foreach ($args as $arg) {
                if ($arg === '--' || str_starts_with($arg, '-')) {
                    continue;
                }
                $paths[] = $arg;
            }
            if ($paths === []) {
                return null;
            }
            foreach ($paths as $path) {
                $resolved = self::resolvePath($path, $root);
                if ($resolved === null || ! self::isWithinWorkspace($resolved, $root)) {
                    return self::verdict(self::GRAY, "{$command} touches a path outside the workspace.");
                }
            }
            return self::verdict(self::AUTO_ALLOW, "{$command} stays inside the workspace.");
        }
        return null;
    }

    /**
     * Resolve a path to an absolute, symlink-collapsed form.
     *
     * Handles ~ expansion, relative paths against the workspace root, dot
     * segments, and symlink prefixes (via the nearest existing ancestor).
     * Returns null when resolution is impossible — callers fail closed.
     */
    private static function resolvePath(string $rawPath, string $root): ?string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return null;
        }
        if ($rawPath === '~' || str_starts_with($rawPath, '~/')) {
            $home = getenv('HOME');
            if (! is_string($home) || $home === '') {
                return null;
            }
            $rawPath = $home.substr($rawPath, 1);
        } elseif (str_starts_with($rawPath, '~')) {
            return null; // ~user expansion is unsupported; fail closed.
        }
        if (! str_starts_with($rawPath, '/')) {
            $rawPath = $root.'/'.$rawPath;
        }

        $parts = [];
        foreach (explode('/', $rawPath) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $normalized = '/'.implode('/', $parts);

        // Collapse symlinks through the nearest existing ancestor so that a
        // symlink inside the workspace pointing outside is treated as outside.
        $candidate = $normalized;
        $suffix = [];
        while (! file_exists($candidate) && $candidate !== '/') {
            $suffix[] = basename($candidate);
            $candidate = dirname($candidate);
        }
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }
        while ($suffix !== []) {
            $resolved .= '/'.array_pop($suffix);
        }
        return $resolved;
    }

    private static function isWithinWorkspace(string $resolved, string $root): bool
    {
        return $resolved === $root || str_starts_with($resolved, $root.'/');
    }
}
