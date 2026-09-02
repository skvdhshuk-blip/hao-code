<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Turn-boundary producer that restates the task during a long run.
 *
 * Task context is injected once, on the first user turn. Thirty turns and one
 * compaction later the original request can be the least prominent thing in the
 * window, and the model drifts or stops early. Restating it periodically is the
 * cheapest fix: a short recap often, the full request occasionally.
 *
 * @internal
 */
final class GoalReminderPolicy
{
    private const RECAP_CHAR_LIMIT = 300;

    private const FULL_CHAR_LIMIT = 8000;

    /**
     * @param  \Closure(): ?string  $promptProvider  Supplies the run's original user prompt.
     */
    public function __construct(
        private readonly ?string $goal,
        private readonly int $recapEvery,
        private readonly int $fullEvery,
        private readonly \Closure $promptProvider,
    ) {}

    public function __invoke(int $completedTurn, string $sessionId): ?string
    {
        if ($completedTurn <= 0) {
            return null;
        }

        $prompt = ($this->promptProvider)();

        if ($this->fullEvery > 0 && $completedTurn % $this->fullEvery === 0) {
            $full = $this->truncate(trim((string) ($prompt ?? $this->goal ?? '')), self::FULL_CHAR_LIMIT);
            if ($full !== '') {
                return "# Original task (after turn {$completedTurn})\n\n"
                    ."This is the complete original request, restated so it stays in context:\n\n"
                    ."<original_task>\n{$full}\n</original_task>\n\n"
                    .'Continue working toward it and finish it.';
            }
        }

        if ($this->recapEvery > 0 && $completedTurn % $this->recapEvery === 0) {
            $recap = $this->recap($prompt);
            if ($recap !== '') {
                return "# Task reminder (after turn {$completedTurn})\n\n"
                    ."You are still working on this task: {$recap}\n\n"
                    .'Keep working until it is fully complete. Do not stop early, do not drift '
                    .'into unrelated work, and do not ask for confirmation you already have.';
            }
        }

        return null;
    }

    private function recap(?string $prompt): string
    {
        // An explicit goal is a better recap than the opening words of the prompt.
        $source = trim((string) ($this->goal ?? ''));
        if ($source === '') {
            $source = trim((string) ($prompt ?? ''));
        }

        return $this->truncate((string) preg_replace('/\s+/u', ' ', $source), self::RECAP_CHAR_LIMIT);
    }

    private function truncate(string $text, int $limit): string
    {
        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)).'…';
    }
}
