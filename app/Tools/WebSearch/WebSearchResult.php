<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

/** @internal */
final class WebSearchResult
{
    /**
     * @param list<string> $engines
     * @param array<string, int> $positions
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly array $engines,
        public readonly array $positions,
        public readonly float $score,
        public readonly string $dedupKey,
        public readonly int $bestPosition,
        public readonly int $highestQualityPriority,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(int $rank): array
    {
        return [
            'rank' => $rank,
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'engines' => $this->engines,
            'positions' => $this->positions,
            'score' => round($this->score, 6),
        ];
    }
}
