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

        $this->assertSame(15, $acc->getInputTokens());
        $this->assertSame(10, $acc->getOutputTokens());
        $this->assertSame(1, $acc->getCacheCreationTokens());
        $this->assertSame(6, $acc->getCacheReadTokens());
    }

    public function test_ignores_negative_deltas(): void
    {
        $acc = new UsageAccumulator;
        $acc->add(-5, -1, -2, -3);
        $this->assertSame(0, $acc->getInputTokens());
        $this->assertSame(0, $acc->getOutputTokens());
    }
}
