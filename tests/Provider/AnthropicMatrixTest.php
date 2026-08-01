<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\LlmProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnthropicMatrixTest extends AbstractMatrixTest
{
    protected function providerName(): string
    {
        return 'anthropic';
    }

    protected function createMockProvider(string $sseFixturePath): LlmProvider
    {
        return new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-haiku-4-5-20251001',
            httpClient: self::buildMockClient($sseFixturePath),
        );
    }

    protected function createMockProviderMulti(MockHttpClient $client): LlmProvider
    {
        return new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-haiku-4-5-20251001',
            httpClient: $client,
        );
    }

    public function test_cache_usage_shape(): void
    {
        $provider = $this->createMockProvider($this->ssePath('text-only'));
        $fixture = $this->loadFixture('text-only');

        $events = iterator_to_array(
            $provider->streamMessages(
                systemPrompt: [],
                messages: $fixture['messages'],
                tools: [],
            ),
            false,
        );

        $startEvent = null;
        foreach ($events as $event) {
            if ($event->type === 'message_start') {
                $startEvent = $event;
                break;
            }
        }

        $this->assertNotNull($startEvent, 'message_start event must be present');

        $usage = $startEvent->data['message']['usage'] ?? [];
        $this->assertArrayHasKey(
            'cache_creation_input_tokens',
            $usage,
            'Anthropic message_start usage must contain cache_creation_input_tokens',
        );
        $this->assertArrayHasKey(
            'cache_read_input_tokens',
            $usage,
            'Anthropic message_start usage must contain cache_read_input_tokens',
        );
    }

    public function test_stream_rejects_an_oversized_unterminated_sse_line(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-haiku-4-5-20251001',
            httpClient: new MockHttpClient([
                new MockResponse([str_repeat('x', 4 * 1024 * 1024 + 1)], ['http_code' => 200]),
            ]),
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('SSE line exceeded');
        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));
    }

    public function test_stream_rejects_an_oversized_multiline_sse_event(): void
    {
        $body = "event: message_start\n";
        for ($index = 0; $index < 5; $index++) {
            $body .= 'data: '.str_repeat('x', 900_000)."\n";
        }
        $body .= "\n";

        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-haiku-4-5-20251001',
            httpClient: new MockHttpClient([
                new MockResponse([$body], ['http_code' => 200]),
            ]),
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Streaming SSE line exceeded');
        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));
    }
}
