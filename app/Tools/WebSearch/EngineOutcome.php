<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\RawSearchResult;

/** @internal */
final class EngineOutcome
{
    /** @param list<RawSearchResult> $results */
    public function __construct(
        public readonly EngineInterface $engine,
        public readonly array $results,
        public readonly EngineStat $stat,
    ) {}
}
