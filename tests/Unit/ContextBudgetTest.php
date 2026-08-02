<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\ContextBudget;
use PHPUnit\Framework\TestCase;

class ContextBudgetTest extends TestCase
{
    public function test_safe_input_limit_reserves_output_and_safety_margin(): void
    {
        $this->assertSame(113600, ContextBudget::safeInputLimit(128000, 8000));
        $this->assertSame(174000, ContextBudget::safeInputLimit(200000, 16000));
        $this->assertSame(566000, ContextBudget::safeInputLimit(1_000_000, 384_000));
    }

    public function test_safe_input_limit_never_becomes_zero_or_negative(): void
    {
        $this->assertSame(190000, ContextBudget::safeInputLimit(200000, 0));
        $this->assertSame(1, ContextBudget::safeInputLimit(200000, 300000));
    }
}
