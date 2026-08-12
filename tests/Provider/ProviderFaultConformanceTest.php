<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\OpenAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class ProviderFaultConformanceTest extends TestCase
{
    private const MAX_SSE_LINE_BYTES = 4 * 1024 * 1024;

    public function test_fault_fixture_catalog_has_at_least_twenty_unique_cases(): void
    {
        $cases = iterator_to_array(self::faultCases());
        $fixtureNames = array_map(
            static fn (array $arguments): string => (string) $arguments[0]['name'],
            $cases,
        );
        $providers = array_values(array_unique(array_column(array_map(
            static fn (array $arguments): array => $arguments[0],
            $cases,
        ), 'provider')));
        sort($providers);
        $faultKindsByProvider = [];
        foreach ($cases as $arguments) {
            $fixture = $arguments[0];
            $faultKindsByProvider[$fixture['provider']][$fixture['kind']] = true;
        }
        foreach ($faultKindsByProvider as &$faultKinds) {
            $faultKinds = array_keys($faultKinds);
            sort($faultKinds);
        }
        unset($faultKinds);

        $this->assertGreaterThanOrEqual(20, count($cases));
        $this->assertCount(count($cases), array_unique($fixtureNames));
        $this->assertSame(['anthropic', 'openai', 'openai_chat'], $providers);
        $this->assertSame($faultKindsByProvider['anthropic'], $faultKindsByProvider['openai']);
        $this->assertSame($faultKindsByProvider['anthropic'], $faultKindsByProvider['openai_chat']);
    }

    /** @param array<string, mixed> $fixture */
    #[DataProvider('faultCases')]
    public function test_provider_fault_contract(array $fixture): void
    {
        $provider = $this->createProvider($fixture);
        $this->setSingleAttempt($provider);

        try {
            iterator_to_array($provider->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail("Expected fault fixture {$fixture['name']} to throw.");
        } catch (ApiErrorException $exception) {
            $this->assertSame($fixture['expected_error_type'], $exception->getErrorType());
            $this->assertStringContainsString($fixture['expected_message'], $exception->getMessage());
            if (isset($fixture['http_status'])) {
                $this->assertSame($fixture['http_status'], $exception->getCode());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function faultCases(): iterable
    {
        $paths = glob(__DIR__.'/../fixtures/provider-conformance/faults/*.json') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($fixture)) {
                throw new \RuntimeException("Invalid provider fault fixture: {$path}");
            }

            yield basename($path, '.json') => [$fixture];
        }
    }

    /** @param array<string, mixed> $fixture */
    private function createProvider(array $fixture): LlmProvider
    {
        $kind = (string) $fixture['kind'];
        $time = 0.0;
        $timeProvider = static function () use (&$time): float {
            $current = $time;
            $time += 2.0;

            return $current;
        };
        $client = match ($kind) {
            'timeout' => $this->timeoutClient(),
            'transport_error' => $this->transportErrorClient(),
            default => new MockHttpClient([$this->responseFor($fixture)]),
        };

        return match ($fixture['provider']) {
            'anthropic' => new AnthropicProvider(
                apiKey: 'test-key',
                model: 'claude-sonnet-4-6',
                httpClient: $client,
                idleTimeoutSeconds: 1,
                streamPollTimeoutSeconds: 0.01,
                timeProvider: $timeProvider,
            ),
            'openai' => new OpenAiProvider(
                apiKey: 'test-key',
                model: 'gpt-5.2',
                httpClient: $client,
                idleTimeoutSeconds: 1,
                streamPollTimeoutSeconds: 0.01,
                timeProvider: $timeProvider,
            ),
            'openai_chat' => new OpenAiChatProvider(
                apiKey: 'test-key',
                model: 'deepseek-reasoner',
                httpClient: $client,
                idleTimeoutSeconds: 1,
                streamPollTimeoutSeconds: 0.01,
                timeProvider: $timeProvider,
            ),
            default => throw new \RuntimeException("Unknown fixture provider: {$fixture['provider']}"),
        };
    }

    /** @param array<string, mixed> $fixture */
    private function responseFor(array $fixture): MockResponse
    {
        $kind = (string) $fixture['kind'];
        if ($kind === 'http_error') {
            return new MockResponse(json_encode([
                'error' => [
                    'type' => $fixture['expected_error_type'],
                    'message' => $fixture['expected_message'],
                ],
            ], JSON_THROW_ON_ERROR), [
                'http_code' => $fixture['http_status'],
                'response_headers' => ['retry-after: 0'],
            ]);
        }

        $body = match ($kind) {
            'stream_error' => $this->streamErrorBody($fixture),
            'malformed_json' => $this->malformedBody((string) $fixture['provider']),
            'non_object_json' => $this->nonObjectBody((string) $fixture['provider']),
            'oversized_line' => str_repeat('x', self::MAX_SSE_LINE_BYTES + 1),
            default => throw new \RuntimeException("Unknown fault fixture kind: {$kind}"),
        };

        return new MockResponse([$body], ['http_code' => 200]);
    }

    /** @param array<string, mixed> $fixture */
    private function streamErrorBody(array $fixture): string
    {
        $error = json_encode([
            'error' => [
                'type' => $fixture['expected_error_type'],
                'message' => $fixture['expected_message'],
            ],
        ], JSON_THROW_ON_ERROR);

        return match ($fixture['provider']) {
            'anthropic' => "event: error\ndata: {$error}\n\n",
            'openai' => "event: error\ndata: {$error}\n\n",
            'openai_chat' => "data: {$error}\n\n",
            default => throw new \RuntimeException("Unknown fixture provider: {$fixture['provider']}"),
        };
    }

    private function malformedBody(string $provider): string
    {
        return match ($provider) {
            'anthropic' => "event: message_start\ndata: {not-json}\n\n",
            'openai' => "event: response.created\ndata: {not-json}\n\n",
            'openai_chat' => "data: {not-json}\n\n",
            default => throw new \RuntimeException("Unknown fixture provider: {$provider}"),
        };
    }

    private function nonObjectBody(string $provider): string
    {
        return match ($provider) {
            'anthropic' => "event: message_start\ndata: []\n\n",
            'openai' => "event: response.created\ndata: []\n\n",
            'openai_chat' => "data: []\n\n",
            default => throw new \RuntimeException("Unknown fixture provider: {$provider}"),
        };
    }

    private function timeoutClient(): HttpClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->with(false)->willReturn([]);

        $chunk = $this->createMock(ChunkInterface::class);
        $chunk->method('isTimeout')->willReturn(true);
        $chunk->expects($this->never())->method('getContent');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->method('stream')->willReturnCallback(
            static fn (): ResponseStreamInterface => new SingleChunkResponseStream($response, $chunk),
        );

        return $client;
    }

    private function transportErrorClient(): HttpClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->with(false)->willReturn([]);

        $chunk = $this->createMock(ChunkInterface::class);
        $chunk->method('isTimeout')->willReturn(false);
        $chunk->method('getContent')->willThrowException(
            new TransportException('connection reset during stream'),
        );

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->method('stream')->willReturnCallback(
            static fn (): ResponseStreamInterface => new SingleChunkResponseStream($response, $chunk),
        );

        return $client;
    }

    private function setSingleAttempt(LlmProvider $provider): void
    {
        $property = (new \ReflectionObject($provider))->getProperty('maxRetries');
        $property->setValue($provider, 1);
    }
}

final class SingleChunkResponseStream implements ResponseStreamInterface
{
    private int $position = 0;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly ChunkInterface $chunk,
    ) {}

    public function current(): ChunkInterface
    {
        return $this->chunk;
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
        return $this->position === 0;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }
}
