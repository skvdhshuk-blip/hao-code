<?php

namespace Tests\Unit;

use HaoCode\Services\Compact\ContextCompactor;

/**
 * compact() with a graded keep-list: the model names the originals that must
 * survive verbatim, and tier selection decides how many priority bands of them
 * fit next to the rebuilt history.
 */
trait ContextCompactorTestPreservedBandsConcern
{
    /** Six alternating messages; compact(keepLast: 2) summarizes indices 0-3. */
    private function makeBandHistory(string $marker = 'CANARY_TOKEN'): \HaoCode\Services\Agent\MessageHistory
    {
        return $this->makeHistory([
            ['role' => 'user', 'content' => 'old request'],
            ['role' => 'assistant', 'content' => "load bearing {$marker} detail"],
            ['role' => 'user', 'content' => 'more chatter'],
            ['role' => 'assistant', 'content' => 'more replies'],
            ['role' => 'user', 'content' => 'recent request'],
            ['role' => 'assistant', 'content' => 'recent reply'],
        ]);
    }

    public function test_compact_preserves_band_one_originals_verbatim(): void
    {
        $history = $this->makeBandHistory();
        $qe = $this->makeQueryEngine('<summary>Structured summary</summary><keep band="1">1</keep>');

        $result = (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $note = $history->getMessagesForApi()[0]['content'];
        $this->assertStringContainsString('<preserved index="1" role="assistant">', $note);
        $this->assertStringContainsString('load bearing CANARY_TOKEN detail', $note);
        $this->assertStringContainsString('Preserved 1 priority band(s)', $result);
    }

    public function test_compact_keeps_summary_before_preserved_block(): void
    {
        $history = $this->makeBandHistory();
        $qe = $this->makeQueryEngine('<summary>Structured summary</summary><keep band="1">1</keep>');

        (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $note = $history->getMessagesForApi()[0]['content'];
        $this->assertLessThan(
            mb_strpos($note, '<preserved'),
            mb_strpos($note, 'Structured summary'),
            'The summary must precede the preserved originals.',
        );
        $this->assertStringContainsString('[End of Summary.', $note);
    }

    public function test_compact_does_not_leak_keep_blocks_into_the_summary(): void
    {
        $history = $this->makeBandHistory();
        // Keep blocks emitted inside <summary> — the model ignoring the layout.
        $qe = $this->makeQueryEngine('<summary>Body text<keep band="1">1</keep></summary>');

        (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $note = $history->getMessagesForApi()[0]['content'];
        $this->assertStringNotContainsString('<keep', $note);
        $this->assertStringContainsString('Body text', $note);
    }

    public function test_compact_skips_file_reinjection_when_originals_were_preserved(): void
    {
        $history = $this->makeBandHistory();
        $qe = $this->makeQueryEngine('<summary>S</summary><keep band="1">1</keep>');

        $result = (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $this->assertStringNotContainsString('Re-injected', $result);
    }

    public function test_compact_falls_back_to_previous_behaviour_without_keep_blocks(): void
    {
        $history = $this->makeBandHistory();
        $qe = $this->makeQueryEngine('<summary>Plain summary, no keep list</summary>');

        $result = (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $note = $history->getMessagesForApi()[0]['content'];
        $this->assertStringNotContainsString('Preserved', $result);
        $this->assertStringNotContainsString('<preserved', $note);
        $this->assertStringContainsString('Plain summary', $note);
    }

    public function test_tier_selection_drops_lower_bands_that_do_not_fit(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'small but critical'],
            ['role' => 'assistant', 'content' => str_repeat('B', 20_000)], // ~5k tokens
            ['role' => 'user', 'content' => 'filler'],
            ['role' => 'assistant', 'content' => 'filler'],
            ['role' => 'user', 'content' => 'recent request'],
            ['role' => 'assistant', 'content' => 'recent reply'],
        ]);
        // 20k window → preserved-original budget scales to ~4k tokens, so band 1
        // fits and band 2 cannot.
        $qe = $this->makeQueryEngine('<summary>S</summary><keep band="1">0</keep><keep band="2">1</keep>');

        $result = (new ContextCompactor($qe, null, 20_000))->compact($history, keepLast: 2);

        $note = $history->getMessagesForApi()[0]['content'];
        $this->assertStringContainsString('small but critical', $note);
        $this->assertStringNotContainsString(str_repeat('B', 100), $note);
        $this->assertStringContainsString('Preserved 1 priority band(s)', $result);
    }

    public function test_tier_selection_preserves_nothing_when_the_remaining_history_fills_the_window(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'small but critical'],
            ['role' => 'assistant', 'content' => 'reply'],
            ['role' => 'user', 'content' => 'filler'],
            ['role' => 'assistant', 'content' => 'filler'],
            ['role' => 'user', 'content' => 'recent request'],
            ['role' => 'assistant', 'content' => str_repeat('C', 100_000)], // ~25k tokens
        ]);
        $qe = $this->makeQueryEngine('<summary>S</summary><keep band="1">0</keep>');

        $result = (new ContextCompactor($qe, null, 20_000))->compact($history, keepLast: 2);

        $this->assertStringNotContainsString('Preserved', $result);
        $this->assertStringNotContainsString('<preserved', $history->getMessagesForApi()[0]['content']);
    }

    public function test_summary_request_keeps_both_ends_of_an_oversized_transcript(): void
    {
        $captured = '';
        $processor = $this->createMock(\HaoCode\Services\Agent\StreamProcessor::class);
        $processor->method('getAccumulatedText')->willReturn('<summary>S</summary>');
        $qe = $this->createMock(\HaoCode\Services\Agent\QueryEngine::class);
        $qe->method('query')->willReturnCallback(
            function (array $systemPrompt, array $messages) use (&$captured, $processor) {
                $captured = $messages[0]['content'];

                return $processor;
            },
        );

        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'HEAD_MARKER opening request'],
            ['role' => 'assistant', 'content' => str_repeat('z', 80_000)],
            ['role' => 'user', 'content' => 'TAIL_MARKER latest state'],
            ['role' => 'assistant', 'content' => 'ack'],
            ['role' => 'user', 'content' => 'recent request'],
            ['role' => 'assistant', 'content' => 'recent reply'],
        ]);

        (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $this->assertStringContainsString('HEAD_MARKER', $captured);
        $this->assertStringContainsString('TAIL_MARKER', $captured);
        $this->assertStringContainsString('middle of transcript truncated', $captured);
    }

    public function test_the_summary_request_advertises_no_tools(): void
    {
        $captured = null;
        $processor = $this->createMock(\HaoCode\Services\Agent\StreamProcessor::class);
        $processor->method('getAccumulatedText')->willReturn('<summary>S</summary>');
        $qe = $this->createMock(\HaoCode\Services\Agent\QueryEngine::class);
        $qe->method('query')->willReturnCallback(
            function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
                ?array $toolsOverride = null,
            ) use (&$captured, $processor) {
                $captured = $toolsOverride;

                return $processor;
            },
        );

        (new ContextCompactor($qe))->compact($this->makeBandHistory(), keepLast: 2);

        // Not null: null makes QueryEngine fall back to the whole registry, and
        // the compact prompt has just told the model it may not call anything.
        $this->assertSame([], $captured);
    }

    public function test_messages_to_text_labels_indices_when_requested(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'second']]],
        ];

        $labelled = $this->invoke('messagesToText', $this->makeCompactor(), $messages, true);
        $this->assertStringContainsString('[#0] user: first', $labelled);
        $this->assertStringContainsString('[#1] assistant:', $labelled);

        $plain = $this->invoke('messagesToText', $this->makeCompactor(), $messages);
        $this->assertStringNotContainsString('[#0]', $plain);
    }
}
