<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\Bash\ReadOnlyCommandSafety;

trait HitlPolicyClassifyActionConcern
{

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
        $sensitive = SensitivePathGuard::check($toolName, $input);
        if ($sensitive !== null) {
            return self::verdict(self::RED_LINE, "Touches sensitive material ({$sensitive}).");
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
        $sensitive = SensitivePathGuard::matchSensitive($resolved);
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
            $sensitive = SensitivePathGuard::matchSensitive($resolved);
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

    /**
     * Split a shell command into segments on && / || / ; / | / newlines.
     *
     * @internal Exposed for HitlAllowlist, which applies the exact same
     *           quote-aware segmentation for per-segment rule coverage.
     *
     * @return string[]
     */
    public static function splitSegments(string $command): array
    {
        return SensitivePathGuard::splitShellSegments($command);
    }

    /** @return array{level: string, reason: string} */
    private static function classifySegment(string $segment, string $root): array
    {
        $redirect = self::checkRedirects($segment, $root);
        if ($redirect !== null) {
            return $redirect;
        }

        $staticSegment = SensitivePathGuard::normalizeShellStaticText($segment);
        $tokens = preg_split('/\s+/', trim($staticSegment)) ?: [];
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
        if (SensitivePathGuard::requiresShellPathReview($segment)) {
            return self::verdict(
                self::ASK,
                "Read command '{$command}' uses dynamic shell path expansion.",
            );
        }
        $readOnlyMutation = ReadOnlyCommandSafety::mutationReason($segment);
        if ($command !== 'git' && $readOnlyMutation !== null) {
            return self::verdict(self::ASK, $readOnlyMutation);
        }
        if ($command === 'find' && array_intersect(self::FIND_MUTATING_ACTIONS, $args) !== []) {
            return self::verdict(self::ASK, 'find action may mutate files or execute commands.');
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
            if ($readOnlyMutation !== null) {
                return self::verdict(self::ASK, $readOnlyMutation);
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
}
