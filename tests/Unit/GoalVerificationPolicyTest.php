<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\GoalVerificationPolicy;
use PHPUnit\Framework\TestCase;

class GoalVerificationPolicyTest extends TestCase
{
    public function test_it_confronts_the_model_with_the_goal_and_its_answer(): void
    {
        $policy = new GoalVerificationPolicy('All tests pass on CI');

        $instruction = $policy->instruction('I updated the config.', 0);

        $this->assertStringContainsString(GoalVerificationPolicy::MARKER, $instruction);
        $this->assertStringContainsString('# Goal check', $instruction);
        $this->assertStringContainsString('All tests pass on CI', $instruction);
        $this->assertStringContainsString('I updated the config.', $instruction);
    }

    public function test_the_allowance_is_spent_after_the_configured_rounds(): void
    {
        $policy = new GoalVerificationPolicy('goal', 1);

        $this->assertNotNull($policy->instruction('answer', 0));
        $this->assertNull($policy->instruction('answer', 1));
    }

    public function test_more_rounds_can_be_allowed(): void
    {
        $policy = new GoalVerificationPolicy('goal', 2);

        $this->assertNotNull($policy->instruction('answer', 0));
        $this->assertNotNull($policy->instruction('answer', 1));
        $this->assertNull($policy->instruction('answer', 2));
    }

    public function test_zero_rounds_never_asks(): void
    {
        $this->assertNull((new GoalVerificationPolicy('goal', 0))->instruction('answer', 0));
    }

    public function test_a_long_answer_is_truncated(): void
    {
        $policy = new GoalVerificationPolicy('goal');

        $instruction = $policy->instruction(str_repeat('x', 9000), 0);

        $this->assertStringContainsString('(truncated)', $instruction);
        $this->assertLessThan(5000, mb_strlen($instruction));
    }
}
