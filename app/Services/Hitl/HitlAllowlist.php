<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

/**
 * User-saved "always allow" rules for the smart HITL mode.
 *
 * Ports the codex "always allow" concept (prefix rules persisted to
 * ~/.codex/rules/default.rules) into the SDK as an exact-match v1: a Bash
 * action whose trimmed command string is exactly equal to a saved rule is
 * approved without the rule classifier or the guardian review. Because the
 * user explicitly saved the rule, a match intentionally overrides red lines
 * (user sovereignty); everything unmatched falls through to the normal
 * classifier untouched.
 *
 * The rule file is JSON with a frozen format:
 *
 *     {
 *       "version": 1,
 *       "rules": [
 *         {"command": "<full command string>", "addedAt": "<iso8601>", "source": "user"}
 *       ]
 *     }
 *
 * Everything is fail-closed: a missing, unreadable, corrupt, or
 * wrong-version file loads as an empty allowlist and never throws.
 *
 * @internal
 */
final class HitlAllowlist
{
    /** @var array<string, true> Exact-match set of trimmed command strings. */
    private array $commands = [];

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
            if (! is_array($data) || ($data['version'] ?? null) !== 1) {
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
                $command = $rule['command'] ?? null;
                if (! is_string($command) || trim($command) === '') {
                    continue;
                }
                $list->commands[trim($command)] = true;
            }
        } catch (\Throwable) {
            return new self();
        }

        return $list;
    }

    /**
     * Exact-match lookup: the trimmed command must be byte-identical to a
     * saved rule. No prefix or wildcard matching in v1 (intentionally
     * conservative).
     */
    public function matches(string $command): bool
    {
        return isset($this->commands[trim($command)]);
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }
}
