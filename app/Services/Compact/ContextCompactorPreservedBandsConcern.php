<?php

namespace HaoCode\Services\Compact;

use HaoCode\Services\Agent\ContextBudget;

trait ContextCompactorPreservedBandsConcern
{

    /**
     * Get the structured compact system prompt: 9-section summary plus the
     * graded keep-list the tier selection later draws from.
     */
    private function getCompactSystemPrompt(): array
    {
        return GradedCompactionPrompt::systemPrompt();
    }

    /**
     * Pick how many priority bands of verbatim originals fit alongside the
     * history that will remain, and render them.
     *
     * This is the tier selection: none, one, two or all three bands. Bands are
     * taken whole and in priority order, so a partial fit degrades predictably
     * instead of truncating the middle of an original.
     *
     * @param  array<int, list<int>>  $bands
     * @param  array<int, array<string, mixed>>  $oldMessages
     * @param  array<int, array<string, mixed>>  $remaining
     * @return array{0: string, 1: int} rendered text, number of bands kept
     */
    private function renderPreservedBands(array $bands, array $oldMessages, array $remaining): array
    {
        if ($bands === []) {
            return ['', 0];
        }

        $budget = min(
            $this->scaleFromDefaultWindow(self::TIER_MAX_PRESERVED_TOKENS),
            $this->effectiveContextWindow()
                - $this->scaleFromDefaultWindow(self::TIER_OVERHEAD_RESERVE_TOKENS)
                - ContextBudget::estimateTokens([], $remaining, []),
        );
        if ($budget <= 0) {
            return ['', 0];
        }

        $sections = [];
        $used = 0;

        foreach ($bands as $indices) {
            $text = GradedCompactionPrompt::renderBand(
                $indices,
                $oldMessages,
                self::TIER_PER_ITEM_CAP_CHARS,
            );
            if ($text === '') {
                continue;
            }

            $cost = ContextBudget::estimateTokens([], [['role' => 'user', 'content' => $text]], []);
            if ($used + $cost > $budget) {
                break;
            }

            $sections[] = $text;
            $used += $cost;
        }

        if ($sections === []) {
            return ['', 0];
        }

        $kept = count($sections);
        $header = "[Preserved verbatim from compacted history — {$kept} priority band(s) the model marked as load-bearing]";

        return [$header."\n\n".implode("\n\n", $sections), $kept];
    }
}
