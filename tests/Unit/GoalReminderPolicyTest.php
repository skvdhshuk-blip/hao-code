<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\GoalReminderPolicy;
use PHPUnit\Framework\TestCase;

class GoalReminderPolicyTest extends TestCase
{
    private function policy(
        ?string $goal = null,
        int $recapEvery = 5,
        int $fullEvery = 10,
        ?string $prompt = 'Migrate the billing module to the new provider',
    ): GoalReminderPolicy {
        return new GoalReminderPolicy($goal, $recapEvery, $fullEvery, fn (): ?string => $prompt);
    }

    public function test_it_says_nothing_at_run_start(): void
    {
        $this->assertNull(($this->policy())(0, 'session-a'));
    }

    public function test_it_says_nothing_between_intervals(): void
    {
        $policy = $this->policy();

        $this->assertNull($policy(1, 'session-a'));
        $this->assertNull($policy(4, 'session-a'));
        $this->assertNull($policy(6, 'session-a'));
    }

    public function test_it_recaps_on_the_recap_interval(): void
    {
        $notice = ($this->policy())(5, 'session-a');

        $this->assertStringContainsString('# Task reminder (after turn 5)', $notice);
        $this->assertStringContainsString('Migrate the billing module', $notice);
        $this->assertStringNotContainsString('<original_task>', $notice);
    }

    public function test_the_full_interval_wins_when_both_are_due(): void
    {
        $notice = ($this->policy())(10, 'session-a');

        $this->assertStringContainsString('# Original task (after turn 10)', $notice);
        $this->assertStringContainsString('<original_task>', $notice);
        $this->assertStringNotContainsString('# Task reminder', $notice);
    }

    public function test_an_explicit_goal_is_preferred_over_the_prompt_for_the_recap(): void
    {
        $notice = ($this->policy(goal: 'Ship the migration with tests passing'))(5, 'session-a');

        $this->assertStringContainsString('Ship the migration with tests passing', $notice);
        $this->assertStringNotContainsString('Migrate the billing module', $notice);
    }

    public function test_a_long_prompt_is_collapsed_and_truncated_in_the_recap(): void
    {
        $prompt = "line one\n\nline two ".str_repeat('padding ', 100);
        $notice = ($this->policy(prompt: $prompt))(5, 'session-a');

        $this->assertStringContainsString('line one line two', $notice);
        $this->assertStringContainsString('…', $notice);
        $this->assertLessThan(600, mb_strlen($notice));
    }

    public function test_zero_intervals_disable_each_half(): void
    {
        $recapOnly = $this->policy(recapEvery: 3, fullEvery: 0);
        $this->assertStringContainsString('# Task reminder', $recapOnly(3, 'session-a'));
        $this->assertNull($recapOnly(10, 'session-a'));

        $fullOnly = $this->policy(recapEvery: 0, fullEvery: 4);
        $this->assertNull($fullOnly(5, 'session-a'));
        $this->assertStringContainsString('# Original task', $fullOnly(4, 'session-a'));
    }

    public function test_it_says_nothing_without_a_prompt_or_a_goal(): void
    {
        $policy = $this->policy(prompt: null);

        $this->assertNull($policy(5, 'session-a'));
        $this->assertNull($policy(10, 'session-a'));
    }

    public function test_the_goal_stands_in_for_a_missing_prompt(): void
    {
        $policy = $this->policy(goal: 'Finish the migration', prompt: null);

        $this->assertStringContainsString('Finish the migration', $policy(5, 'session-a'));
        $this->assertStringContainsString('Finish the migration', $policy(10, 'session-a'));
    }
}
