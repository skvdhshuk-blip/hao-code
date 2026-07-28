<?php

declare(strict_types=1);

namespace HaoCode\Services\Cost;

/**
 * Shared mutable token counters for a root run and nested AgentAsTool children.
 *
 * @internal
 */
final class UsageAccumulator
{
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheCreationTokens = 0;
    private int $cacheReadTokens = 0;
    private float $cost = 0.0;

    public function add(
        int $inputTokens,
        int $outputTokens,
        int $cacheCreationTokens = 0,
        int $cacheReadTokens = 0,
    ): void {
        $this->inputTokens += max(0, $inputTokens);
        $this->outputTokens += max(0, $outputTokens);
        $this->cacheCreationTokens += max(0, $cacheCreationTokens);
        $this->cacheReadTokens += max(0, $cacheReadTokens);
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->cacheCreationTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function addCost(float $cost): void
    {
        $this->cost += max(0.0, $cost);
    }

    public function ensureCostAtLeast(float $cost): void
    {
        $this->cost = max($this->cost, max(0.0, $cost));
    }

    public function getCost(): float
    {
        return $this->cost;
    }

    public function reset(): void
    {
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->cacheCreationTokens = 0;
        $this->cacheReadTokens = 0;
        $this->cost = 0.0;
    }
}
