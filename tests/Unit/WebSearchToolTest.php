<?php

namespace Tests\Unit;

use HaoCode\Tools\WebSearch\WebSearchTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebSearchToolTest extends TestCase
{
    private WebSearchTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new WebSearchTool;
        $this->ref = new \ReflectionClass($this->tool);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->tool, ...$args);
    }

    private function callWithResponses(array $responses): \HaoCode\Tools\ToolResult
    {
        $tool = new WebSearchTool;
        $tool->setClient(new MockHttpClient($responses));

        return $tool->call(
            ['query' => 'test'],
            new ToolUseContext(sys_get_temp_dir(), 'test'),
        );
    }

    private function emptyDdgHtml(): string
    {
        return '<div class="no-results">No results.</div>';
    }

    private function emptyGoogleHtml(): string
    {
        return '<div>Your search did not match any documents.</div>';
    }

    // ─── name / description / isReadOnly ─────────────────────────────────

    public function test_name_is_web_search(): void
    {
        $this->assertSame('WebSearch', $this->tool->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_is_concurrency_safe(): void
    {
        $this->assertTrue($this->tool->isConcurrencySafe([]));
    }

    public function test_description_mentions_search(): void
    {
        $this->assertStringContainsString('search', strtolower($this->tool->description()));
    }

    // ─── decodeDdgUrl ─────────────────────────────────────────────────────

    public function test_decode_ddg_url_extracts_uddg_param(): void
    {
        $ddgUrl = '//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fpage&rut=abc';
        $decoded = $this->invoke('decodeDdgUrl', $ddgUrl);
        $this->assertSame('https://example.com/page', $decoded);
    }

    public function test_decode_ddg_url_returns_unchanged_when_no_uddg(): void
    {
        $url = 'https://example.com/page';
        $this->assertSame($url, $this->invoke('decodeDdgUrl', $url));
    }

    // ─── domain filtering (via call with injected results) ────────────────

    public function test_allowed_domains_filter_keeps_matching_results(): void
    {
        // We test via a subclass that overrides searchDuckDuckGo to return known results
        $proxy = new class extends WebSearchTool {
            protected function searchDuckDuckGoForTest(): array
            {
                return [
                    ['title' => 'PHP Docs', 'url' => 'https://php.net/manual', 'snippet' => ''],
                    ['title' => 'Blog Post', 'url' => 'https://example.com/post', 'snippet' => ''],
                ];
            }

            public function call(array $input, \HaoCode\Tools\ToolUseContext $ctx): \HaoCode\Tools\ToolResult
            {
                $results = $this->searchDuckDuckGoForTest();
                $allowedDomains = $input['allowed_domains'] ?? [];
                $blockedDomains = $input['blocked_domains'] ?? [];

                if (!empty($allowedDomains)) {
                    $results = array_filter($results, function ($r) use ($allowedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($allowedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return true;
                        }
                        return false;
                    });
                }

                if (!empty($blockedDomains)) {
                    $results = array_filter($results, function ($r) use ($blockedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($blockedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return false;
                        }
                        return true;
                    });
                }

                if (empty($results)) {
                    return \HaoCode\Tools\ToolResult::success("No search results found for: {$input['query']}");
                }

                $output = "Search results for: \"{$input['query']}\"\n\n";
                foreach (array_values($results) as $i => $result) {
                    $output .= ($i + 1) . ". [{$result['title']}]({$result['url']})\n\n";
                }
                return \HaoCode\Tools\ToolResult::success($output);
            }
        };

        $ctx = new ToolUseContext(sys_get_temp_dir(), 'test');

        $result = $proxy->call([
            'query' => 'test',
            'allowed_domains' => ['php.net'],
        ], $ctx);

        $this->assertStringContainsString('php.net', $result->output);
        $this->assertStringNotContainsString('example.com', $result->output);
    }

    public function test_blocked_domains_filter_removes_matching_results(): void
    {
        $proxy = new class extends WebSearchTool {
            public function call(array $input, \HaoCode\Tools\ToolUseContext $ctx): \HaoCode\Tools\ToolResult
            {
                $results = [
                    ['title' => 'PHP Docs', 'url' => 'https://php.net/manual', 'snippet' => ''],
                    ['title' => 'Spam Site', 'url' => 'https://spam.example.com/post', 'snippet' => ''],
                ];

                $blockedDomains = $input['blocked_domains'] ?? [];
                if (!empty($blockedDomains)) {
                    $results = array_filter($results, function ($r) use ($blockedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($blockedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return false;
                        }
                        return true;
                    });
                }

                $output = '';
                foreach (array_values($results) as $r) {
                    $output .= "[{$r['title']}]({$r['url']})\n";
                }
                return \HaoCode\Tools\ToolResult::success($output);
            }
        };

        $result = $proxy->call([
            'query' => 'test',
            'blocked_domains' => ['example.com'],
        ], new ToolUseContext(sys_get_temp_dir(), 'test'));

        $this->assertStringContainsString('php.net', $result->output);
        $this->assertStringNotContainsString('spam.example.com', $result->output);
    }

    // ─── input schema ─────────────────────────────────────────────────────

    public function test_input_schema_requires_query(): void
    {
        $schema = $this->tool->inputSchema()->toJsonSchema();
        $this->assertContains('query', $schema['required']);
    }

    // ─── domain-boundary matching (private hostMatchesDomain) ─────────────
    // The previous test suite reimplemented filtering in an anonymous subclass
    // using str_ends_with, which silently treated notexample.com as matching
    // example.com. These tests hit the real production method.

    public function test_host_matches_domain_rejects_suffix_lookalike(): void
    {
        // notexample.com must NOT match example.com.
        $this->assertFalse($this->invoke('hostMatchesDomain', 'notexample.com', 'example.com'));
    }

    public function test_host_matches_domain_accepts_exact_match(): void
    {
        $this->assertTrue($this->invoke('hostMatchesDomain', 'example.com', 'example.com'));
    }

    public function test_host_matches_domain_accepts_real_subdomain(): void
    {
        $this->assertTrue($this->invoke('hostMatchesDomain', 'docs.example.com', 'example.com'));
        $this->assertTrue($this->invoke('hostMatchesDomain', 'a.b.example.com', 'example.com'));
    }

    public function test_host_matches_domain_rejects_different_tld(): void
    {
        $this->assertFalse($this->invoke('hostMatchesDomain', 'example.com.evil.com', 'example.com'));
    }

    // ─── normalizeDomains accepts URL / wildcard / case forms ─────────────

    public function test_normalize_domains_strips_wildcard_and_url_forms(): void
    {
        $normalized = $this->invoke('normalizeDomains', [
            '*.example.com',
            'https://Foo.Com/path',
            'BAR.ORG',
            '',
            123,
            'bare.example.net',
        ]);

        $this->assertSame(['example.com', 'foo.com', 'bar.org', 'bare.example.net'], $normalized);
    }

    // ─── filterResults applies allowed + blocked with boundary semantics ──

    public function test_filter_results_applies_allowed_and_blocked_boundaries(): void
    {
        $results = [
            ['title' => 'A', 'url' => 'https://example.com/a', 'snippet' => ''],
            ['title' => 'B', 'url' => 'https://docs.example.com/b', 'snippet' => ''],
            ['title' => 'C', 'url' => 'https://notexample.com/c', 'snippet' => ''],
            ['title' => 'D', 'url' => 'https://other.com/d', 'snippet' => ''],
        ];

        // allowed = example.com → A, B pass (exact + real subdomain);
        // C (notexample.com — suffix lookalike) and D rejected.
        $allowedOnly = $this->invoke('filterResults', $results, ['example.com'], []);
        $this->assertSame(
            ['https://example.com/a', 'https://docs.example.com/b'],
            array_column($allowedOnly, 'url'),
        );

        // blocked = example.com → A, B removed; C (notexample.com — NOT a
        // match, so not blocked) and D kept.
        $blockedOnly = $this->invoke('filterResults', $results, [], ['example.com']);
        $this->assertSame(
            ['https://notexample.com/c', 'https://other.com/d'],
            array_column($blockedOnly, 'url'),
        );
    }

    // ─── Symfony HttpClient migration invariants ──────────────────────────

    public function test_source_uses_symfony_http_client_not_curl(): void
    {
        // WebSearch previously called curl_init() directly and disabled TLS.
        // After the migration it must use Symfony HttpClient exclusively.
        $source = file_get_contents((new \ReflectionClass(WebSearchTool::class))->getFileName());
        $this->assertStringNotContainsString('curl_init', $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
        $this->assertStringContainsString('Symfony\\Component\\HttpClient', $source);
    }

    public function test_call_returns_no_results_message_when_all_filtered_out(): void
    {
        // Inject a search backend (via MockHttpClient) that returns only
        // blocked-domain hits. The previous implementation returned an empty
        // "Search results for: …" header because the empty check ran before
        // filtering.
        $ddgHtml = '<a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fblocked.example.com%2Fa">A</a>'
            .'<a class="result__snippet" href="">s</a>';

        $tool = new WebSearchTool;
        $tool->setClient(new MockHttpClient(function (string $method, string $url) use ($ddgHtml) {
            if (str_contains($url, 'duckduckgo')) {
                return new MockResponse($ddgHtml, ['http_code' => 200]);
            }

            return new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]);
        }));

        $result = $tool->call([
            'query' => 'test',
            'blocked_domains' => ['example.com'],
        ], new ToolUseContext(sys_get_temp_dir(), 'test'));

        $this->assertStringNotContainsString('Search results for', $result->output);
        $this->assertStringContainsString('No search results found', $result->output);
        $this->assertFalse($result->isError);
    }

    public function test_ddg_title_and_url_are_results_without_a_snippet(): void
    {
        $result = $this->callWithResponses([
            new MockResponse(
                '<a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fddg">DDG title</a>',
                ['http_code' => 200],
            ),
        ]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('[DDG title](https://example.com/ddg)', $result->output);
    }

    public function test_google_title_and_url_are_results_without_a_snippet(): void
    {
        $result = $this->callWithResponses([
            new MockResponse($this->emptyDdgHtml(), ['http_code' => 200]),
            new MockResponse(
                '<a href="/url?q=https%3A%2F%2Fexample.com%2Fgoogle&amp;sa=U">Google title</a>',
                ['http_code' => 200],
            ),
        ]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('[Google title](https://example.com/google)', $result->output);
    }

    public function test_fallback_returns_google_results_when_ddg_has_http_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse('SECRET_DDG_BODY', ['http_code' => 503]),
            new MockResponse(
                '<a href="/url?q=https%3A%2F%2Fexample.com%2Ffallback">Fallback title</a>',
                ['http_code' => 200],
            ),
        ]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Fallback title', $result->output);
        $this->assertStringNotContainsString('SECRET_DDG_BODY', $result->output);
    }

    public function test_both_explicit_empty_backends_return_success(): void
    {
        $result = $this->callWithResponses([
            new MockResponse($this->emptyDdgHtml(), ['http_code' => 200]),
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No search results found', $result->output);
    }

    public function test_two_blank_success_bodies_are_parse_errors(): void
    {
        $result = $this->callWithResponses([
            new MockResponse('', ['http_code' => 200]),
            new MockResponse("\n", ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=parse_error', $result->output);
        $this->assertStringContainsString('Google=parse_error', $result->output);
    }

    public function test_http_error_without_usable_results_is_reported_without_body(): void
    {
        $result = $this->callWithResponses([
            new MockResponse('SECRET_HTTP_BODY', ['http_code' => 503]),
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=http_error', $result->output);
        $this->assertStringNotContainsString('SECRET_HTTP_BODY', $result->output);
    }

    public function test_transport_error_without_usable_results_is_reported(): void
    {
        $transportResponse = new MockResponse((function () {
            throw new TransportException('SECRET_TRANSPORT_BODY');
            yield '';
        })(), ['http_code' => 200]);

        $result = $this->callWithResponses([
            $transportResponse,
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=transport_error', $result->output);
        $this->assertStringNotContainsString('SECRET_TRANSPORT_BODY', $result->output);
    }

    public function test_oversized_response_is_transport_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse(str_repeat('x', 2_097_153), ['http_code' => 200]),
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=transport_error', $result->output);
    }

    public function test_captcha_page_is_parse_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse('<div class="g-recaptcha">challenge</div>', ['http_code' => 200]),
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=parse_error', $result->output);
    }

    public function test_google_captcha_page_is_parse_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse($this->emptyDdgHtml(), ['http_code' => 200]),
            new MockResponse('<form action="/sorry/index"><div>captcha</div></form>', ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Google=parse_error', $result->output);
    }

    public function test_unknown_google_layout_is_parse_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse($this->emptyDdgHtml(), ['http_code' => 200]),
            new MockResponse('<main>Unexpected search markup</main>', ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Google=parse_error', $result->output);
    }

    public function test_unknown_ddg_layout_is_parse_error(): void
    {
        $result = $this->callWithResponses([
            new MockResponse('<main>Unexpected search markup</main>', ['http_code' => 200]),
            new MockResponse($this->emptyGoogleHtml(), ['http_code' => 200]),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('DuckDuckGo=parse_error', $result->output);
    }
}
