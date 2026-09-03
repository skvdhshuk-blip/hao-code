<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineRegistry;
use HaoCode\Tools\WebSearch\WebSearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebSearchToolTest extends TestCase
{
    public function test_schema_exposes_optional_unique_engine_subset(): void
    {
        $inputSchema = $this->newTool()->inputSchema();
        $schema = $inputSchema->toJsonSchema();

        $this->assertSame(['query'], $schema['required']);
        $this->assertSame(
            ['bing', 'duckduckgo', 'sogou', '360', 'yahoo'],
            $schema['properties']['engines']['items']['enum'],
        );
        $this->assertSame(1, $schema['properties']['engines']['minItems']);
        $this->assertSame(5, $schema['properties']['engines']['maxItems']);
        $this->assertTrue($schema['properties']['engines']['uniqueItems']);
        $this->assertStringContainsString('date', strtolower($schema['properties']['query']['description']));
        $this->assertSame(
            ['query' => 'test', 'engines' => ['360']],
            $inputSchema->validate(['query' => 'test', 'engines' => ['360']]),
        );
    }

    public function test_default_call_fans_out_to_all_five_engines_and_never_google(): void
    {
        $searchUrls = [];
        $tool = $this->tool([], $searchUrls);

        $result = $tool->call(['query' => 'php sdk'], $this->context());

        $this->assertFalse($result->isError);
        $this->assertCount(5, $searchUrls);
        $this->assertSame(
            ['bing', 'duckduckgo', 'sogou', '360', 'yahoo'],
            $result->data['selected_engines'],
        );
        $this->assertStringNotContainsString('google.', implode(' ', $searchUrls));
        $bingQuery = $this->queryForHost($searchUrls, 'www.bing.com');
        $this->assertSame('php sdk', $bingQuery['q'] ?? null);
        $this->assertSame('en-US', $bingQuery['mkt'] ?? null);
    }

    public function test_model_markdown_stays_clean_while_data_contains_provenance_and_score(): void
    {
        $tool = $this->tool([
            'bing' => $this->bing('HTTP title', 'http://www.example.com/page', 'Bing snippet'),
            'duckduckgo' => $this->ddg('Clean title', 'https://example.com/page', 'Clean snippet'),
        ]);

        $result = $tool->call(['query' => 'test'], $this->context());

        $this->assertFalse($result->isError);
        $this->assertSame(
            "Search results for: \"test\"\n\n1. [Clean title](https://example.com/page)\n   Clean snippet\n\n",
            $result->output,
        );
        $this->assertStringNotContainsString('duckduckgo', strtolower($result->output));
        $this->assertStringNotContainsString('score', strtolower($result->output));
        $this->assertSame('web_search', $result->data['type']);
        $this->assertSame(1, $result->data['schema_version']);
        $this->assertFalse($result->data['partial']);
        $this->assertSame(['duckduckgo', 'bing'], $result->data['results'][0]['engines']);
        $this->assertSame(['duckduckgo' => 1, 'bing' => 1], $result->data['results'][0]['positions']);
        $this->assertSame(4.0, $result->data['results'][0]['score']);
        $this->assertCount(5, $result->data['stats']);
    }

    public function test_two_engine_failures_with_results_are_success_and_silent_in_model_text(): void
    {
        $tool = $this->tool([
            'duckduckgo' => $this->ddg('Working result', 'https://example.com/ok', 'Useful'),
            'sogou' => new MockResponse('SECRET_BACKEND_BODY', ['http_code' => 503]),
            'yahoo' => new MockResponse('<main>unexpected layout</main>', ['http_code' => 200]),
        ]);

        $result = $tool->call(['query' => 'test'], $this->context());

        $this->assertFalse($result->isError);
        $this->assertTrue($result->data['partial']);
        $this->assertStringContainsString('Working result', $result->output);
        $this->assertStringNotContainsString('http_error', $result->output);
        $this->assertStringNotContainsString('SECRET_BACKEND_BODY', $result->output);
        $stats = array_column($result->data['stats'], null, 'engine');
        $this->assertSame('http_error', $stats['sogou']['status']);
        $this->assertSame(503, $stats['sogou']['http_status']);
        $this->assertSame('http_status', $stats['sogou']['error']);
        $this->assertSame('parse_error', $stats['yahoo']['status']);
        $this->assertSame('unexpected_markup', $stats['yahoo']['error']);
    }

    public function test_all_explicitly_empty_engines_keep_existing_no_results_text(): void
    {
        $result = $this->tool()->call(['query' => 'nothing'], $this->context());

        $this->assertFalse($result->isError);
        $this->assertSame('No search results found for: nothing', $result->output);
        $this->assertFalse($result->data['partial']);
        $this->assertSame([], $result->data['results']);
        $this->assertSame(
            array_fill(0, 5, 'success_empty'),
            array_column($result->data['stats'], 'status'),
        );
    }

    public function test_no_results_plus_any_failure_is_an_error_with_ordered_statuses(): void
    {
        $tool = $this->tool([
            'duckduckgo' => new MockResponse('<main>unexpected</main>', ['http_code' => 200]),
        ]);

        $result = $tool->call(['query' => 'test'], $this->context());

        $this->assertTrue($result->isError);
        $this->assertTrue($result->data['partial']);
        $this->assertSame(
            'Web search failed with no usable results. Backend statuses: '
            .'Bing=success_empty, DuckDuckGo=parse_error, Sogou=success_empty, '
            .'360=success_empty, Yahoo=success_empty.',
            $result->output,
        );
    }

    public function test_domain_policy_keeps_true_subdomains_and_blocked_wins(): void
    {
        $tool = $this->tool([
            'duckduckgo' => $this->ddg('Allowed', 'https://docs.example.com/a', 'one')
                .$this->ddg('Suffix lookalike', 'https://notexample.com/b', 'two')
                .$this->ddg('Blocked', 'https://private.example.com/c', 'three'),
        ]);

        $result = $tool->call([
            'query' => 'test',
            'allowed_domains' => ['example.com'],
            'blocked_domains' => ['private.example.com'],
        ], $this->context());

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('docs.example.com', $result->output);
        $this->assertStringNotContainsString('notexample.com', $result->output);
        $this->assertStringNotContainsString('private.example.com', $result->output);
        $this->assertSame(1, $result->data['results'][0]['positions']['duckduckgo']);
        $this->assertSame(3, $result->data['stats'][1]['count']);
    }

    public function test_explicit_engine_subset_dispatches_only_selected_engines(): void
    {
        $searchUrls = [];
        $tool = $this->tool([
            'duckduckgo' => $this->ddg('Only result', 'https://example.com/only', ''),
        ], $searchUrls);

        $result = $tool->call([
            'query' => 'test',
            'engines' => ['duckduckgo'],
        ], $this->context());

        $this->assertFalse($result->isError);
        $this->assertCount(1, $searchUrls);
        $this->assertStringContainsString('html.duckduckgo.com', $searchUrls[0]);
        $this->assertSame(['duckduckgo'], $result->data['selected_engines']);
        $this->assertCount(1, $result->data['stats']);
    }

    public function test_invalid_engine_selection_fails_before_network_io(): void
    {
        $requests = [];
        $tool = $this->tool([], $requests);
        $context = $this->context();

        $this->assertSame(
            'Duplicate WebSearch engine selection: bing',
            $tool->validateInput(['query' => 'test', 'engines' => ['bing', 'bing']], $context),
        );
        $result = $tool->call(['query' => 'test', 'engines' => ['google']], $context);

        $this->assertTrue($result->isError);
        $this->assertSame('Unknown WebSearch engine: google', $result->output);
        $this->assertSame([], $requests);
    }

    public function test_abort_before_warmup_returns_aborted_without_network_io(): void
    {
        $requests = [];
        $tool = $this->tool([], $requests);
        $context = new ToolUseContext(sys_get_temp_dir(), 'test', shouldAbort: static fn (): bool => true);

        $result = $tool->call(['query' => 'test'], $context);

        $this->assertSame('aborted', $result->outcome()->value);
        $this->assertSame([], $requests);
        $this->assertNull($result->data);
    }

    public function test_tool_identity_and_permissions_are_unchanged(): void
    {
        $tool = $this->newTool();

        $this->assertSame('WebSearch', $tool->name());
        $this->assertTrue($tool->isReadOnly([]));
        $this->assertTrue($tool->isConcurrencySafe([]));
        $this->assertStringContainsString("today's date", strtolower($tool->description()));
    }

    /**
     * @param array<string, string|MockResponse> $responses
     * @param list<string> $searchUrls
     */
    private function tool(array $responses = [], array &$searchUrls = []): WebSearchTool
    {
        $tool = $this->newTool();
        $tool->setClient(new MockHttpClient(function (string $method, string $url) use ($responses, &$searchUrls): MockResponse {
            $engine = $this->engineForUrl($url);
            if ($this->isWarmup($url)) {
                return new MockResponse('', [
                    'http_code' => 204,
                    'response_headers' => ['set-cookie' => 'hao=code; Path=/; Secure'],
                ]);
            }

            $searchUrls[] = $url;
            $response = $responses[$engine] ?? $this->emptyHtml($engine);

            return $response instanceof MockResponse
                ? $response
                : new MockResponse($response, ['http_code' => 200]);
        }));

        return $tool;
    }

    private function newTool(): WebSearchTool
    {
        return new WebSearchTool(EngineRegistry::createDefault());
    }

    private function context(): ToolUseContext
    {
        return new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    private function engineForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return match ($host) {
            'www.bing.com' => 'bing',
            'duckduckgo.com', 'html.duckduckgo.com' => 'duckduckgo',
            'www.sogou.com' => 'sogou',
            'www.so.com' => '360',
            'search.yahoo.com' => 'yahoo',
            default => throw new \RuntimeException("Unexpected URL: {$url}"),
        };
    }

    private function isWarmup(string $url): bool
    {
        return (parse_url($url, PHP_URL_PATH) ?: '/') === '/'
            && parse_url($url, PHP_URL_QUERY) === null;
    }

    private function emptyHtml(string $engine): string
    {
        return match ($engine) {
            'bing' => '<ol id="b_results"><li class="b_no">There are no results found.</li></ol>',
            'duckduckgo' => '<div class="no-results">No results.</div>',
            'sogou' => '<main>抱歉，没有找到相关结果</main>',
            '360' => '<main>未找到相关结果</main>',
            'yahoo' => '<main>We did not find results for this query.</main>',
        };
    }

    private function ddg(string $title, string $url, string $snippet): string
    {
        $redirect = '//duckduckgo.com/l/?uddg='.rawurlencode($url);

        return '<div class="result web-result"><h2 class="result__title">'
            .'<a class="result__a" href="'.$redirect.'">'.$title.'</a></h2>'
            .'<a class="result__snippet">'.$snippet.'</a></div>';
    }

    private function bing(string $title, string $url, string $snippet): string
    {
        return '<ol id="b_results"><li class="b_algo"><h2><a href="'.$url.'">'
            .$title.'</a></h2><p>'.$snippet.'</p></li></ol>';
    }

    /** @param list<string> $urls */
    private function queryForHost(array $urls, string $host): array
    {
        foreach ($urls as $url) {
            if (parse_url($url, PHP_URL_HOST) === $host) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return $query;
            }
        }

        return [];
    }
}
