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

trait StreamingClientTestMakeChunkConcern
{
    private function makeChunk(bool $isTimeout, ?string $content = null): ChunkInterface
    {
        $chunk = $this->createMock(ChunkInterface::class);
        $chunk->method('isTimeout')->willReturn($isTimeout);

        if ($isTimeout) {
            $chunk->expects($this->never())->method('getContent');
        } else {
            $chunk->method('getContent')->willReturn($content ?? '');
        }

        return $chunk;
    }

    /**
     * @param array<int, ChunkInterface> $chunks
     */
    private function makeStreamingHttpClient(array $chunks, ?int &$requests = null): HttpClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->with(false)->willReturn([]);

        $stream = new class($response, $chunks) implements ResponseStreamInterface {
            /** @param array<int, ChunkInterface> $chunks */
            public function __construct(
                private ResponseInterface $response,
                private array $chunks,
                private int $position = 0,
            ) {}

            public function current(): ChunkInterface
            {
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
        $httpClient->method('request')->willReturnCallback(function () use ($response, &$requests) {
            if ($requests !== null) {
                $requests++;
            }

            return $response;
        });
        $httpClient->method('stream')->willReturn($stream);

        return $httpClient;
    }

    public function test_it_throws_a_readable_error_when_request_payload_cannot_be_encoded(): void
    {
        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: new MockHttpClient([]),
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Failed to encode request payload');

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => "\xB1\x31"]],
            tools: [],
        ));
    }

    public function test_it_includes_http_response_body_in_api_errors(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"unsupported field tools"}}',
                ['http_code' => 400]
            ),
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

            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('invalid_request_error', $e->getErrorType());
            $this->assertStringContainsString('unsupported field tools', $e->getMessage());
        }
    }

    public function test_it_parses_sse_events_when_event_lines_are_split_across_chunks(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_st",
                "op\n",
                "data: {\"index\":0}\n\n",
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
        $this->assertSame('content_block_stop', $events[0]->type);
        $this->assertSame(['index' => 0], $events[0]->data);
    }

    public function test_it_parses_sse_events_when_data_lines_are_split_across_chunks(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: message_delta\n",
                "data: {\"delta\":{\"stop_rea",
                "son\":\"tool_use\"}}\n\n",
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
        $this->assertSame('message_delta', $events[0]->type);
        $this->assertSame('tool_use', $events[0]->data['delta']['stop_reason'] ?? null);
    }

    public function test_it_flushes_a_pending_event_before_the_next_event_header(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_stop\n",
                "data: {\"index\":0}\n",
                "event: message_delta\n",
                "data: {\"delta\":{\"stop_reason\":\"tool_use\"}}\n\n",
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

        $this->assertCount(2, $events);
        $this->assertSame('content_block_stop', $events[0]->type);
        $this->assertSame(['index' => 0], $events[0]->data);
        $this->assertSame('message_delta', $events[1]->type);
        $this->assertSame('tool_use', $events[1]->data['delta']['stop_reason'] ?? null);
    }

    public function test_it_retries_after_handshake_event_before_response_state_is_committed(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;

            if ($attempts > 1) {
                return MockAnthropicSse::textResponse('recovered');
            }

            return new MockResponse((function () {
                yield "event: message_start\n";
                yield "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n";
                throw new TransportException('stream interrupted');
            })(), ['http_code' => 200]);
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
        $this->assertContains('content_block_delta', array_column($events, 'type'));
    }

    public function test_it_throws_stream_timeout_when_no_new_data_arrives(): void
    {
        $attempts = 0;
        $httpClient = $this->makeStreamingHttpClient([
            $this->makeChunk(isTimeout: true),
        ], $attempts);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
            idleTimeoutSeconds: 0,
            streamPollTimeoutSeconds: 0.01,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('stream_timeout', $e->getErrorType());
            $this->assertStringContainsString('stalled', $e->getMessage());
            $this->assertSame(3, $attempts, 'Should retry stalled streams before any event is emitted');
        }
    }

    public function test_it_retries_stream_timeout_after_only_handshake_event(): void
    {
        $attempts = 0;
        $httpClient = $this->makeStreamingHttpClient([
            $this->makeChunk(isTimeout: false, content: "event: message_start\n"),
            $this->makeChunk(isTimeout: false, content: "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n"),
            $this->makeChunk(isTimeout: true),
        ], $attempts);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
            idleTimeoutSeconds: 0,
            streamPollTimeoutSeconds: 0.01,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('stream_timeout', $e->getErrorType());
            $this->assertSame(3, $attempts, 'Handshake-only attempts are safe to retry');
        }
    }

    public function test_it_does_not_retry_after_content_block_has_started(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;

            return new MockResponse((function () {
                yield "event: message_start\n";
                yield "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n";
                yield "event: content_block_start\n";
                yield "data: {\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n";
                throw new TransportException('stream interrupted after content');
            })(), ['http_code' => 200]);
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
            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('transport_error', $e->getErrorType());
            $this->assertSame(1, $attempts);
        }
    }

    public function test_it_returns_early_without_request_when_already_aborted(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests) {
            $requests++;

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
            shouldAbort: fn(): bool => true,
        ));

        $this->assertSame(0, $requests);
        $this->assertSame([], $events);
    }

    public function test_it_cancels_active_response_when_abort_is_requested_mid_stream(): void
    {
        $state = (object) ['abort' => false];

        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('cancel');

        $firstChunk = $this->createMock(\Symfony\Contracts\HttpClient\ChunkInterface::class);
        $firstChunk->method('getContent')->willReturn(
            "event: message_start\n" .
            "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n"
        );

        $secondChunk = $this->createMock(\Symfony\Contracts\HttpClient\ChunkInterface::class);
        $secondChunk->expects($this->never())->method('getContent');

        $stream = new class($response, $firstChunk, $secondChunk, $state) implements \Symfony\Contracts\HttpClient\ResponseStreamInterface {
            /** @var array<int, \Symfony\Contracts\HttpClient\ChunkInterface> */
            private array $chunks;
            private int $position = 0;

            public function __construct(
                private \Symfony\Contracts\HttpClient\ResponseInterface $response,
                \Symfony\Contracts\HttpClient\ChunkInterface $firstChunk,
                \Symfony\Contracts\HttpClient\ChunkInterface $secondChunk,
                private object $state,
            ) {
                $this->chunks = [$firstChunk, $secondChunk];
            }

            public function current(): \Symfony\Contracts\HttpClient\ChunkInterface
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

            public function key(): \Symfony\Contracts\HttpClient\ResponseInterface
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

        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
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
}
