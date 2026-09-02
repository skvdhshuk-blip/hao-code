<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\PageQualitySignals;
use PHPUnit\Framework\TestCase;

class PageQualitySignalsTest extends TestCase
{
    // ─── visibleTextLength: markdown URLs must not inflate the count ──────

    public function test_counts_plain_text_without_whitespace(): void
    {
        $this->assertSame(10, PageQualitySignals::visibleTextLength('hello world'));
        $this->assertSame(0, PageQualitySignals::visibleTextLength(''));
        $this->assertSame(0, PageQualitySignals::visibleTextLength("  \n\t  "));
    }

    public function test_markdown_link_counts_text_but_not_url(): void
    {
        $markdown = 'see [Google](https://www.google.com/?q=test&utm_source=x) for details';

        // "see" + "Google" + "for" + "details"
        $this->assertSame(19, PageQualitySignals::visibleTextLength($markdown));
    }

    public function test_markdown_image_counts_alt_but_not_url(): void
    {
        $this->assertSame(7, PageQualitySignals::visibleTextLength('![alt text](https://example.com/image.png)'));
    }

    public function test_bare_brackets_are_not_treated_as_links(): void
    {
        // "array" + "[0]" + "is" + "not" + "a" + "link"
        $this->assertSame(18, PageQualitySignals::visibleTextLength('array[0] is not a link'));
    }

    public function test_nested_brackets_inside_link_text(): void
    {
        // "see" + "the[inner]thing"
        $this->assertSame(18, PageQualitySignals::visibleTextLength('see [the [inner] thing](http://example.com)'));
    }

    public function test_counts_cjk_characters_individually(): void
    {
        // Four CJK characters plus "|" plus "About".
        $markdown = '[关于腾讯](http://www.tencent.com/) | [About](http://www.tencent.com/index_e.shtml)';

        $this->assertSame(10, PageQualitySignals::visibleTextLength($markdown));
    }

    public function test_cjk_and_zero_width_spaces_are_not_visible(): void
    {
        $this->assertSame(2, PageQualitySignals::visibleTextLength("中\u{3000}文"));
        $this->assertSame(2, PageQualitySignals::visibleTextLength("中\u{200B}文"));
        $this->assertSame(2, PageQualitySignals::visibleTextLength("ab\u{00A0}"));
    }

    // ─── challenge / anti-bot walls ───────────────────────────────────────

    public function test_detects_cloudflare_challenge(): void
    {
        $this->assertTrue(PageQualitySignals::isUnderChallenge('<title>Just a moment...</title>'));
        $this->assertTrue(PageQualitySignals::isUnderChallenge('<div class="cf-challenge-running"></div>'));
        $this->assertFalse(PageQualitySignals::isUnderChallenge('<p>An ordinary paragraph.</p>'));
    }

    public function test_detects_ua_blacklist_and_captcha_walls(): void
    {
        $this->assertTrue(PageQualitySignals::hasAntiBotWall('denied by UA ACL = blacklist'));
        $this->assertTrue(PageQualitySignals::hasAntiBotWall('<h1>百度安全验证</h1>'));
        $this->assertTrue(PageQualitySignals::hasAntiBotWall('<h1>Access Denied</h1>'));
    }

    public function test_long_article_mentioning_a_wall_phrase_is_not_a_wall(): void
    {
        // A news article about captchas must not be mistaken for one.
        $article = '<article>'.str_repeat('这是一篇讨论人机验证机制的长文章。', 3000).'</article>';

        $this->assertGreaterThan(32768, strlen($article));
        $this->assertFalse(PageQualitySignals::hasAntiBotWall($article));
    }

    // ─── client-rendered shells ───────────────────────────────────────────

    public function test_detects_spa_shell(): void
    {
        $shell = '<html><head><title>App</title></head><body><div id="root"></div>'
            .'<script>'.str_repeat('var x = 1;', 500).'</script></body></html>';

        $this->assertTrue(PageQualitySignals::looksLikeSpaOrBlank($shell));
    }

    public function test_real_page_is_not_flagged(): void
    {
        $page = '<html><body><article><p>'
            .str_repeat('Real prose that a reader would actually read. ', 20)
            .'</p></article></body></html>';

        $this->assertFalse(PageQualitySignals::looksLikeSpaOrBlank($page));
        $this->assertNull(PageQualitySignals::describe($page));
    }

    public function test_describe_explains_why_a_response_is_unusable(): void
    {
        $challenge = '<html><body><h1>Just a moment...</h1>'.str_repeat('<span>.</span>', 200).'</body></html>';
        $this->assertStringContainsString('challenge', (string) PageQualitySignals::describe($challenge));

        $wall = '<html><body><h1>denied by UA ACL = blacklist</h1></body></html>';
        $this->assertStringContainsString('access-denied', (string) PageQualitySignals::describe($wall));

        $shell = '<html><body><div id="app"></div><script>'.str_repeat('a;', 400).'</script></body></html>';
        $this->assertStringContainsString('renders client-side', (string) PageQualitySignals::describe($shell));
        $this->assertStringContainsString('0 visible characters', (string) PageQualitySignals::describe($shell));
    }

    public function test_strip_scripts_and_tags_drops_script_bodies(): void
    {
        $stripped = PageQualitySignals::stripScriptsAndTags(
            '<div>keep<script>secret()</script><style>.a{}</style><!-- note --></div>',
        );

        $this->assertStringContainsString('keep', $stripped);
        $this->assertStringNotContainsString('secret', $stripped);
        $this->assertStringNotContainsString('.a{}', $stripped);
        $this->assertStringNotContainsString('note', $stripped);
    }

    public function test_strip_handles_unterminated_script_block(): void
    {
        $stripped = PageQualitySignals::stripScriptsAndTags('<p>text</p><script>var a = 1;');

        $this->assertStringContainsString('text', $stripped);
        $this->assertStringNotContainsString('var a', $stripped);
    }
}
