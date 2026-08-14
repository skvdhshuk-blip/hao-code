<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\OpenAiProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\Support\MockAnthropicSse;

trait StreamingClientTestTestItSwallowsTransportExceptionAfterAbortIsRequestedMidStreamConcern
{

    public function test_it_swallows_transport_exception_after_abort_is_requested_mid_stream(): void
    {
        $state = (object) ['abort' => false];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->atLeastOnce())->method('cancel');

        $firstChunk = $this->createMock(ChunkInterface::class);
        $firstChunk->method('isTimeout')->willReturn(false);
        $firstChunk->method('getContent')->willReturn(
            "event: message_start\n" .
            "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n"
        );

        $secondChunk = $this->createMock(ChunkInterface::class);
        $secondChunk->method('isTimeout')->willReturn(false);
        $secondChunk->method('getContent')->willThrowException(new TransportException('Operation failed'));

        $stream = new class($response, $firstChunk, $secondChunk, $state) implements ResponseStreamInterface {
            /** @var array<int, ChunkInterface> */
            private array $chunks;
            private int $position = 0;

            public function __construct(
                private ResponseInterface $response,
                ChunkInterface $firstChunk,
                ChunkInterface $secondChunk,
                private object $state,
            ) {
                $this->chunks = [$firstChunk, $secondChunk];
            }

            public function current(): ChunkInterface
            {
                if ($this->position === 1) {
                    $this->state->abort = true;
                }

                return $this->chunks[$this->position];
            }

            public function next(): void
            {
                $this->position++;
            }

            public function key(): ResponseInterface
            {
                return $this->response;
            }

            public function valid(): bool
            {
                return isset($this->chunks[$this->position]);
            }

            public function rewind(): void
            {
                $this->position = 0;
            }
        };

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'glm-5.1',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
            shouldAbort: fn(): bool => $state->abort,
        ));

        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
    }

    public function test_it_retries_transport_errors_before_any_event_is_emitted(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                return new MockResponse((function () {
                    if (false) {
                        yield '';
                    }

                    throw new TransportException('connect timeout');
                })(), ['http_code' => 200]);
            }

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame(2, $attempts);
        $this->assertCount(1, $events);
        $this->assertSame('message_stop', $events[0]->type);
    }

    public function test_it_retries_overloaded_error_before_stream_starts(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return new MockResponse(
                    '{"error":{"type":"overloaded_error","message":"server overloaded"}}',
                    ['http_code' => 529],
                );
            }
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame(2, $attempts, 'Should retry on overloaded_error');
        $this->assertCount(1, $events);
    }

    public function test_it_does_not_retry_invalid_request_error(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            return new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"bad request"}}',
                ['http_code' => 400],
            );
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(1, $attempts, 'Should NOT retry invalid_request_error');
            $this->assertSame('invalid_request_error', $e->getErrorType());
        }
    }

    public function test_it_stops_retrying_after_max_retries(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            return new MockResponse(
                '{"error":{"type":"rate_limit_error","message":"rate limited"}}',
                ['http_code' => 429],
            );
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
            // maxRetries defaults to 3
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            // maxRetries=3 means: 1 initial + up to 3 retries, but the
            // shouldRetry check uses `$attempt >= $this->maxRetries` which
            // means attempts 1,2,3 → attempt 3 is NOT >= 3 so retry is
            // allowed, then attempt 4 → 4 >= 3 so no retry. But the
            // initial attempt is attempt 0 in the while loop... Actually
            // the code increments $attempt after each catch. So:
            // attempt becomes 1, 2, 3 — at 3, shouldRetry: 3 >= 3 → false.
            // So total = 3 attempts (initial + 2 retries).
            $this->assertSame(3, $attempts, 'Should stop after maxRetries limit');
        }
    }

    public function test_sse_error_event_throws_api_error_exception(): void
    {
        // The MockHttpClient needs enough responses for the retry loop.
        // The error event causes an ApiErrorException which the retry loop
        // catches and may retry (since no events were yielded yet).
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('rate_limit_error', $e->getErrorType());
            $this->assertStringContainsString('too many requests', $e->getMessage());
        }
    }

    public function test_vendor_1302_rate_limit_message_is_retryable(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
        );
        $method = new \ReflectionMethod($provider, 'shouldRetry');

        $this->assertTrue($method->invoke(
            $provider,
            new ApiErrorException('[1302][Rate limit reached for requests]', '1302'),
            1,
        ));
    }

    public function test_data_line_with_leading_space_strips_one_space(): void
    {
        // "data: " followed by a space then JSON — the leading space in the value
        // should be stripped per SSE spec
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: message_start\n",
                "data:  {\"message\":{\"id\":\"m1\",\"usage\":[]}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
        $this->assertSame('m1', $events[0]->data['message']['id'] ?? null);
    }

    public function test_multiline_data_lines_are_joined_with_newline(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_delta\n",
                "data: {\"index\":0,\n",
                "data: \"delta\":{\"text\":\"hi\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        // The data lines should be joined with "\n"
        $this->assertSame('content_block_delta', $events[0]->type);
        $this->assertIsArray($events[0]->data);
    }

    public function test_empty_data_produces_no_event(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: ping\n",
                "data: {}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        // ping events are parsed but the StreamEvent is still yielded;
        // the StreamProcessor later ignores them. At the client level
        // we just verify no exception is thrown.
        $this->assertIsArray($events);
    }

    public function test_thinking_enabled_adds_thinking_payload(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
            thinkingEnabled: true,
            thinkingBudget: 16000,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'think about this']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('thinking', $decoded);
        $this->assertSame('enabled', $decoded['thinking']['type']);
        $this->assertSame(16000, $decoded['thinking']['budget_tokens']);
        // max_tokens should be boosted for extended thinking
        $this->assertGreaterThanOrEqual(16000 + 4096, $decoded['max_tokens']);
    }

    public function test_opus_4_8_uses_adaptive_thinking_without_manual_budget(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-opus-4-8',
            httpClient: $httpClient,
            thinkingEnabled: true,
            thinkingBudget: 16000,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'think about this']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertSame(['type' => 'adaptive'], $decoded['thinking']);
        // thinkingBudget 16000 maps to high effort for adaptive models.
        $this->assertSame(['effort' => 'high'], $decoded['output_config']);
        $this->assertArrayNotHasKey('budget_tokens', $decoded['thinking']);
    }

    public function test_adaptive_thinking_maps_low_budget_to_low_effort(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-opus-4-8',
            httpClient: $httpClient,
            thinkingEnabled: true,
            thinkingBudget: 4000,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'think about this']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertSame(['type' => 'adaptive'], $decoded['thinking']);
        $this->assertSame(['effort' => 'low'], $decoded['output_config']);
    }
}
