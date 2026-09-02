<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchOutputComposer;
use PHPUnit\Framework\TestCase;

class WebFetchOutputComposerTest extends TestCase
{
    private WebFetchOutputComposer $composer;

    /** @var callable(string, bool): string */
    private $convert;

    protected function setUp(): void
    {
        $this->composer = new WebFetchOutputComposer;
        $this->convert = static fn (string $html, bool $markdown): string => 'FULL_PAGE:'.strip_tags($html);
    }

    // ─── non-HTML passes through untouched ────────────────────────────────

    public function test_json_is_returned_verbatim(): void
    {
        $json = '{"ok":true}';

        $output = $this->compose($json, 'application/json', extract: true);

        $this->assertSame($json, $output);
    }

    // ─── quality notices ──────────────────────────────────────────────────

    public function test_prepends_a_notice_for_a_client_rendered_shell(): void
    {
        $shell = '<html><body><div id="root"></div><script>'.str_repeat('var a=1;', 200).'</script></body></html>';

        $output = $this->compose($shell, 'text/html', extract: false);

        $this->assertStringStartsWith('[WebFetch] Low-confidence result:', $output);
        $this->assertStringContainsString('renders client-side', $output);
        $this->assertStringContainsString('FULL_PAGE:', $output);
    }

    public function test_real_page_gets_no_notice_when_extraction_is_off(): void
    {
        $output = $this->compose($this->articleHtml(), 'text/html', extract: false);

        $this->assertStringNotContainsString('[WebFetch]', $output);
        $this->assertStringStartsWith('FULL_PAGE:', $output);
    }

    // ─── extraction ───────────────────────────────────────────────────────

    public function test_extraction_replaces_the_full_page_and_says_so(): void
    {
        $output = $this->compose($this->articleHtml(), 'text/html', extract: true);

        $this->assertStringContainsString('[WebFetch] Extracted main content', $output);
        $this->assertStringNotContainsString('FULL_PAGE:', $output);
        $this->assertStringContainsString('The committee met on Thursday', $output);
        $this->assertStringNotContainsString('Sign up for our newsletter', $output);
    }

    public function test_falls_back_to_the_full_page_when_nothing_scores(): void
    {
        $thin = '<html><body>'.str_repeat('<div><a href="/a">link</a></div>', 60).'</body></html>';

        $output = $this->compose($thin, 'text/html', extract: true);

        $this->assertStringContainsString('[WebFetch] Extraction skipped', $output);
        $this->assertStringContainsString('FULL_PAGE:', $output);
    }

    public function test_fallback_note_reports_a_short_extraction(): void
    {
        // Long enough to score, too short to be worth returning over the page.
        $html = '<html><body><article><p>'.str_repeat('short. ', 8).'</p></article>'
            .'<div>'.str_repeat('<span>x</span>', 400).'</div></body></html>';

        $output = $this->compose($html, 'text/html', extract: true);

        $this->assertStringContainsString('[WebFetch] Extraction skipped', $output);
        $this->assertStringContainsString('FULL_PAGE:', $output);
    }

    public function test_keywords_steer_which_block_is_extracted(): void
    {
        $html = '<html><body>'
            .'<div id="main"><p>'.str_repeat('General background prose about the organisation. ', 20).'</p></div>'
            .'<div id="hours"><p>开放时间 09:00-18:00</p></div>'
            .'</body></html>';

        $withoutKeywords = $this->compose($html, 'text/html', extract: true);
        $withKeywords = $this->compose($html, 'text/html', extract: true, keywords: ['开放时间']);

        $this->assertStringNotContainsString('开放时间 09:00-18:00', $withoutKeywords);
        $this->assertStringContainsString('开放时间 09:00-18:00', $withKeywords);
    }

    /** @param list<string> $keywords */
    private function compose(
        string $body,
        string $mediaType,
        bool $extract,
        array $keywords = [],
        bool $markdown = false,
    ): string {
        return $this->composer->compose(
            $body,
            $mediaType,
            'https://example.com/page',
            $markdown,
            $extract,
            $keywords,
            $this->convert,
        );
    }

    private function articleHtml(): string
    {
        return '<html><head><title>Committee notes</title></head><body>'
            .'<nav><a href="/">Home</a></nav>'
            .'<article><h1>Committee notes</h1>'
            .'<p>The committee met on Thursday to review the three outstanding proposals and '
            .'agreed to defer the largest of them until the next quarter.</p>'
            .'<p>Two members recorded objections, both concerning the timeline rather than the '
            .'substance of the proposals themselves.</p></article>'
            .'<aside class="sidebar"><p>Sign up for our newsletter.</p></aside>'
            .'</body></html>';
    }
}
