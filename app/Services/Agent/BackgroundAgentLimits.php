<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** @internal */
final class BackgroundAgentLimits
{
    public function __construct(
        public readonly int $maxActivePerRun = 8,
        public readonly int $mailboxMaxMessages = 128,
        public readonly int $messageMaxBytes = 65_536,
        public readonly int $mailboxMaxBytes = 1_048_576,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("Background agent limit {$name} must be greater than zero.");
            }
        }
    }
}
