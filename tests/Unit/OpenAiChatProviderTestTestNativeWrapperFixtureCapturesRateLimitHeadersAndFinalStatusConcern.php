<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait OpenAiChatProviderTestTestNativeWrapperFixtureCapturesRateLimitHeadersAndFinalStatusConcern
{

    public function test_native_wrapper_fixture_captures_rate_limit_headers_and_final_status(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: new MockHttpClient([]),
        );
        $reflection = new \ReflectionClass($provider);
        $headerMethod = $reflection->getMethod('extractRateLimitHeadersFromWrapperData');
        $statusMethod = $reflection->getMethod('extractStatusCodeFromWrapperData');
        $fixture = file(
            dirname(__DIR__).'/fixtures/openai-chat-native-rate-limit-headers.txt',
            FILE_IGNORE_NEW_LINES,
        );

        $this->assertIsArray($fixture);
        $headers = $headerMethod->invoke($provider, $fixture);

        $this->assertSame(429, $statusMethod->invoke($provider, $fixture));
        $this->assertSame('17', $headers['retry-after']);
        $this->assertSame('500', $headers['x-ratelimit-limit-requests']);
        $this->assertSame('0', $headers['x-ratelimit-remaining-requests']);
        $this->assertArrayNotHasKey('content-type', $headers);
        $this->assertSame($headers, $provider->getLastRateLimitHeaders());
    }

    public function test_stream_rejects_an_oversized_unterminated_sse_line(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([str_repeat('x', 4 * 1024 * 1024 + 1)], ['http_code' => 200]),
        ]);
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('SSE line exceeded');
        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));
    }

    public function test_http_client_transport_merges_custom_headers_and_protects_authorization(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            $body = 'data: ' . json_encode([
                'id' => 'chatcmpl-h',
                'model' => 'gpt-4o-mini',
                'choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            $body .= "data: [DONE]\n\n";

            return new MockResponse([$body], ['http_code' => 200]);
        });

        $provider = new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
            headers: [
                'Editor-Version' => 'vscode/1.96.0',
                'Copilot-Integration-Id' => 'vscode-chat',
                'Accept' => 'application/json',     // same-name override wins
                'Authorization' => 'Bearer stolen', // auth header stays protected
            ],
        );

        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));

        $this->assertNotNull($capturedHeaders);
        $this->assertSame('vscode/1.96.0', $this->headerLineValue($capturedHeaders, 'editor-version'));
        $this->assertSame('vscode-chat', $this->headerLineValue($capturedHeaders, 'copilot-integration-id'));
        $this->assertSame('application/json', $this->headerLineValue($capturedHeaders, 'accept'));
        $this->assertSame('Bearer real-key', $this->headerLineValue($capturedHeaders, 'authorization'));
    }

    public function test_http_client_transport_resolves_custom_headers_from_settings_manager(): void
    {
        $settings = new \HaoCode\Services\Settings\SettingsManager(getcwd());
        $settings->set('api_key', 'real-key');
        $settings->set('headers', ['Editor-Version' => 'phpstorm/2024.3']);

        $provider = (new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            httpClient: new MockHttpClient([]),
            headers: ['Editor-Version' => 'ctor-value'],
        ))->withSettingsManager($settings);

        $headers = $provider->buildRequestHeaders();

        // Runtime settings win over the constructor map.
        $this->assertSame('phpstorm/2024.3', $headers['Editor-Version']);
        $this->assertSame('Bearer real-key', $headers['Authorization']);
    }

    public function test_native_transport_header_lines_merge_custom_headers(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            headers: [
                'Editor-Version' => 'vscode/1.96.0',
                'Content-Type' => 'application/vnd.custom+json', // same-name override
                'Authorization' => 'Bearer stolen',              // auth stays protected
            ],
        );

        $lines = $provider->buildNativeHeaderLines();

        $this->assertContains('Editor-Version: vscode/1.96.0', $lines);
        $this->assertContains('Content-Type: application/vnd.custom+json', $lines);
        $this->assertContains('Authorization: Bearer real-key', $lines);
        $this->assertContains('Connection: close', $lines);
        $this->assertNotContains('Authorization: Bearer stolen', $lines);

        // The overridden hardcoded default must not linger as a duplicate.
        $contentTypeLines = array_filter($lines, fn (string $l) => stripos($l, 'content-type:') === 0);
        $this->assertCount(1, $contentTypeLines);
    }

    public function test_native_transport_respects_caller_connection_header(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'k',
            model: 'gpt-4o-mini',
            headers: ['Connection' => 'keep-alive'],
        );

        $lines = $provider->buildNativeHeaderLines();

        $this->assertContains('Connection: keep-alive', $lines);
        $this->assertNotContains('Connection: close', $lines);
    }

    public function test_http_error_preserves_rate_limit_headers_for_pool_failover(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                '{"error":{"type":"rate_limit_error","message":"slow down"}}',
                [
                    'http_code' => 429,
                    'response_headers' => [
                        'Retry-After: 23',
                        'x-ratelimit-remaining-requests: 0',
                    ],
                ],
            ),
        ]);
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
        );
        $maxRetries = new \ReflectionProperty($provider, 'maxRetries');
        $maxRetries->setValue($provider, 1);

        try {
            iterator_to_array($provider->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hi']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('rate_limit_error', $e->getErrorType());
            $this->assertSame('23', $provider->getLastRateLimitHeaders()['retry-after'] ?? null);
            $this->assertSame('0', $provider->getLastRateLimitHeaders()['x-ratelimit-remaining-requests'] ?? null);
        }
    }

    public function test_default_headers_are_unchanged_when_no_custom_headers(): void
    {
        $provider = new OpenAiChatProvider(apiKey: 'k', model: 'gpt-4o-mini');

        $this->assertSame([
            'Authorization' => 'Bearer k',
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ], $provider->buildRequestHeaders());

        $this->assertSame([
            'Authorization: Bearer k',
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Connection: close',
        ], $provider->buildNativeHeaderLines());
    }

    /**
     * @param string[] $headers
     */
    private function headerLineValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function buildSseStream(array $payloads): MockHttpClient
    {
        $body = '';
        foreach ($payloads as $payload) {
            $body .= 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new MockHttpClient([
            new MockResponse([$body], ['http_code' => 200]),
        ]);
    }

    private function collectEvents(MockHttpClient $client): array
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $client,
        );

        return iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ), false);
    }

    private function assertEventTypes(array $expected, array $events): void
    {
        $actual = array_map(fn (StreamEvent $e) => $e->type, $events);
        $this->assertSame($expected, $actual);
    }
}
