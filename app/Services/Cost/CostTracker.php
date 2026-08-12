<?php

namespace HaoCode\Services\Cost;

use HaoCode\Services\Settings\ModelCatalog;

/**
 * Tracks spending per session with configurable thresholds and warnings.
 */
class CostTracker
{
    private float $totalCost = 0.0;
    private float $warnThreshold;
    private float $stopThreshold;
    private bool $warnedAtThreshold = false;

    /** @var callable|null */
    private $onWarning = null;

    public function __construct(
        ?float $warnThreshold = null,
        ?float $stopThreshold = null,
        private readonly ?BudgetLedger $budgetLedger = null,
        private readonly ?UsageAccumulator $usageAccumulator = null,
    ) {
        $sharedLimit = $budgetLedger?->getLimit();
        $this->warnThreshold = $warnThreshold
            ?? ($sharedLimit !== null ? $sharedLimit * 0.8 : (float) ($_ENV['HAOCODE_COST_WARN'] ?? 5.00));
        $this->stopThreshold = $stopThreshold
            ?? $sharedLimit
            ?? (float) ($_ENV['HAOCODE_COST_STOP'] ?? 50.00);
    }

    private string $currentModel = ModelCatalog::SONNET;

    private string $providerType = 'anthropic';

    public function setModel(string $model): void
    {
        $this->currentModel = $model;
    }

    public function setProviderType(string $providerType): void
    {
        $this->providerType = $providerType;
    }

    /**
     * Synchronize the complete pricing identity immediately before a provider
     * request. Budgeted runs fail closed when that identity has no trusted
     * price instead of silently recording zero cost.
     *
     * @internal
     */
    public function setProviderContext(string $providerType, string $model): void
    {
        if ($this->budgetLedger !== null && ModelCatalog::pricingFor($providerType, $model) === null) {
            throw new \RuntimeException(
                "Cost budget requires pricing for model \"{$model}\" "
                ."on provider type \"{$providerType}\". No trusted pricing is configured.",
            );
        }

        $this->providerType = $providerType;
        $this->currentModel = $model;
    }

    /** @internal */
    public function setResponseModel(string $model): void
    {
        // Provider responses may report a deployment alias rather than the
        // requested catalog model. A budgeted request was already validated
        // against its resolved request identity before I/O; keep that trusted
        // price when the response alias is unknown instead of dropping usage
        // accounting or failing only after the external spend occurred.
        if ($this->budgetLedger !== null
            && ModelCatalog::pricingFor($this->providerType, $model) === null) {
            return;
        }

        $this->currentModel = $model;
    }

    /**
     * Get pricing for the current model.
     *
     * @return array{input: float, output: float, cache_write: float, cache_read: float}|null
     */
    private function getPricing(): ?array
    {
        return ModelCatalog::pricingFor($this->providerType, $this->currentModel);
    }

    public function isPricingAvailable(): bool
    {
        return $this->getPricing() !== null;
    }

    /**
     * Add cost from a single API call.
     */
    public function addUsage(int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): void
    {
        $pricing = $this->getPricing();
        if ($pricing === null) {
            return;
        }

        $cost = (
            $inputTokens * $pricing['input'] +
            $outputTokens * $pricing['output'] +
            $cacheWriteTokens * $pricing['cache_write'] +
            $cacheReadTokens * $pricing['cache_read']
        ) / 1_000_000;

        $this->totalCost += $cost;
        $this->usageAccumulator?->addCost($cost);
        $globalCost = $this->budgetLedger?->add($cost)
            ?? $this->usageAccumulator?->getCost()
            ?? $this->totalCost;

        if (!$this->warnedAtThreshold && $globalCost >= $this->warnThreshold) {
            $this->warnedAtThreshold = true;
            if ($this->onWarning) {
                ($this->onWarning)($globalCost, $this->warnThreshold, 'warning');
            }
        }
    }

    /**
     * Set a flat cost (used when importing from AgentLoop's accumulated totals).
     */
    public function setTotalCost(float $cost): void
    {
        $this->totalCost = max(0.0, $cost);
        $this->usageAccumulator?->ensureCostAtLeast($this->totalCost);
        $globalCost = $this->budgetLedger?->ensureAtLeast($this->totalCost)
            ?? $this->usageAccumulator?->getCost()
            ?? $this->totalCost;
        $this->warnedAtThreshold = $globalCost >= $this->warnThreshold;
    }

    public function reset(): void
    {
        $this->totalCost = 0.0;
        $this->warnedAtThreshold = false;
    }

    public function getTotalCost(): float
    {
        return $this->budgetLedger?->getSpent()
            ?? $this->usageAccumulator?->getCost()
            ?? $this->totalCost;
    }

    /** @internal */
    public function getLocalTotalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * Check if the hard stop threshold has been exceeded.
     */
    public function shouldStop(): bool
    {
        return $this->budgetLedger?->shouldStop()
            ?? $this->getTotalCost() >= $this->stopThreshold;
    }

    /**
     * Check if the warning threshold has been reached.
     */
    public function shouldWarn(): bool
    {
        return $this->getTotalCost() >= $this->warnThreshold;
    }

    public function setOnWarning(callable $callback): void
    {
        $this->onWarning = $callback;
    }

    public function getWarnThreshold(): float
    {
        return $this->warnThreshold;
    }

    public function getStopThreshold(): float
    {
        return $this->stopThreshold;
    }

    public function setThresholds(float $warn, float $stop): void
    {
        $this->warnThreshold = $warn;
        $this->stopThreshold = $stop;
        $this->warnedAtThreshold = false;
    }

    /**
     * Get a summary string for display.
     */
    public function getSummary(): string
    {
        if (! $this->isPricingAvailable()) {
            return "Cost unavailable for model {$this->currentModel} ({$this->providerType})";
        }

        $cost = '$' . number_format($this->getTotalCost(), 2);
        $warn = '$' . number_format($this->warnThreshold, 2);
        $stop = '$' . number_format($this->stopThreshold, 2);
        return "Cost: {$cost} (warn at {$warn}, stop at {$stop})";
    }
}
