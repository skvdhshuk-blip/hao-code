<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineHttpResponse;
use HaoCode\Tools\WebSearch\Engine\EngineParseResult;
use HaoCode\Tools\WebSearch\Engine\RawSearchResult;
use HaoCode\Tools\WebSearch\WebSearchTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\Support\FakeWebSearchEngine;

final class WebSearchTransportTest extends TestCase
{
    public function test_queues_all_searches_before_reading_and_preserves_selection_order(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;
            $body = (function () use (&$requests, $url) {
                self::assertCount(2, $requests, 'Both requests must be queued before a body is consumed.');
                if (str_contains($url, 'slow')) {
                    usleep(2_000);
                }
                yield $url;
            })();

            return new MockResponse($body, ['http_code' => 200]);
        });
        $engines = [
            $this->resultEngine('slow', 100),
            $this->resultEngine('fast', 200),
        ];

        $batch = (new WebSearchTransport($client))->search($engines, 'test', $this->context());

        $this->assertFalse($batch->aborted);
        $this->assertSame(['slow', 'fast'], array_map(
            static fn ($outcome): string => $outcome->engine->id(),
            $batch->outcomes,
        ));
        $this->assertSame(
            ['success_with_results', 'success_with_results'],
            array_map(static fn ($outcome): string => $outcome->stat->status, $batch->outcomes),
        );
    }

    public function test_one_transport_failure_does_not_discard_a_completed_peer(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'broken')) {
                return new MockResponse((function () {
                    throw new TransportException('secret network message');
                    yield '';
                })(), ['http_code' => 200]);
            }

            return new MockResponse($url, ['http_code' => 200]);
        });

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('working'),
            $this->resultEngine('broken'),
        ], 'test', $this->context());

        $this->assertSame('success_with_results', $batch->outcomes[0]->stat->status);
        $this->assertSame('transport_error', $batch->outcomes[1]->stat->status);
        $this->assertSame('network_error', $batch->outcomes[1]->stat->error);
        $this->assertCount(1, $batch->outcomes[0]->results);
    }

    public function test_parser_exception_is_contained_as_a_safe_parse_error(): void
    {
        $engine = new FakeWebSearchEngine(
            'broken-parser',
            parser: static function (): never {
                throw new \RuntimeException('secret parser detail');
            },
        );

        $batch = (new WebSearchTransport(new MockHttpClient(
            new MockResponse('body', ['http_code' => 200]),
        )))->search([$engine], 'test', $this->context());

        $this->assertSame('parse_error', $batch->outcomes[0]->stat->status);
        $this->assertSame('unexpected_markup', $batch->outcomes[0]->stat->error);
        $this->assertSame([], $batch->outcomes[0]->results);
    }

    public function test_polling_continues_after_an_idle_stream_tick(): void
    {
        $client = new class(new MockResponse('body', ['http_code' => 200])) extends MockHttpClient {
            public int $streamCalls = 0;

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null,
            ): ResponseStreamInterface {
                $this->streamCalls++;
                if ($this->streamCalls > 1) {
                    return parent::stream($responses, $timeout);
                }

                $responses = $responses instanceof ResponseInterface ? [$responses] : $responses;
                $generator = (static function () use ($responses): \Generator {
                    foreach ($responses as $response) {
                        yield $response => new ErrorChunk(0, 'Idle stream poll');
                    }
                })();

                return new ResponseStream($generator);
            }
        };

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('polled'),
        ], 'test', $this->context());

        $this->assertGreaterThanOrEqual(2, $client->streamCalls);
        $this->assertSame('success_with_results', $batch->outcomes[0]->stat->status);
        $this->assertSame('body', $batch->outcomes[0]->results[0]->snippet);
    }

    public function test_response_limit_is_independent_per_engine(): void
    {
        $client = new MockHttpClient([
            new MockResponse('12345', ['http_code' => 200]),
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        $batch = (new WebSearchTransport($client, maxResponseBytes: 4))->search([
            $this->resultEngine('large'),
            $this->resultEngine('small'),
        ], 'test', $this->context());

        $this->assertSame('response_too_large', $batch->outcomes[0]->stat->error);
        $this->assertSame('success_with_results', $batch->outcomes[1]->stat->status);
    }

    public function test_non_decoding_transport_rejects_compressed_body_before_buffering(): void
    {
        $capturedHeaders = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['normalized_headers'];

            return new MockResponse(gzencode('large body'), [
                'http_code' => 200,
                'response_headers' => ['content-encoding' => 'gzip'],
            ]);
        });

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('gzip'),
        ], 'test', $this->context());

        $this->assertStringContainsString('identity', implode(' ', $capturedHeaders['accept-encoding']));
        $this->assertSame('transport_error', $batch->outcomes[0]->stat->status);
        $this->assertSame('invalid_content_encoding', $batch->outcomes[0]->stat->error);
    }

    public function test_http_error_records_status_without_retaining_response_body(): void
    {
        $client = new MockHttpClient([
            new MockResponse('SECRET_BODY', ['http_code' => 503]),
        ]);

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('down'),
        ], 'test', $this->context());

        $this->assertSame('http_error', $batch->outcomes[0]->stat->status);
        $this->assertSame(503, $batch->outcomes[0]->stat->httpStatus);
        $this->assertSame('http_status', $batch->outcomes[0]->stat->error);
        $this->assertSame([], $batch->outcomes[0]->results);
    }

    public function test_per_engine_deadline_cancels_only_the_slow_engine(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'slow')) {
                return new MockResponse((function () use ($url) {
                    usleep(5_000);
                    yield $url;
                })(), ['http_code' => 200]);
            }

            return new MockResponse($url, ['http_code' => 200]);
        });

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('slow', timeoutMs: 1),
            $this->resultEngine('fast', timeoutMs: 100),
        ], 'test', $this->context());

        $this->assertSame('timeout', $batch->outcomes[0]->stat->error);
        $this->assertSame('success_with_results', $batch->outcomes[1]->stat->status);
    }

    public function test_overall_deadline_classifies_every_still_pending_engine(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return new MockResponse((function () use ($url) {
                usleep(5_000);
                yield $url;
            })(), ['http_code' => 200]);
        });

        $batch = (new WebSearchTransport($client, overallTimeoutMs: 1))->search([
            $this->resultEngine('one', timeoutMs: 100),
            $this->resultEngine('two', timeoutMs: 100),
        ], 'test', $this->context());

        $this->assertSame(
            ['overall_timeout', 'overall_timeout'],
            array_map(static fn ($outcome): ?string => $outcome->stat->error, $batch->outcomes),
        );
    }

    public function test_overall_deadline_wins_after_consuming_a_transport_error_chunk(): void
    {
        $client = new class(new MockResponse('', ['http_code' => 200])) extends MockHttpClient {
            public int $streamCalls = 0;

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null,
            ): ResponseStreamInterface {
                $this->streamCalls++;
                $responses = $responses instanceof ResponseInterface ? [$responses] : $responses;
                $generator = (static function () use ($responses): \Generator {
                    usleep(2_000);
                    foreach ($responses as $response) {
                        yield $response => new ErrorChunk(
                            0,
                            new TransportException('late transport failure'),
                        );
                    }
                })();

                return new ResponseStream($generator);
            }
        };

        $batch = (new WebSearchTransport($client, overallTimeoutMs: 1))->search([
            $this->resultEngine('late-error', timeoutMs: 100),
        ], 'test', $this->context());

        $this->assertSame(1, $client->streamCalls);
        $this->assertSame('overall_timeout', $batch->outcomes[0]->stat->error);
    }

    public function test_host_abort_cancels_the_batch_instead_of_becoming_engine_failure(): void
    {
        $checks = 0;
        $context = new ToolUseContext(
            sys_get_temp_dir(),
            'test',
            shouldAbort: static function () use (&$checks): bool {
                $checks++;

                return $checks >= 4;
            },
        );
        $client = new MockHttpClient([
            new MockResponse('one', ['http_code' => 200]),
            new MockResponse('two', ['http_code' => 200]),
        ]);

        $batch = (new WebSearchTransport($client))->search([
            $this->resultEngine('one'),
            $this->resultEngine('two'),
        ], 'test', $context);

        $this->assertTrue($batch->aborted);
        $this->assertSame([], $batch->outcomes);
    }

    public function test_warmup_is_attempted_once_per_client_and_cookie_stays_on_same_origin(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$url, $options['normalized_headers']];
            if ((parse_url($url, PHP_URL_PATH) ?: '/') === '/') {
                return new MockResponse('', [
                    'http_code' => 204,
                    'response_headers' => ['set-cookie' => ['session=abc; Path=/; Secure', 'bad cookie']],
                ]);
            }

            return new MockResponse('empty', ['http_code' => 200]);
        });
        $engine = new FakeWebSearchEngine(
            'cookie',
            warmup: 'https://cookie.example/',
            requestUrl: 'https://cookie.example/search?q=test',
        );
        $transport = new WebSearchTransport($client);

        $transport->search([$engine], 'test', $this->context());
        $transport->search([$engine], 'test', $this->context());

        $this->assertCount(3, $requests);
        $this->assertArrayNotHasKey('cookie', $requests[0][1]);
        $this->assertStringContainsString('session=abc', implode(' ', $requests[1][1]['cookie']));
        $this->assertStringContainsString('session=abc', implode(' ', $requests[2][1]['cookie']));
    }

    public function test_warmup_transport_timeout_never_suppresses_the_search(): void
    {
        $client = new class([
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('search body', ['http_code' => 200]),
        ]) extends MockHttpClient {
            private int $streamCalls = 0;

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null,
            ): ResponseStreamInterface {
                $this->streamCalls++;
                if ($this->streamCalls > 1) {
                    return parent::stream($responses, $timeout);
                }

                $responses = $responses instanceof ResponseInterface ? [$responses] : $responses;
                $generator = (static function () use ($responses): \Generator {
                    usleep(2_000);
                    foreach ($responses as $response) {
                        yield $response => new ErrorChunk(
                            0,
                            new TransportException('warmup timeout'),
                        );
                    }
                })();

                return new ResponseStream($generator);
            }
        };
        $engine = new FakeWebSearchEngine(
            'warmup-timeout',
            warmup: 'https://warmup.example/',
            requestUrl: 'https://warmup.example/search?q=test',
            parser: static function (EngineHttpResponse $response): EngineParseResult {
                $result = RawSearchResult::from(
                    'Result',
                    'https://result.example/',
                    $response->body,
                );

                return EngineParseResult::success([$result]);
            },
        );

        $batch = (new WebSearchTransport(
            $client,
            overallTimeoutMs: 100,
            warmupTimeoutMs: 1,
        ))->search([$engine], 'test', $this->context());

        $this->assertFalse($batch->aborted);
        $this->assertSame('success_with_results', $batch->outcomes[0]->stat->status);
        $this->assertSame('search body', $batch->outcomes[0]->results[0]->snippet);
    }

    public function test_search_requests_use_owned_deadlines_redirect_limit_and_browser_headers(): void
    {
        $optionsSeen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$optionsSeen): MockResponse {
            $optionsSeen = $options;

            return new MockResponse('empty', ['http_code' => 200]);
        });

        (new WebSearchTransport($client))->search([
            new FakeWebSearchEngine('options', engineTimeoutMs: 4321),
        ], 'test', $this->context());

        $this->assertSame(3, $optionsSeen['max_redirects']);
        $this->assertTrue($optionsSeen['verify_peer']);
        $this->assertTrue($optionsSeen['verify_host']);
        $this->assertLessThanOrEqual(4.321, $optionsSeen['timeout']);
        $this->assertGreaterThan(4.0, $optionsSeen['timeout']);
        $this->assertSame($optionsSeen['timeout'], $optionsSeen['max_duration']);
        foreach (['user-agent', 'accept', 'accept-language', 'accept-encoding', 'upgrade-insecure-requests'] as $header) {
            $this->assertArrayHasKey($header, $optionsSeen['normalized_headers']);
        }
    }

    private function resultEngine(
        string $id,
        int $priority = 100,
        int $timeoutMs = 5000,
    ): FakeWebSearchEngine {
        return new FakeWebSearchEngine(
            $id,
            priority: $priority,
            engineTimeoutMs: $timeoutMs,
            parser: static function (EngineHttpResponse $response) use ($id): EngineParseResult {
                $result = RawSearchResult::from(
                    strtoupper($id),
                    'https://results.example/'.$id,
                    $response->body,
                );

                return $result === null
                    ? EngineParseResult::error()
                    : EngineParseResult::success([$result]);
            },
        );
    }

    private function context(): ToolUseContext
    {
        return new ToolUseContext(sys_get_temp_dir(), 'test');
    }
}
