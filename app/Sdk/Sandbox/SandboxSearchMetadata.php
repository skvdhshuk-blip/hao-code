<?php

namespace HaoCode\Sdk\Sandbox;

/** @internal */
final class SandboxSearchMetadata
{
    /** @var \WeakMap<object, array<string, mixed>>|null */
    private static ?\WeakMap $entries = null;

    public static function begin(object $backend): void
    {
        unset(self::map()[$backend]);
    }

    /** @param array<string, mixed> $metadata */
    public static function record(object $backend, array $metadata): void
    {
        self::map()[$backend] = $metadata;
    }

    /** @return array<string, mixed>|null */
    public static function consume(object $backend): ?array
    {
        $map = self::map();
        if (! isset($map[$backend])) {
            return null;
        }

        $metadata = $map[$backend];
        unset($map[$backend]);

        return $metadata;
    }

    private static function map(): \WeakMap
    {
        return self::$entries ??= new \WeakMap;
    }
}
