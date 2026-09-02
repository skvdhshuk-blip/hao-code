<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\ReadabilityExtractor;
use PHPUnit\Framework\TestCase;

class ReadabilityExtractorTest extends TestCase
{
    private ReadabilityExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ReadabilityExtractor;
    }

    // ─── main-content selection ───────────────────────────────────────────

    public function test_keeps_the_article_and_drops_page_chrome(): void
    {
        $article = $this->extractor->extract($this->newsPage(), 'https://example.com/story', [], false);

        $this->assertNotNull($article);
        $this->assertStringContainsString('The council voted', $article->content);
        $this->assertStringNotContainsString('Subscribe to our newsletter', $article->content);
        $this->assertStringNotContainsString('Advertisement', $article->content);
        $this->assertStringNotContainsString('Home About Contact', $article->content);
    }

    public function test_reports_visible_length_of_the_extracted_text(): void
    {
        $article = $this->extractor->extract($this->newsPage(), 'https://example.com/story', [], false);

        $this->assertNotNull($article);
        $this->assertGreaterThan(200, $article->visibleLength);
    }

    public function test_returns_null_for_html_without_content(): void
    {
        $this->assertNull($this->extractor->extract('', 'https://example.com', [], false));
        $this->assertNull($this->extractor->extract('<html><body></body></html>', 'https://example.com', [], false));
    }

    // ─── keyword weighting ────────────────────────────────────────────────

    /**
     * The case the keyword pass exists for: the wanted data is a handful of
     * characters and the boilerplate is thousands. Without weighting the
     * boilerplate wins on length alone.
     */
    public function test_keywords_pull_a_short_data_block_over_long_boilerplate(): void
    {
        $html = $this->weatherPage();

        $withoutKeywords = $this->extractor->extract($html, 'https://example.com/weather', [], false);
        $withKeywords = $this->extractor->extract($html, 'https://example.com/weather', ['温度'], false);

        $this->assertNotNull($withoutKeywords);
        $this->assertNotNull($withKeywords);
        $this->assertStringNotContainsString('温度 25°', $withoutKeywords->content);
        $this->assertStringContainsString('温度 25°', $withKeywords->content);
    }

    public function test_keyword_matching_is_case_insensitive(): void
    {
        $html = $this->weatherPage();

        $article = $this->extractor->extract($html, 'https://example.com/weather', ['HUMIDITY'], false);

        $this->assertNotNull($article);
        $this->assertStringContainsString('humidity 57%', $article->content);
    }

    public function test_unmatched_keywords_leave_the_ranking_alone(): void
    {
        $html = $this->weatherPage();

        $baseline = $this->extractor->extract($html, 'https://example.com/weather', [], false);
        $withMiss = $this->extractor->extract($html, 'https://example.com/weather', ['房价'], false);

        $this->assertNotNull($baseline);
        $this->assertNotNull($withMiss);
        $this->assertSame($baseline->content, $withMiss->content);
    }

    /**
     * A keyword sitting inside an otherwise-discarded region must rescue that
     * region, or asking for it would make it less likely to be returned.
     */
    public function test_keyword_rescues_a_block_named_like_page_chrome(): void
    {
        $html = '<html><body>'
            .'<div id="main"><p>'.str_repeat('Ordinary article prose about nothing in particular. ', 30).'</p></div>'
            .'<div id="sidebar-widget"><p>营业时间 09:00-18:00</p></div>'
            .'</body></html>';

        $article = $this->extractor->extract($html, 'https://example.com', ['营业时间'], false);

        $this->assertNotNull($article);
        $this->assertStringContainsString('营业时间 09:00-18:00', $article->content);
    }

    // ─── output shape ─────────────────────────────────────────────────────

    public function test_markdown_output_keeps_headings_lists_and_links(): void
    {
        $html = '<html><body><article>'
            .'<h2>Findings</h2>'
            .'<p>'.str_repeat('A sentence that carries enough weight to score. ', 8).'</p>'
            .'<ul><li>First point</li><li>Second point</li></ul>'
            .'<p>See <a href="/docs/spec">the spec</a> for details.</p>'
            .'</article></body></html>';

        $article = $this->extractor->extract($html, 'https://example.com/report', [], true);

        $this->assertNotNull($article);
        $this->assertStringContainsString('## Findings', $article->content);
        $this->assertStringContainsString('- First point', $article->content);
        $this->assertStringContainsString('[the spec](https://example.com/docs/spec)', $article->content);
    }

    public function test_text_output_has_no_markdown_syntax(): void
    {
        $html = '<html><body><article>'
            .'<h2>Findings</h2>'
            .'<p>'.str_repeat('A sentence that carries enough weight to score. ', 8).'</p>'
            .'<p>See <a href="/docs/spec">the spec</a> for details.</p>'
            .'</article></body></html>';

        $article = $this->extractor->extract($html, 'https://example.com/report', [], false);

        $this->assertNotNull($article);
        $this->assertStringContainsString('Findings', $article->content);
        $this->assertStringNotContainsString('##', $article->content);
        $this->assertStringNotContainsString('](', $article->content);
        $this->assertStringContainsString('the spec', $article->content);
    }

    public function test_script_and_style_bodies_never_reach_the_output(): void
    {
        $html = '<html><body><article>'
            .'<script>window.tracker = "leak-me";</script>'
            .'<style>.hidden { color: leakcolor; }</style>'
            .'<p>'.str_repeat('Visible prose that should survive extraction. ', 8).'</p>'
            .'</article></body></html>';

        $article = $this->extractor->extract($html, 'https://example.com', [], false);

        $this->assertNotNull($article);
        $this->assertStringNotContainsString('leak-me', $article->content);
        $this->assertStringNotContainsString('leakcolor', $article->content);
    }

    // ─── title resolution ─────────────────────────────────────────────────

    public function test_prefers_the_article_heading_over_the_document_title(): void
    {
        $article = $this->extractor->extract($this->newsPage(), 'https://example.com/story', [], false);

        $this->assertNotNull($article);
        $this->assertSame('Council approves the budget', $article->title);
    }

    public function test_falls_back_to_title_tag_with_the_site_suffix_trimmed(): void
    {
        $html = '<html><head><title>Quarterly results | Example Corp</title></head><body>'
            .'<article><p>'.str_repeat('Revenue grew across every reported segment. ', 10).'</p></article>'
            .'</body></html>';

        $article = $this->extractor->extract($html, 'https://example.com', [], false);

        $this->assertNotNull($article);
        $this->assertSame('Quarterly results', $article->title);
    }

    public function test_render_prefixes_the_title_as_a_heading(): void
    {
        $article = $this->extractor->extract($this->newsPage(), 'https://example.com/story', [], true);

        $this->assertNotNull($article);
        $this->assertStringStartsWith('# Council approves the budget', $article->render());
    }

    public function test_utf8_survives_parsing_without_a_charset_meta(): void
    {
        $html = '<html><body><article><p>'
            .str_repeat('中文正文内容需要足够长才能被评分器选中。', 6)
            .'</p></article></body></html>';

        $article = $this->extractor->extract($html, 'https://example.com', [], false);

        $this->assertNotNull($article);
        $this->assertStringContainsString('中文正文内容', $article->content);
    }

    // ─── fixtures ─────────────────────────────────────────────────────────

    private function newsPage(): string
    {
        return '<html><head><title>Council approves the budget | Example News</title></head><body>'
            .'<nav><a href="/">Home</a> <a href="/about">About</a> <a href="/contact">Contact</a></nav>'
            .'<div class="ad-break"><p>Advertisement</p></div>'
            .'<article><h1>Council approves the budget</h1>'
            .'<p>The council voted eleven to four on Tuesday evening, ending a debate that had run '
            .'for the better part of three months and drawn objections from every district.</p>'
            .'<p>Funding for the transit extension survives intact, while the parks allocation was '
            .'reduced by roughly a fifth to close the remaining gap.</p>'
            .'</article>'
            .'<aside class="sidebar"><p>Subscribe to our newsletter for daily updates.</p></aside>'
            .'<footer><p>Copyright Example News</p></footer>'
            .'</body></html>';
    }

    /** A short data block competing with a much longer block of prose. */
    private function weatherPage(): string
    {
        return '<html><head><title>杭州天气</title></head><body>'
            .'<div id="data"><p>温度 25°</p><p>humidity 57%</p></div>'
            .'<div id="about"><p>'
            .str_repeat('这是一大段无关内容，用来干扰评分算法，需要足够长才能在没有关键词时胜出。', 40)
            .'</p></div>'
            .'</body></html>';
    }
}
