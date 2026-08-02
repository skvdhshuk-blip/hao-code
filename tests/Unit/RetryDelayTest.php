<?php

namespace Tests\Unit;

use HaoCode\Services\Api\RetryDelay;
use PHPUnit\Framework\TestCase;

final class RetryDelayTest extends TestCase
{
    public function test_jitter_stays_within_the_fallback_delay_cap(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $delay = RetryDelay::withJitter(8.0, 10.0);

            $this->assertGreaterThanOrEqual(6.4, $delay);
            $this->assertLessThanOrEqual(10.0, $delay);
        }
    }

    public function test_non_positive_delay_is_not_slept(): void
    {
        $this->assertSame(0.0, RetryDelay::withJitter(0.0, 10.0));
        $this->assertSame(0.0, RetryDelay::withJitter(2.0, 0.0));
    }
}
