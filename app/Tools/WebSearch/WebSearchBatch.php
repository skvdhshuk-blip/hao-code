<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

/** @internal */
final class WebSearchBatch
{
    /** @param list<EngineOutcome> $outcomes */
    public function __construct(
        public readonly array $outcomes,
        public readonly bool $aborted = false,
    ) {}

    public static function aborted(): self
    {
        return new self([], true);
    }
}
