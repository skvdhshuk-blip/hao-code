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
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);
        $tool->setClient($client);

        $result = $tool->call(
            ['url' => 'http://127.0.0.1:9999/page', 'format' => 'markdown'],
            $this->context,
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('# Title', $result->output);
        $this->assertStringContainsString('[docs](https://example.com)', $result->output);
    }

    public function test_text_format_strips_markdown_markers(): void
    {
        $html = '<h1>Title</h1><p>See <a href="https://example.com">docs</a>.</p>';
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);
        $tool->setClient($client);

        $result = $tool->call(
            ['url' => 'http://127.0.0.1:9999/page2', 'format' => 'text'],
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
        $loopback = ['127.0.0.1/32'];
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
        $strict->call(['url' => 'http://127.0.0.1:9999/same'], $this->context);
        $permissive->call(['url' => 'http://127.0.0.1:9999/same'], $this->context);

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
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient($client);

        $tool->call(['url' => 'http://127.0.0.1:9999/cache-hit'], $this->context);
        $tool->call(['url' => 'http://127.0.0.1:9999/cache-hit'], $this->context);

        $this->assertSame(1, $calls, 'second identical call must be served from cache');
    }

    public function test_cache_stores_only_the_truncated_rendered_output(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse(str_repeat('x', 200_000), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]),
        ]));

        $tool->call(['url' => 'http://127.0.0.1:9999/cache-truncated'], $this->context);

        $cache = $this->ref->getStaticPropertyValue('cache');
        $entry = reset($cache);
        $this->assertIsArray($entry);
        $this->assertLessThan(101_000, strlen($entry['content']));
        $this->assertStringContainsString('[Content truncated at 100000 characters]', $entry['content']);
    }

    public function test_cache_evicts_entries_to_stay_within_total_byte_budget(): void
    {
        $cache = [];
        for ($index = 0; $index < 40; $index++) {
            $cache["entry{$index}"] = [
                'content' => str_repeat('x', 1_000_000),
                'time' => time() - 10 + $index,
                'final_url' => null,
            ];
        }
        $this->ref->setStaticPropertyValue('cache', $cache);

        $method = $this->ref->getMethod('storeCache');
        $method->setAccessible(true);
        $method->invoke($this->tool, 'new-entry', 'fresh', null);

        $stored = $this->ref->getStaticPropertyValue('cache');
        $bytes = array_sum(array_map(static fn (array $entry): int => strlen($entry['content']), $stored));
        $this->assertLessThanOrEqual(32 * 1024 * 1024, $bytes);
        $this->assertArrayHasKey('new-entry', $stored);
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
        $this->assertSame('https://example.com/foo/bar', $method->invoke($tool, $base, 'bar#section'));
        $this->assertSame('https://example.com/foo/qux', $method->invoke($tool, 'https://example.com/foo/', 'qux'));
        $this->assertSame('https://example.com/next', $method->invoke($tool, 'https://example.com', 'next'));
        $this->assertSame('https://[::1]:8080/foo/bar', $method->invoke($tool, 'https://[::1]:8080/foo/', 'bar'));
        $this->assertSame('https://[::1]:8080/next', $method->invoke($tool, 'https://[::1]:8080/foo', '../next'));
        $this->assertSame('https://example.com/foo/g/', $method->invoke($tool, 'https://example.com/foo/bar', 'g/'));
        $this->assertSame('https://example.com/', $method->invoke($tool, 'https://example.com/foo/bar', '../'));
        $this->assertSame('https://other.example/y', $method->invoke($tool, $base, 'https://other.example/x/../y#fragment'));
        $this->assertSame('https://other.example/y', $method->invoke($tool, $base, '//other.example/x/../y#fragment'));
        $this->assertSame('https://user:pass@example.com/foo/next', $method->invoke(
            $tool,
            'https://user:pass@example.com/foo/page',
            'next',
        ));
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
        $tool = new WebFetchTool(allowPrivateNetworks: false, ssrfAllowList: ['127.0.0.1/32'], maxBytes: 16);
        $tool->setClient(new MockHttpClient([
            new MockResponse(str_repeat('x', 1024), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/huge'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('exceeded', $result->output);
    }

    public function test_output_truncation_keeps_prefix_and_marker(): void
    {
        $prefix = str_repeat('a', 100_000);
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse($prefix.'TAIL', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/truncate'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringStartsWith($prefix, $result->output);
        $this->assertStringContainsString('[Content truncated at 100000 characters]', $result->output);
        $this->assertStringNotContainsString('TAIL', $result->output);
    }

    public function test_byte_fallback_does_not_cut_utf8_character(): void
    {
        $method = (new \ReflectionClass($this->tool))->getMethod('truncateUtf8ByBytes');
        $method->setAccessible(true);
        $content = str_repeat('a', 99_999).'界';
        $prefix = $method->invoke($this->tool, $content, 100_000);

        $this->assertSame(99_999, strlen($prefix));
        $this->assertTrue((bool) preg_match('//u', $prefix));
    }

    // ─── DNS pinning: HttpClient receives a hostname → checked IP map ────

    public function test_call_does_not_disable_tls_or_use_curl(): void
    {
        // Sanity: the tool no longer touches ext-curl. We assert the source
        // has no curl_* calls (WebSearch made the same migration; WebFetch
        // never used curl, but the assertion documents the invariant).
        $source = file_get_contents((new \ReflectionClass(WebFetchTool::class))->getFileName());
        $this->assertStringNotContainsString('curl_init', $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
    }

    public function test_request_pins_one_checked_ip_without_manual_host_header(): void
    {
        $optionsSeen = null;
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;

            return new MockResponse('ok', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]);
        }));

        $result = $tool->call(['url' => 'http://127.0.0.1:8080/pinned'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertIsArray($optionsSeen);
        $this->assertSame(['127.0.0.1' => '127.0.0.1'], $optionsSeen['resolve']);
        $this->assertSame('*', $optionsSeen['no_proxy']);
        $this->assertArrayNotHasKey('Host', $optionsSeen['headers']);
    }

    public function test_request_pins_hostname_to_checked_ip_and_preserves_port(): void
    {
        // Exercise the exact options builder without depending on the runner's
        // DNS configuration (localhost is intentionally not resolved in some
        // CI sandboxes).
        $method = (new \ReflectionClass($this->tool))->getMethod('requestOptions');
        $method->setAccessible(true);
        $options = $method->invoke($this->tool, [
            'host' => 'localhost',
            'ips' => ['127.0.0.1'],
        ]);

        $this->assertSame(['localhost' => '127.0.0.1'], $options['resolve']);
        $this->assertSame('*', $options['no_proxy']);
        $this->assertArrayNotHasKey('Host', $options['headers']);
    }

    public function test_request_fails_over_to_the_next_validated_ip_on_transport_error(): void
    {
        $resolves = [];
        $calls = 0;
        $tool = new WebFetchTool;
        $tool->setClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$resolves, &$calls) {
            $resolves[] = $options['resolve'];
            $calls++;

            return $calls === 1
                ? new MockResponse('', ['error' => 'connection failed'])
                : new MockResponse('ok', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]);
        }));

        $method = (new \ReflectionClass($tool))->getMethod('requestValidatedUrl');
        $method->setAccessible(true);
        $response = $method->invoke($tool, 'https://example.com/path', [
            'host' => 'example.com',
            'ips' => ['203.0.113.10', '203.0.113.11'],
        ]);

        $this->assertSame('ok', $response['body']);
        $this->assertSame([
            ['example.com' => '203.0.113.10'],
            ['example.com' => '203.0.113.11'],
        ], $resolves);
    }

    public function test_request_does_not_fail_over_after_an_http_response(): void
    {
        $calls = 0;
        $tool = new WebFetchTool;
        $tool->setClient(new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('unavailable', ['http_code' => 503]);
        }));

        $method = (new \ReflectionClass($tool))->getMethod('requestValidatedUrl');
        $method->setAccessible(true);

        try {
            $method->invoke($tool, 'https://example.com/path', [
                'host' => 'example.com',
                'ips' => ['203.0.113.10', '203.0.113.11'],
            ]);
            $this->fail('Expected an HTTP error.');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $exception = $e instanceof \ReflectionException ? $e : ($e->getPrevious() ?? $e);
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    public function test_prompt_is_reported_as_focus_not_extraction(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse('full page', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call([
            'url' => 'http://127.0.0.1:9999/focus',
            'prompt' => 'look for invoices',
        ], $this->context);

        $this->assertStringContainsString('[Requested focus: look for invoices]', $result->output);
        $this->assertStringContainsString('full page', $result->output);
    }
}
