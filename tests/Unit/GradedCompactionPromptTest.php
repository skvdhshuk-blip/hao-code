<?php

namespace Tests\Unit;

use HaoCode\Services\Compact\GradedCompactionPrompt;
use PHPUnit\Framework\TestCase;

class GradedCompactionPromptTest extends TestCase
{
    // ─── parseBands ────────────────────────────────────────────────────────

    public function test_parse_bands_reads_all_three_bands(): void
    {
        $text = '<keep band="1">5,2</keep><keep band="2">7</keep><keep band="3">9</keep>';

        $bands = GradedCompactionPrompt::parseBands($text, 10);

        $this->assertSame([1 => [2, 5], 2 => [7], 3 => [9]], $bands);
    }

    public function test_parse_bands_returns_bands_in_priority_order(): void
    {
        $text = '<keep band="3">9</keep><keep band="1">1</keep>';

        $this->assertSame([1, 3], array_keys(GradedCompactionPrompt::parseBands($text, 10)));
    }

    public function test_parse_bands_keeps_first_claim_when_an_index_repeats(): void
    {
        $text = '<keep band="1">4</keep><keep band="2">4,6</keep>';

        $this->assertSame([1 => [4], 2 => [6]], GradedCompactionPrompt::parseBands($text, 10));
    }

    public function test_parse_bands_drops_out_of_range_indices(): void
    {
        $text = '<keep band="1">2,99,-1</keep>';

        $this->assertSame([1 => [2]], GradedCompactionPrompt::parseBands($text, 5));
    }

    public function test_parse_bands_caps_indices_per_band(): void
    {
        $text = '<keep band="1">0,1,2,3,4,5,6,7,8</keep>';

        $bands = GradedCompactionPrompt::parseBands($text, 20);

        $this->assertCount(6, $bands[1]);
    }

    public function test_parse_bands_ignores_unknown_band_numbers(): void
    {
        $text = '<keep band="0">1</keep><keep band="4">2</keep><keep band="2">3</keep>';

        $this->assertSame([2 => [3]], GradedCompactionPrompt::parseBands($text, 10));
    }

    public function test_parse_bands_drops_empty_bands(): void
    {
        $text = '<keep band="1">1</keep><keep band="2"></keep>';

        $this->assertSame([1 => [1]], GradedCompactionPrompt::parseBands($text, 10));
    }

    public function test_parse_bands_returns_empty_without_keep_blocks(): void
    {
        $this->assertSame([], GradedCompactionPrompt::parseBands('just a summary', 10));
    }

    public function test_parse_bands_returns_empty_for_empty_transcript(): void
    {
        $this->assertSame([], GradedCompactionPrompt::parseBands('<keep band="1">0</keep>', 0));
    }

    public function test_parse_bands_tolerates_unquoted_band_attribute(): void
    {
        $this->assertSame([1 => [3]], GradedCompactionPrompt::parseBands('<keep band=1>3</keep>', 10));
    }

    // ─── stripKeepBlocks ───────────────────────────────────────────────────

    public function test_strip_keep_blocks_removes_them(): void
    {
        $text = "# Summary\nBody text\n<keep band=\"1\">1,2</keep>\n<keep band=\"2\">3</keep>";

        $stripped = GradedCompactionPrompt::stripKeepBlocks($text);

        $this->assertStringNotContainsString('<keep', $stripped);
        $this->assertStringContainsString('Body text', $stripped);
    }

    // ─── renderBand ────────────────────────────────────────────────────────

    public function test_render_band_wraps_string_content(): void
    {
        $messages = [['role' => 'user', 'content' => 'Fix the login bug']];

        $rendered = GradedCompactionPrompt::renderBand([0], $messages, 1000);

        $this->assertStringContainsString('<preserved index="0" role="user">', $rendered);
        $this->assertStringContainsString('Fix the login bug', $rendered);
        $this->assertStringContainsString('</preserved>', $rendered);
    }

    public function test_render_band_names_the_tool_behind_a_result(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'Read', 'input' => ['file_path' => '/a.php']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '<?php echo 1;'],
            ]],
        ];

        $rendered = GradedCompactionPrompt::renderBand([1], $messages, 1000);

        $this->assertStringContainsString('[Tool result: Read]', $rendered);
        $this->assertStringContainsString('<?php echo 1;', $rendered);
    }

    public function test_render_band_marks_error_results(): void
    {
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'x', 'is_error' => true, 'content' => 'boom'],
        ]]];

        $this->assertStringContainsString(
            '(error)',
            GradedCompactionPrompt::renderBand([0], $messages, 1000),
        );
    }

    public function test_render_band_renders_tool_calls_with_input(): void
    {
        $messages = [['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 't', 'name' => 'Bash', 'input' => ['command' => 'composer test']],
        ]]];

        $rendered = GradedCompactionPrompt::renderBand([0], $messages, 1000);

        $this->assertStringContainsString('[Tool call: Bash]', $rendered);
        $this->assertStringContainsString('composer test', $rendered);
    }

    public function test_render_band_caps_each_item(): void
    {
        $messages = [['role' => 'user', 'content' => str_repeat('x', 5000)]];

        $rendered = GradedCompactionPrompt::renderBand([0], $messages, 100);

        $this->assertStringContainsString('truncated when preserved', $rendered);
        $this->assertLessThan(1000, mb_strlen($rendered));
    }

    public function test_render_band_skips_missing_indices(): void
    {
        $messages = [['role' => 'user', 'content' => 'only one']];

        $this->assertSame('', GradedCompactionPrompt::renderBand([7], $messages, 1000));
    }

    public function test_render_band_skips_messages_with_no_renderable_blocks(): void
    {
        $messages = [['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'hmm']]]];

        $this->assertSame('', GradedCompactionPrompt::renderBand([0], $messages, 1000));
    }

    public function test_render_band_joins_multiple_indices(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => 'second'],
        ];

        $rendered = GradedCompactionPrompt::renderBand([0, 1], $messages, 1000);

        $this->assertStringContainsString('index="0"', $rendered);
        $this->assertStringContainsString('index="1"', $rendered);
    }

    // ─── systemPrompt ──────────────────────────────────────────────────────

    public function test_system_prompt_asks_for_summary_and_keep_bands(): void
    {
        $text = GradedCompactionPrompt::systemPrompt()[0]['text'];

        $this->assertStringContainsString('<summary>', $text);
        $this->assertStringContainsString('<keep band="1">', $text);
        $this->assertStringContainsString('## 9. Optional Next Step', $text);
    }
}
