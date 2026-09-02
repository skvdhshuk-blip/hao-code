<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end behaviour of the `extract` / `keywords` parameters and of the
 * browser-shaped request headers, exercised through WebFetchTool::call().
 */
class WebFetchToolExtractionTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');

        $cache = (new \ReflectionClass(WebFetchTool::class))->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    // ─── schema ───────────────────────────────────────────────────────────

    public function test_schema_exposes_extract_and_keywords(): void
    {
        $schema = (new WebFetchTool)->inputSchema()->toJsonSchema();

        $this->assertSame('boolean', $schema['properties']['extract']['type']);
        $this->assertSame('array', $schema['properties']['keywords']['type']);
        $this->assertSame(['url'], $schema['required']);
    }

    // ─── extraction through call() ────────────────────────────────────────

    public function test_extract_returns_the_article_without_the_chrome(): void
    {
        $result = $this->fetch(['url' => 'http://127.0.0.1:9999/story', 'extract' => true], $this->newsHtml());

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('The board approved', $result->output);
        $this->assertStringNotContainsString('Subscribe to the newsletter', $result->output);
        $this->assertStringContainsString('[WebFetch] Extracted main content', $result->output);
    }

    public function test_default_call_still_returns_the_whole_page(): void
    {
        $result = $this->fetch(['url' => 'http://127.0.0.1:9999/story2'], $this->newsHtml());

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Subscribe to the newsletter', $result->output);
        $this->assertStringNotContainsString('[WebFetch] Extracted main content', $result->output);
    }

    public function test_keywords_change_the_cache_key(): void
    {
        $html = '<html><body>'
            .'<div id="main"><p>'.str_repeat('Generic prose about the organisation. ', 25).'</p></div>'
            .'<div id="hours"><p>开放时间 09:00-18:00</p></div>'
            .'</body></html>';

        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            $this->htmlResponse($html),
            $this->htmlResponse($html),
        ]));

        $plain = $tool->call(['url' => 'http://127.0.0.1:9999/hours', 'extract' => true], $this->context);
        $keyed = $tool->call(
            ['url' => 'http://127.0.0.1:9999/hours', 'extract' => true, 'keywords' => ['开放时间']],
            $this->context,
        );

        // A second request was issued rather than the first answer replayed.
        $this->assertStringNotContainsString('[Cached result]', $keyed->output);
        $this->assertStringNotContainsString('开放时间 09:00-18:00', $plain->output);
        $this->assertStringContainsString('开放时间 09:00-18:00', $keyed->output);
    }

    public function test_identical_requests_still_hit_the_cache(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([$this->htmlResponse($this->newsHtml())]));

        $tool->call(['url' => 'http://127.0.0.1:9999/cached', 'extract' => true], $this->context);
        $second = $tool->call(['url' => 'http://127.0.0.1:9999/cached', 'extract' => true], $this->context);

        $this->assertStringContainsString('[Cached result]', $second->output);
    }

    public function test_non_string_keywords_are_ignored(): void
    {
        $result = $this->fetch(
            ['url' => 'http://127.0.0.1:9999/robust', 'extract' => true, 'keywords' => [1, null, ' ', 'board']],
            $this->newsHtml(),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('The board approved', $result->output);
    }

    // ─── low-confidence responses ─────────────────────────────────────────

    public function test_client_rendered_shell_is_flagged(): void
    {
        $shell = '<html><body><div id="root"></div><script>'.str_repeat('var a=1;', 300).'</script></body></html>';

        $result = $this->fetch(['url' => 'http://127.0.0.1:9999/spa'], $shell);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('[WebFetch] Low-confidence result', $result->output);
        $this->assertStringContainsString('renders client-side', $result->output);
    }

    public function test_error_response_body_is_reported_with_the_status(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse(
                '<html><body><h1>403 Forbidden</h1><p>denied by UA ACL = blacklist</p></body></html>',
                ['http_code' => 403, 'response_headers' => ['content-type' => 'text/html']],
            ),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/denied'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('HTTP 403', $result->output);
        $this->assertStringContainsString('denied by UA ACL', $result->output);
    }

    // ─── request headers ──────────────────────────────────────────────────

    public function test_requests_present_a_browser_user_agent(): void
    {
        $seen = [];
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['headers'] ?? [];

            return $this->htmlResponse($this->newsHtml());
        }));

        $tool->call(['url' => 'http://127.0.0.1:9999/ua'], $this->context);

        $headers = implode("\n", $seen);
        $this->assertStringContainsString('Chrome/', $headers);
        $this->assertStringNotContainsString('HaoCode/1.0', $headers);
        $this->assertStringContainsString('Accept-Language', $headers);
    }

    private function fetch(array $input, string $html): \HaoCode\Tools\ToolResult
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([$this->htmlResponse($html)]));

        return $tool->call($input, $this->context);
    }

    private function htmlResponse(string $html): MockResponse
    {
        return new MockResponse($html, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ]);
    }

    private function newsHtml(): string
    {
        return '<html><head><title>Board notes | Example</title></head><body>'
            .'<nav><a href="/">Home</a></nav>'
            .'<article><h1>Board notes</h1>'
            .'<p>The board approved the revised schedule on Thursday after a discussion that ran '
            .'well past its allotted hour, with two members abstaining.</p>'
            .'<p>Implementation begins next month and the first review is set for the quarter after.</p>'
            .'</article>'
            .'<aside class="sidebar"><p>Subscribe to the newsletter for updates.</p></aside>'
            .'</body></html>';
    }
}
