<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Services\Run\RunStatus;

/** @internal */
final class AgentRunOutcome
{
    public function __construct(
        public readonly string $text,
        public readonly RunStatus $status,
        public readonly RunTerminationReason $terminationReason,
    ) {}

    public static function normal(string $text): self
    {
        return new self($text, RunStatus::Completed, RunTerminationReason::Normal);
    }

    public static function cancelled(): self
    {
        return new self('(aborted)', RunStatus::Cancelled, RunTerminationReason::Cancelled);
    }

    public static function budgetExhausted(string $summary): self
    {
        return new self(
            '(Cost limit reached: '.$summary.')',
            RunStatus::Cancelled,
            RunTerminationReason::BudgetExhausted,
        );
    }

    public static function turnLimit(string $text, bool $repeatedToolFailure = false): self
    {
        return new self(
            $text,
            RunStatus::Completed,
            $repeatedToolFailure
                ? RunTerminationReason::RepeatedToolFailure
                : RunTerminationReason::TurnLimit,
        );
    }
}
