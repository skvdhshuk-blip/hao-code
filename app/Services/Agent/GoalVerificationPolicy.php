<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Builds the instruction that asks the model to check its answer against the goal.
 *
 * The loop ends when the model stops calling tools, which it will happily do while
 * a stated goal is only partly met. Confronting it once with the goal and its own
 * answer is enough to catch the common case of stopping early.
 *
 * @internal
 */
final class GoalVerificationPolicy
{
    public const MARKER = '[haocode:goal-check]';

    private const RESULT_CHAR_LIMIT = 4000;

    public function __construct(
        private readonly string $goal,
        private readonly int $maxRounds = 1,
    ) {}

    /** Returns null once the allowance is spent, so the run can finish. */
    public function instruction(string $finalText, int $roundsUsed): ?string
    {
        if ($roundsUsed >= $this->maxRounds) {
            return null;
        }

        $result = trim($finalText);
        if (mb_strlen($result) > self::RESULT_CHAR_LIMIT) {
            $result = mb_substr($result, 0, self::RESULT_CHAR_LIMIT)."\n… (truncated)";
        }

        return self::MARKER."\n"
            ."# Goal check\n\n"
            ."The goal for this run was:\n<goal>\n{$this->goal}\n</goal>\n\n"
            ."Your last response was:\n<result>\n{$result}\n</result>\n\n"
            .'Is the goal fully achieved? If anything is missing, incomplete, or unverified, '
            .'continue working now: use tools to finish the remaining parts, then give the final '
            .'answer. If the goal is achieved, restate the complete final answer (it is what the '
            .'caller receives).';
    }
}
