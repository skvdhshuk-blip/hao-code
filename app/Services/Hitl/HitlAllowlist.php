<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

/**
 * User-saved "always allow" rules for the smart HITL mode.
 *
 * Ports the codex "always allow" concept (prefix rules persisted to
 * ~/.codex/rules/default.rules) into the SDK. A Bash action that matches a
 * saved rule is approved without the rule classifier or the guardian review.
 * Because the user explicitly saved the rule, a match intentionally overrides
 * red lines (user sovereignty); everything unmatched falls through to the
 * normal classifier untouched.
 *
 * The rule file is JSON with a frozen dual format:
 *
 *     {
 *       "version": 2,
 *       "rules": [
 *         {"type": "prefix", "tokens": ["git", "commit"], "addedAt": "<iso8601>", "source": "user"},
 *         {"type": "exact", "command": "<full command string>", "addedAt": "<iso8601>", "source": "user"},
 *         {"command": "<legacy v1 entry, no type>", "addedAt": "<iso8601>", "source": "user"}
 *       ]
 *     }
 *
 * Matching semantics (frozen; the writing side generates rules under the same
 * semantics):
 *
 * - Legacy v1 entries (no "type", or any entry in a version-1 file): the
 *   whole trimmed command string must be byte-identical to the rule; they
 *   never cover individual segments of a chained command.
 * - Whole-command exact rules run FIRST: if the trimmed command equals any
 *   exact/legacy rule, it matches outright (keeps heredoc-containing commands
 *   stored as whole-string exact rules working, with no segment parsing).
 * - Otherwise the command is split into segments on && / || / ; / | /
 *   newlines (quote-aware, identical to HitlPolicy::splitSegments), leading
 *   VAR=value assignments are stripped from each segment, and EVERY non-empty
 *   segment must hit at least one rule for the command to match — so
 *   "git commit && rm -rf /" never slips through a "git commit" prefix.
 *   - A segment hits an exact rule when its normalized form equals the rule.
 *   - A segment hits a prefix rule when its tokens (split on whitespace,
 *     quotes stripped from token ends) start with the rule's tokens, compared
 *     literally one by one.
 *
 * Everything is fail-closed: a missing, unreadable, corrupt, unknown-version,
 * or wrongly-shaped file loads as an empty allowlist and never throws.
 *
 * @internal
 */
final class HitlAllowlist
{
    /** @var array<string, true> Legacy v1 entries: whole-command exact match only. */
    private array $legacyCommands = [];

    /** @var array<string, true> v2 exact rules: whole-command and per-segment exact match. */
    private array $exactCommands = [];

    /** @var list<list<string>> Prefix rules, each a non-empty token sequence. */
    private array $prefixes = [];

    private function __construct() {}

    /** Empty allowlist; matches nothing. */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Load rules from a JSON file. Never throws: any failure yields an
     * empty allowlist so the decider degrades to the normal review path.
     */
    public static function fromFile(string $path): self
    {
        $list = new self();
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return $list;
            }
            $raw = file_get_contents($path);
            if (! is_string($raw) || trim($raw) === '') {
                return $list;
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                return $list;
            }
            $version = $data['version'] ?? null;
            if ($version !== 1 && $version !== 2) {
                return $list;
            }
            $rules = $data['rules'] ?? null;
            if (! is_array($rules)) {
                return $list;
            }
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue; // malformed entry: skip, keep the rest.
                }
                $type = $rule['type'] ?? 'exact';
                if ($type === 'prefix') {
                    if ($version !== 2) {
                        continue; // prefix rules require the v2 envelope.
                    }
                    $tokens = $rule['tokens'] ?? null;
                    if (! is_array($tokens)) {
                        continue;
                    }
                    $prefix = [];
                    $valid = true;
                    foreach ($tokens as $token) {
                        if (! is_string($token) || trim($token) === '') {
                            $valid = false; // malformed token list: skip the rule.
                            break;
                        }
                        $prefix[] = trim($token);
                    }
                    if (! $valid || $prefix === []) {
                        continue;
                    }
                    $list->prefixes[] = $prefix;
                    continue;
                }
                if ($type !== 'exact') {
                    continue; // unknown rule type: skip, keep the rest.
                }
                $command = $rule['command'] ?? null;
                if (! is_string($command) || trim($command) === '') {
                    continue;
                }
                if ($version === 2 && isset($rule['type'])) {
                    // Explicit v2 exact rule: whole-command AND per-segment.
                    $list->exactCommands[trim($command)] = true;
                } else {
                    // Legacy v1 entry (no type, or any version-1 entry):
                    // whole-command equality only, preserving v1 behavior.
                    $list->legacyCommands[trim($command)] = true;
                }
            }
        } catch (\Throwable) {
            return new self();
        }

        return $list;
    }

    /**
     * Match a command against the saved rules.
     *
     * @return array{type: 'exact'|'prefix', tokens?: list<string>}|null
     *         Hit details for the decider reason — 'prefix' carries the first
     *         prefix rule that contributed to the match — or null when the
     *         command is not allowlisted.
     */
    public function match(string $command): ?array
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return null;
        }

        // 1. Whole-command exact first (v1 behavior; keeps heredoc commands
        //    stored as whole-string exact rules matching without segment
        //    parsing). Legacy v1 entries and v2 exact rules both apply here.
        if (isset($this->legacyCommands[$trimmed]) || isset($this->exactCommands[$trimmed])) {
            return ['type' => 'exact'];
        }

        // 2. Segment coverage: every non-empty segment must hit a v2 rule
        //    (exact or prefix). Legacy v1 entries intentionally do not cover
        //    individual segments — they keep their whole-command semantics.
        $segments = HitlPolicy::splitSegments($trimmed);
        if ($segments === []) {
            return null;
        }
        $firstPrefix = null;
        foreach ($segments as $segment) {
            $hit = $this->segmentHit($segment);
            if ($hit === null) {
                return null; // one uncovered segment poisons the whole command.
            }
            if ($hit['type'] === 'prefix' && $firstPrefix === null) {
                $firstPrefix = $hit['tokens'];
            }
        }

        return $firstPrefix !== null
            ? ['type' => 'prefix', 'tokens' => $firstPrefix]
            : ['type' => 'exact'];
    }

    /**
     * Boolean lookup kept for the v1 call shape; see match() for hit details.
     */
    public function matches(string $command): bool
    {
        return $this->match($command) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->legacyCommands === [] && $this->exactCommands === [] && $this->prefixes === [];
    }

    /**
     * Match one shell segment against v2 rules: leading VAR=value assignments
     * are stripped, then a segment hits an exact rule when the trimmed
     * remainder is byte-identical to it, or a prefix rule when its tokens
     * (whitespace split, quotes trimmed from token ends) start with the
     * rule's tokens.
     *
     * @return array{type: 'exact'|'prefix', tokens?: list<string>}|null
     */
    private function segmentHit(string $segment): ?array
    {
        $bare = self::stripEnvAssignments($segment);
        if ($bare === '') {
            return null;
        }
        if (isset($this->exactCommands[$bare])) {
            return ['type' => 'exact'];
        }

        $tokens = preg_split('/\s+/', $bare) ?: [];
        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => trim($token, "\"'"),
            $tokens,
        ), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return null;
        }
        foreach ($this->prefixes as $prefix) {
            if (array_slice($tokens, 0, count($prefix)) === $prefix) {
                return ['type' => 'prefix', 'tokens' => $prefix];
            }
        }

        return null;
    }

    /**
     * Remove leading VAR=value environment assignments from a segment,
     * honoring quoted values (FOO="a b"), and trim the remainder.
     */
    private static function stripEnvAssignments(string $segment): string
    {
        $bare = trim($segment);
        while (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|\'[^\']*\'|\S+)\s+/', $bare, $m) === 1) {
            $bare = substr($bare, strlen($m[0]));
        }

        return trim($bare);
    }
}
