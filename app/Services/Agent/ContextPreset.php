<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** @internal */
final class ContextPreset
{
    public const CODING = 'coding';

    public const GENERIC = 'generic';

    public static function assertValid(string $preset): void
    {
        if (! in_array($preset, [self::CODING, self::GENERIC], true)) {
            throw new \InvalidArgumentException(
                "contextPreset must be 'coding' or 'generic'; got '{$preset}'.",
            );
        }
    }
}
