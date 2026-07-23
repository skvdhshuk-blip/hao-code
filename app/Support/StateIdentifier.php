<?php

namespace HaoCode\Support;

/**
 * Validates identifiers before they are used as persistent-state keys.
 *
 * @internal
 */
final class StateIdentifier
{
    private const ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/D';

    private const TEAM_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,31}\z/D';

    public static function backgroundAgentId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Invalid background agent ID.');
        }

        return $id;
    }

    public static function taskId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Invalid task ID.');
        }

        return $id;
    }

    public static function teamName(string $name): string
    {
        if (preg_match(self::TEAM_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Invalid team name.');
        }

        return $name;
    }
}
