<?php

namespace Tests\Unit;

use HaoCode\Services\Cost\UsageAccumulator;
use PHPUnit\Framework\TestCase;

class UsageAccumulatorTest extends TestCase
{
    public function test_adds_and_exposes_totals(): void
    {
        $acc = new UsageAccumulator;
        $acc->add(10, 3, 1, 2);
        $acc->add(5, 7, 0, 4);
        $acc->addCost(0.25);
        $acc->addCost(0.5);

        $this->assertSame(15, $acc->getInputTokens());
        $this->assertSame(10, $acc->getOutputTokens());
        $this->assertSame(1, $acc->getCacheCreationTokens());
        $this->assertSame(6, $acc->getCacheReadTokens());
        $this->assertSame(0.75, $acc->getCost());
    }

    public function test_ignores_negative_deltas(): void
    {
        $acc = new UsageAccumulator;
        $acc->add(-5, -1, -2, -3);
        $acc->addCost(-1.0);
        $this->assertSame(0, $acc->getInputTokens());
        $this->assertSame(0, $acc->getOutputTokens());
        $this->assertSame(0.0, $acc->getCost());
    }

    public function test_ensure_cost_and_reset(): void
    {
        $acc = new UsageAccumulator;
        $acc->add(10, 2);
        $acc->addCost(0.25);
        $acc->ensureCostAtLeast(0.5);
        $acc->ensureCostAtLeast(0.1);

        $this->assertSame(0.5, $acc->getCost());

        $acc->reset();
        $this->assertSame(0, $acc->getInputTokens());
        $this->assertSame(0, $acc->getOutputTokens());
        $this->assertSame(0.0, $acc->getCost());
    }
}
