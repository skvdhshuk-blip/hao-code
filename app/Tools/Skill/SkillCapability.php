<?php

namespace HaoCode\Tools\Skill;

/**
 * Parse and enforce Claude-style skill allowed-tools capability rules.
 *
 * Specs look like {@code Read} (full tool) or {@code Bash(cargo:*)} (tool +
 * input pattern). Patterns are never silently stripped to a wider grant.
 *
 * @internal
 */
final class SkillCapability
{
    /**
     * @param  list<mixed>  $specs
     * @return list<string> Canonical capability specs (tool or tool(pattern))
     */
    public static function normalizeSpecs(array $specs): array
    {
        $normalized = [];
        foreach ($specs as $spec) {
            if (! is_string($spec)) {
                continue;
            }
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }
            $parsed = self::parse($spec);
            $key = $parsed['pattern'] === null
                ? $parsed['tool']
                : $parsed['tool'].'('.$parsed['pattern'].')';
            $normalized[$key] = $key;
        }

        return array_values($normalized);
    }

    /**
     * @return array{tool: string, pattern: ?string}
     */
    public static function parse(string $spec): array
    {
        $spec = trim($spec);
        $open = strpos($spec, '(');
        if ($open === false) {
            return ['tool' => $spec, 'pattern' => null];
        }

        $close = strrpos($spec, ')');
        if ($close === false || $close < $open) {
            throw new \InvalidArgumentException(
                "Invalid skill allowed-tools entry '{$spec}': missing closing ')'.",
            );
        }

        $tool = trim(substr($spec, 0, $open));
        $pattern = trim(substr($spec, $open + 1, $close - $open - 1));
        if ($tool === '') {
            throw new \InvalidArgumentException(
                "Invalid skill allowed-tools entry '{$spec}': empty tool name.",
            );
        }
        if ($pattern === '') {
            throw new \InvalidArgumentException(
                "Invalid skill allowed-tools entry '{$spec}': empty pattern. "
                ."Use '{$tool}' for full tool access, or '{$tool}(pattern)' with a non-empty pattern.",
            );
        }

        return ['tool' => $tool, 'pattern' => $pattern];
    }

    /**
     * @param  list<string>  $specs
     * @return list<string>
     */
    public static function toolNames(array $specs): array
    {
        $names = [];
        foreach (self::normalizeSpecs($specs) as $spec) {
            $names[self::parse($spec)['tool']] = true;
        }

        return array_keys($names);
    }

    /**
     * Intersect two capability lists. Patterned grants only survive when both
     * sides allow the tool; the more specific patterns are retained (AND).
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    public static function intersect(array $left, array $right): array
    {
        $leftRules = self::rulesByTool($left);
        $rightRules = self::rulesByTool($right);
        $result = [];

        foreach ($leftRules as $tool => $leftPatterns) {
            if (! array_key_exists($tool, $rightRules)) {
                continue;
            }
            $rightPatterns = $rightRules[$tool];
            $leftFull = in_array(null, $leftPatterns, true);
            $rightFull = in_array(null, $rightPatterns, true);

            if ($leftFull && $rightFull) {
                $result[] = $tool;
                continue;
            }

            $patterns = [];
            if ($leftFull) {
                $patterns = array_values(array_filter($rightPatterns, static fn ($p) => $p !== null));
            } elseif ($rightFull) {
                $patterns = array_values(array_filter($leftPatterns, static fn ($p) => $p !== null));
            } else {
                // Both sides constrain: keep the union of patterns and require
                // every retained pattern to match (checked at runtime via AND
                // of all patterns listed for the tool).
                $patterns = array_values(array_unique(array_merge(
                    array_filter($leftPatterns, static fn ($p) => $p !== null),
                    array_filter($rightPatterns, static fn ($p) => $p !== null),
                )));
            }

            if ($patterns === []) {
                $result[] = $tool;
                continue;
            }

            foreach ($patterns as $pattern) {
                $result[] = $tool.'('.$pattern.')';
            }
        }

        return self::normalizeSpecs($result);
    }

    /**
     * @param  list<string>|null  $specs
     * @param  array<string, mixed>  $input
     */
    public static function allows(?array $specs, string $toolName, array $input = []): bool
    {
        if ($specs === null) {
            return true;
        }
        if ($toolName === 'Skill') {
            return true;
        }

        $rules = self::rulesByTool($specs);
        if (! array_key_exists($toolName, $rules)) {
            return false;
        }

        $patterns = $rules[$toolName];
        if (in_array(null, $patterns, true)) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if ($pattern === null) {
                continue;
            }
            if (! self::matchesPattern($toolName, $pattern, $input)) {
                return false;
            }
        }

        return $patterns !== [];
    }

    /**
     * @param  list<string>  $specs
     * @return array<string, list<?string>>
     */
    private static function rulesByTool(array $specs): array
    {
        $rules = [];
        foreach (self::normalizeSpecs($specs) as $spec) {
            $parsed = self::parse($spec);
            $rules[$parsed['tool']][] = $parsed['pattern'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function matchesPattern(string $toolName, string $pattern, array $input): bool
    {
        if ($toolName === 'Bash') {
            $command = $input['command'] ?? '';
            if (! is_string($command)) {
                return false;
            }

            return self::matchesBashPattern(trim($command), $pattern);
        }

        // Path-constrained tools: match against file_path / path when present.
        $path = $input['file_path'] ?? $input['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return self::matchesGlob($pattern, $path);
        }

        // Pattern present but no enforceable subject — fail closed.
        return false;
    }

    private static function matchesBashPattern(string $command, string $pattern): bool
    {
        if ($command === '' || ! self::isSimpleSingleBashCommand($command)) {
            return false;
        }

        // Claude-style "cargo:*" / "npm run:*" — command prefix + optional args.
        // Prefix alone is not enough: shell operators must already be rejected
        // so "cargo test; rm -rf /" never becomes a grant.
        if (str_ends_with($pattern, ':*')) {
            $prefix = substr($pattern, 0, -2);
            if ($prefix === '') {
                return true;
            }

            return $command === $prefix || str_starts_with($command, $prefix.' ');
        }

        if (str_ends_with($pattern, ' *')) {
            $prefix = substr($pattern, 0, -2);

            return $command === $prefix || str_starts_with($command, $prefix.' ');
        }

        return self::matchesGlob($pattern, $command);
    }

    /**
     * Fail closed on multi-command shells, expansions, and redirections.
     *
     * Skill patterns grant a command family (e.g. cargo:*), not a free shell.
     * Anything that can chain or expand into another program is rejected.
     */
    private static function isSimpleSingleBashCommand(string $command): bool
    {
        // Control operators, pipes, redirections, expansions, subshells, comments.
        if (preg_match('/[;&|`$()<>\n\r#]|&&|\|\|/', $command) === 1) {
            return false;
        }

        // Reject environment-prefix forms like "FOO=bar cargo test" that can
        // rewrite process state before the matched binary runs.
        if (preg_match('/^\s*[A-Za-z_][A-Za-z0-9_]*=/', $command) === 1) {
            return false;
        }

        return true;
    }

    private static function matchesGlob(string $pattern, string $value): bool
    {
        return fnmatch($pattern, $value, FNM_NOESCAPE);
    }
}
