<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Canonical, normalized limits for one agent invocation.
 *
 * Tool-specific execution timeouts deliberately stay on their tool/backend
 * contracts; they use different clocks and cannot be treated as model turns.
 *
 * @internal
 */
final class RunLimits
{
    public function __construct(
        public readonly int $maxTurns,
        public readonly ?int $maxTokens = null,
        public readonly ?float $maxBudgetUsd = null,
        public readonly bool $thinkingEnabled = false,
        public readonly ?int $thinkingBudget = null,
    ) {
        if ($this->maxTurns < 1) {
            throw new \InvalidArgumentException('maxTurns must be >= 1.');
        }
        if ($this->maxBudgetUsd !== null
            && (! is_finite($this->maxBudgetUsd) || $this->maxBudgetUsd < 0)) {
            throw new \InvalidArgumentException('maxBudgetUsd must be a non-negative finite amount.');
        }
    }

    /**
     * A child definition that only controls its model-turn count.
     */
    public static function turns(int $maxTurns): self
    {
        return new self($maxTurns);
    }

    /**
     * A resumed run may tighten, but never widen, the current budget.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function budgetForResume(?array $snapshot): ?float
    {
        $snapshotBudget = $snapshot['budget_limit_usd'] ?? null;
        $snapshotLimit = is_numeric($snapshotBudget) && (float) $snapshotBudget >= 0
            ? (float) $snapshotBudget
            : null;

        if ($snapshotLimit !== null && $this->maxBudgetUsd !== null) {
            return min($snapshotLimit, $this->maxBudgetUsd);
        }

        return $snapshotLimit ?? $this->maxBudgetUsd;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public function turnsForResume(?array $snapshot): int
    {
        $remaining = $snapshot['max_turns_remaining'] ?? null;

        return is_int($remaining) && $remaining > 0
            ? min($remaining, $this->maxTurns)
            : $this->maxTurns;
    }
}
