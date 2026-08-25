<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

/** @internal */
final class ConversationBootstrap
{
    /** @param array<string, mixed> $resumeSnapshot */
    public function __construct(
        public readonly array $resumeSnapshot,
    ) {}
}
