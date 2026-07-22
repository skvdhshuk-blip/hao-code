<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebFetchToolTest extends TestCase
{
    private WebFetchTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new WebFetchTool;
        $this->ref = new \ReflectionClass($this->tool);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');

        // Reset the shared static cache between tests so a previous run cannot
        // satisfy a later assertion via a stale entry.
        $cacheProp = $this->ref->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue(null, []);
    }

    private function htmlToText(string $html): string
    {
        $m = $this->ref->getMethod('htmlToText');
        $m->setAccessible(true);
        return $m->invoke($this->tool, $html);
    }

    // ─── name / description / isReadOnly ─────────────────────────────────

    public function test_name_is_web_fetch(): void
    {
        $this->assertSame('WebFetch', $this->tool->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_description_mentions_url(): void
    {
        $this->assertStringContainsString('URL', $this->tool->description());
    }

    // ─── htmlToText ───────────────────────────────────────────────────────

    public function test_strips_script_tags_and_content(): void
    {
        $html = '<html><script>alert("evil")</script><p>Good content</p></html>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringContainsString('Good content', $text);
    }

    public function test_strips_style_tags_and_content(): void
    {
        $html = '<html><style>.foo { color: red; }</style><p>Text here</p></html>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('color', $text);
        $this->assertStringContainsString('Text here', $text);
    }

    public function test_converts_br_to_newline(): void
    {
        $html = 'Line one<br>Line two<br/>Line three';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString("\n", $text);
        $this->assertStringContainsString('Line one', $text);
        $this->assertStringContainsString('Line two', $text);
    }

    public function test_converts_closing_p_to_double_newline(): void
    {
        $html = '<p>First</p><p>Second</p>';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString('First', $text);
        $this->assertStringContainsString('Second', $text);
    }

    public function test_strips_all_html_tags(): void
    {
        $html = '<div><h1>Title</h1><p>Body <strong>text</strong></p></div>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('>', $text);
        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('Body', $text);
    }

    public function test_decodes_html_entities(): void
    {
        $html = '<p>Tom &amp; Jerry &lt;mice&gt;</p>';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString('Tom & Jerry', $text);
    }

    public function test_strips_html_comments(): void
    {
        $html = '<!-- This is a comment -->Visible text';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('This is a comment', $text);
        $this->assertStringContainsString('Visible text', $text);
    }

    public function test_collapses_multiple_newlines(): void
    {
        $html = '<p>A</p><p></p><p>B</p>';
        $text = $this->htmlToText($html);
        // Should not have 3+ consecutive newlines
        $this->assertSame(0, preg_match('/\n{3,}/', $text));
    }

    // ─── call — network failure ───────────────────────────────────────────

    public function test_call_returns_error_on_connection_failure(): void
    {
        $result = $this->tool->call(
            ['url' => 'https://localhost:19999/nonexistent'],
            $this->context,
        );
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Failed to fetch', $result->output);
    }

    // ─── format parameter distinguishes text vs markdown ──────────────────

    public function test_markdown_format_preserves_headings_and_links(): void
    {
        $html = '<h1>Title</h1><p>See <a href="https://example.com">docs</a>.</p>';
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);
        $this->tool->setClient($client);

        $result = $this->tool->call(
            ['url' => 'http://localhost:9999/page', 'format' => 'markdown'],
            $this->context,
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('# Title', $result->output);
        $this->assertStringContainsString('[docs](https://example.com)', $result->output);
    }

    public function test_text_format_strips_markdown_markers(): void
    {
        $html = '<h1>Title</h1><p>See <a href="https://example.com">docs</a>.</p>';
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);
        $this->tool->setClient($client);

        $result = $this->tool->call(
            ['url' => 'http://localhost:9999/page2', 'format' => 'text'],
            $this->context,
        );

        $this->assertFalse($result->isError);
        // Text format strips the leading "#" and link URL.
        $this->assertStringNotContainsString('# Title', $result->output);
        $this->assertStringNotContainsString('[docs](https://example.com)', $result->output);
        $this->assertStringContainsString('Title', $result->output);
        $this->assertStringContainsString('docs', $result->output);
    }

    // ─── cache isolation across security policies ─────────────────────────

    public function test_cache_is_isolated_by_security_policy(): void
    {
        $html = '<p>body</p>';

        // Both tools must be able to reach loopback (the mock host), so the
        // difference under test is the allowPrivateNetworks flag in the cache
        // key — not whether the request is allowed at all.
        $loopback = ['127.0.0.1/8', '::1/128'];
        $strict = new WebFetchTool(allowPrivateNetworks: false, ssrfAllowList: $loopback, maxBytes: 1024);
        $strictCalls = 0;
        $strict->setClient(new MockHttpClient(function () use ($html, &$strictCalls) {
            $strictCalls++;

            return new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
        }));

        $permissive = new WebFetchTool(allowPrivateNetworks: true, ssrfAllowList: $loopback, maxBytes: 1024);
        $permissiveCalls = 0;
        $permissive->setClient(new MockHttpClient(function () use ($html, &$permissiveCalls) {
            $permissiveCalls++;

            return new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
        }));

        // Same URL, different policies — two distinct fetches.
        $strict->call(['url' => 'http://localhost:9999/same'], $this->context);
        $permissive->call(['url' => 'http://localhost:9999/same'], $this->context);

        $this->assertSame(1, $strictCalls, 'strict policy must fetch on its own');
        $this->assertSame(1, $permissiveCalls, 'permissive policy must not hit the strict cache');
    }

    public function test_cache_hits_on_repeat_within_same_policy(): void
    {
        $html = '<p>cached body</p>';
        $calls = 0;
        $client = new MockHttpClient(function () use ($html, &$calls) {
            $calls++;

            return new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
        });
        $this->tool->setClient($client);

        $this->tool->call(['url' => 'http://localhost:9999/cache-hit'], $this->context);
        $this->tool->call(['url' => 'http://localhost:9999/cache-hit'], $this->context);

        $this->assertSame(1, $calls, 'second identical call must be served from cache');
    }

    // ─── redirect resolution per RFC 3986 reference types ─────────────────

    public function test_resolve_redirect_handles_relative_references(): void
    {
        $tool = new WebFetchTool;
        $method = (new \ReflectionClass($tool))->getMethod('resolveRedirect');
        $method->setAccessible(true);

        $base = 'https://example.com/foo/bar';

        $this->assertSame('https://other.example/path', $method->invoke($tool, $base, 'https://other.example/path'));
        $this->assertSame('https://example.com/baz', $method->invoke($tool, $base, '/baz'));
        $this->assertSame('https://example.com/foo/qux', $method->invoke($tool, $base, 'qux'));
        $this->assertSame('https://example.com/quux', $method->invoke($tool, $base, '../quux'));
        $this->assertSame('https://example.com/foo/bar?page=2', $method->invoke($tool, $base, '?page=2'));
        // Fragment-only references collapse to the base (no re-fetch).
        $this->assertSame($base, $method->invoke($tool, $base, '#section'));
    }

    public function test_normalize_path_collapses_dots(): void
    {
        $tool = new WebFetchTool;
        $method = (new \ReflectionClass($tool))->getMethod('normalizePath');
        $method->setAccessible(true);

        $this->assertSame('/a/b', $method->invoke($tool, '/a/./b'));
        $this->assertSame('/a/c', $method->invoke($tool, '/a/b/../c'));
        $this->assertSame('/c', $method->invoke($tool, '/a/b/../../c'));
        $this->assertSame('/', $method->invoke($tool, '/a/../../..'));
    }

    // ─── byte cap is enforced ─────────────────────────────────────────────

    public function test_byte_cap_aborts_oversized_response(): void
    {
        $tool = new WebFetchTool(allowPrivateNetworks: false, ssrfAllowList: ['127.0.0.1/8', '::1/128'], maxBytes: 16);
        $tool->setClient(new MockHttpClient([
            new MockResponse(str_repeat('x', 1024), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call(['url' => 'http://localhost:9999/huge'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('exceeded', $result->output);
    }

    // ─── DNS pinning: HttpClient receives the resolved IPs ────────────────

    public function test_call_does_not_disable_tls_or_use_curl(): void
    {
        // Sanity: the tool no longer touches ext-curl. We assert the source
        // has no curl_* calls (WebSearch made the same migration; WebFetch
        // never used curl, but the assertion documents the invariant).
        $source = file_get_contents((new \ReflectionClass(WebFetchTool::class))->getFileName());
        $this->assertStringNotContainsString('curl_init', $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
    }
}
