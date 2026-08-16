<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunStateStoreFactory
{
    public static function make(
        string $driver,
        string $sessionPath,
        ?string $databasePath = null,
    ): RunStateStoreInterface {
        return match (strtolower(trim($driver))) {
            'jsonl' => new JsonlRunStateStore($sessionPath),
            'sqlite' => new SqliteRunStateStore(
                self::databasePath($databasePath, $sessionPath),
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported run store '{$driver}'. Expected jsonl or sqlite.",
            ),
        };
    }

    private static function databasePath(?string $path, string $sessionPath): string
    {
        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        return dirname(rtrim($sessionPath, '/\\')).DIRECTORY_SEPARATOR.'run-state.sqlite';
    }
}
