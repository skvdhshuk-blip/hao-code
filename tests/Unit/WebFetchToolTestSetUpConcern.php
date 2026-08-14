<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait WebFetchToolTestSetUpConcern
{

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

    public function test_call_returns_error_on_connection_failure(): void
    {
        $result = $this->tool->call(
            ['url' => 'https://localhost:19999/nonexistent'],
            $this->context,
        );
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Failed to fetch', $result->output);
    }

    public function test_pre_aborted_context_does_not_issue_http_request(): void
    {
        $calls = 0;
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('must not run');
        }));
        $context = new ToolUseContext(
            sys_get_temp_dir(),
            'aborted',
            shouldAbort: static fn (): bool => true,
        );

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/pre-abort'], $context);

        $this->assertSame(ToolOutcome::Aborted, $result->outcome());
        $this->assertSame(0, $calls);
    }

    public function test_mid_stream_abort_stops_fetch_with_aborted_outcome(): void
    {
        $state = (object) ['aborted' => false];
        $body = (function () use ($state) {
            yield 'first';
            $state->aborted = true;
            yield 'second';
        })();
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse($body, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]),
        ]));
        $context = new ToolUseContext(
            sys_get_temp_dir(),
            'mid-abort',
            shouldAbort: static fn (): bool => $state->aborted,
        );

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/mid-abort'], $context);

        $this->assertTrue($state->aborted);
        $this->assertSame(ToolOutcome::Aborted, $result->outcome());
        $this->assertSame('Tool execution aborted', $result->output);
    }

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

    /**
     * @dataProvider htmlMediaTypeProvider
     */
    public function test_html_media_type_is_case_insensitive(string $contentType): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse('<h1>Converted</h1>', [
                'http_code' => 200,
                'response_headers' => ['content-type' => $contentType],
            ]),
        ]));

        $result = $tool->call(
            ['url' => 'http://127.0.0.1:9999/media-'.md5($contentType), 'format' => 'text'],
            $this->context,
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Converted', $result->output);
        $this->assertStringNotContainsString('<h1>', $result->output);
    }

    public static function htmlMediaTypeProvider(): array
    {
        return [
            ['Text/HTML; Charset=UTF-8'],
            ['APPLICATION/XHTML+XML; charset=utf-8'],
        ];
    }

    public function test_binary_content_type_is_rejected_before_returning_body_bytes(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse("\x89PNG\r\nbinary", [
                'http_code' => 200,
                'response_headers' => [
                    'content-type' => 'image/png',
                    'content-length' => '12',
                ],
            ]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/image'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unsupported response Content-Type', $result->output);
        $this->assertStringContainsString('image/png', $result->output);
        $this->assertStringNotContainsString("\x89PNG", $result->output);
    }

    public function test_missing_content_type_is_rejected(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse('body', ['http_code' => 200]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/no-type'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('missing Content-Type', $result->output);
    }

    public function test_text_response_is_normalized_to_valid_utf8(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse("valid\xFFtext", [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/invalid-utf8'], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertTrue((bool) preg_match('//u', $result->output));
        $this->assertStringContainsString('validtext', $result->output);
        $this->assertStringNotContainsString("\xFF", $result->output);
    }

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

    public function test_pre_aborted_context_does_not_read_an_existing_cache_entry(): void
    {
        $calls = 0;
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('cached', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]);
        }));
        $url = 'http://127.0.0.1:9999/abort-cache';
        $first = $tool->call(['url' => $url], $this->context);
        $this->assertFalse($first->isError);

        $abortedContext = new ToolUseContext(
            sys_get_temp_dir(),
            'aborted-cache',
            shouldAbort: static fn (): bool => true,
        );
        $second = $tool->call(['url' => $url], $abortedContext);

        $this->assertSame(1, $calls);
        $this->assertSame(ToolOutcome::Aborted, $second->outcome());
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
}
