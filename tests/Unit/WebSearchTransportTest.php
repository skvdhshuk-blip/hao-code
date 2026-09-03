<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\WebSearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebSearchTransportTest extends TestCase
{
    public function test_requests_send_browser_headers_and_decode_gzip(): void
    {
        $requestHeaders = [];
        $html = '<a class="result__a" href="https://example.com/result">Result title</a>';
        $compressed = gzencode($html);
        $this->assertIsString($compressed);

        $tool = new WebSearchTool;
        $tool->setClient(new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requestHeaders, $compressed) {
                $requestHeaders = $options['normalized_headers'];

                return new MockResponse($compressed, [
                    'http_code' => 200,
                    'response_headers' => ['content-encoding' => 'gzip'],
                ]);
            },
        ));

        $result = $tool->call(
            ['query' => 'test'],
            new ToolUseContext(sys_get_temp_dir(), 'test'),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('[Result title](https://example.com/result)', $result->output);
        foreach ([
            'user-agent',
            'accept',
            'accept-language',
            'accept-encoding',
            'sec-ch-ua',
            'sec-ch-ua-mobile',
            'sec-ch-ua-platform',
            'sec-fetch-dest',
            'sec-fetch-mode',
            'sec-fetch-site',
            'sec-fetch-user',
            'upgrade-insecure-requests',
        ] as $header) {
            $this->assertArrayHasKey($header, $requestHeaders);
        }
        $this->assertStringContainsString('gzip', implode(' ', $requestHeaders['accept-encoding']));
    }

    public function test_bing_fallback_request_includes_query_and_market(): void
    {
        $requestedUrls = [];
        $tool = new WebSearchTool;
        $tool->setClient(new MockHttpClient(
            function (string $method, string $url) use (&$requestedUrls) {
                $requestedUrls[] = $url;
                if (str_contains($url, 'duckduckgo')) {
                    return new MockResponse('<div class="no-results">No results.</div>');
                }

                return new MockResponse(
                    '<ol id="b_results"><li class="b_algo"><h2>'
                    .'<a href="https://example.com/bing">Bing result</a>'
                    .'</h2></li></ol>',
                );
            },
        ));

        $result = $tool->call(
            ['query' => 'php sdk'],
            new ToolUseContext(sys_get_temp_dir(), 'test'),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(2, $requestedUrls);
        $this->assertStringStartsWith('https://www.bing.com/search?', $requestedUrls[1]);
        parse_str((string) parse_url($requestedUrls[1], PHP_URL_QUERY), $query);
        $this->assertSame('php sdk', $query['q'] ?? null);
        $this->assertSame('en-US', $query['mkt'] ?? null);
    }
}
