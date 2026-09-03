<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineHttpResponse;
use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\EngineParseResult;
use HaoCode\Tools\WebSearch\Engine\EngineRequest;
use HaoCode\Tools\WebSearch\Engine\RawSearchResult;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @internal */
final class WebSearchTransport
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36';

    private readonly WebSearchWarmup $warmup;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly int $overallTimeoutMs = 10000,
        private readonly int $warmupTimeoutMs = 3000,
        private readonly int $maxResponseBytes = 2_097_152,
    ) {
        if ($overallTimeoutMs < 1 || $warmupTimeoutMs < 1 || $maxResponseBytes < 1) {
            throw new \InvalidArgumentException('WebSearch transport limits must be positive.');
        }
        $this->warmup = new WebSearchWarmup($client, $warmupTimeoutMs);
    }

    /** @param list<EngineInterface> $engines */
    public function search(array $engines, string $query, ToolUseContext $context): WebSearchBatch
    {
        $overallDeadline = self::now() + ($this->overallTimeoutMs / 1000);
        if (! $this->warmup->run(
            $engines,
            $context,
            $overallDeadline,
            fn (array $headers, float $timeout): array => $this->baseOptions($headers, $timeout),
        )) {
            return WebSearchBatch::aborted();
        }
        if ($context->isAborted()) {
            return WebSearchBatch::aborted();
        }

        /** @var array<string, EngineOutcome> $outcomes */
        $outcomes = [];
        /** @var array<int, EngineResponseState> $states */
        $states = [];

        foreach ($engines as $engine) {
            $startedAt = self::now();
            if ($context->isAborted()) {
                $this->cancelStates($states);

                return WebSearchBatch::aborted();
            }
            if ($startedAt >= $overallDeadline) {
                $outcomes[$engine->id()] = $this->transportFailure(
                    $engine,
                    $startedAt,
                    null,
                    'overall_timeout',
                );
                continue;
            }

            try {
                $request = $engine->createRequest($query);
                $remaining = min(
                    $engine->timeoutMs() / 1000,
                    max(0.001, $overallDeadline - $startedAt),
                );
                $response = $this->client->request(
                    'GET',
                    $request->url,
                    $this->requestOptions($engine, $request, $remaining),
                );
                $states[spl_object_id($response)] = new EngineResponseState(
                    $engine,
                    $response,
                    $startedAt,
                    min($overallDeadline, $startedAt + ($engine->timeoutMs() / 1000)),
                    $request->url,
                );
            } catch (\Throwable) {
                $outcomes[$engine->id()] = $this->transportFailure(
                    $engine,
                    $startedAt,
                    null,
                    'network_error',
                );
            }
        }

        if ($states !== [] && ! $this->collect($states, $outcomes, $context, $overallDeadline)) {
            return WebSearchBatch::aborted();
        }

        $ordered = [];
        foreach ($engines as $engine) {
            $ordered[] = $outcomes[$engine->id()] ?? $this->transportFailure(
                $engine,
                self::now(),
                null,
                'network_error',
            );
        }

        return new WebSearchBatch($ordered);
    }

    /**
     * @param array<int, EngineResponseState> $states
     * @param array<string, EngineOutcome> $outcomes
     */
    private function collect(
        array $states,
        array &$outcomes,
        ToolUseContext $context,
        float $overallDeadline,
    ): bool {
        while ($this->hasPendingState($states)) {
            if ($context->isAborted()) {
                $this->cancelStates($states);

                return false;
            }
            $this->expireStates($states, $outcomes, $overallDeadline);
            $pending = array_filter(
                $states,
                static fn (EngineResponseState $state): bool => ! $state->settled,
            );
            if ($pending === []) {
                break;
            }

            try {
                $responses = array_map(static fn (EngineResponseState $state) => $state->response, $pending);
                foreach ($this->client->stream($responses, $this->pollTimeout($pending, $overallDeadline)) as $response => $chunk) {
                    if ($context->isAborted()) {
                        $this->cancelStates($states);

                        return false;
                    }

                    $this->expireStates($states, $outcomes, $overallDeadline);
                    $id = spl_object_id($response);
                    if (! isset($states[$id]) || $states[$id]->settled) {
                        continue;
                    }

                    $state = $states[$id];
                    try {
                        $this->consumeChunk($state, $chunk, $outcomes);
                    } catch (\Throwable) {
                        $this->settleTransport($state, $outcomes, 'network_error');
                    }
                }
            } catch (\Throwable) {
                foreach ($pending as $state) {
                    $this->settleTransport($state, $outcomes, 'network_error');
                }
            }
        }

        return true;
    }

    /** @param array<int, EngineResponseState> $states */
    private function hasPendingState(array $states): bool
    {
        foreach ($states as $state) {
            if (! $state->settled) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, EngineResponseState> $states */
    private function pollTimeout(array $states, float $overallDeadline): float
    {
        $deadline = $overallDeadline;
        foreach ($states as $state) {
            $deadline = min($deadline, $state->deadline);
        }

        return max(0.001, min(0.05, $deadline - self::now()));
    }

    /** @param array<string, EngineOutcome> $outcomes */
    private function consumeChunk(
        EngineResponseState $state,
        ChunkInterface $chunk,
        array &$outcomes,
    ): void {
        if ($chunk->isTimeout()) {
            return;
        }
        if ($chunk->isFirst()) {
            $state->httpStatus = $state->response->getStatusCode();
            $state->headers = $state->response->getHeaders(false);
            $effectiveUrl = $state->response->getInfo('url');
            if (is_string($effectiveUrl) && $effectiveUrl !== '') {
                $state->effectiveUrl = $effectiveUrl;
            }

            if ($state->httpStatus < 200 || $state->httpStatus >= 300) {
                $state->response->cancel();
                $this->settle($state, $outcomes, EngineStat::HTTP_ERROR, [], 'http_status');

                return;
            }
            $encoding = strtolower($state->headers['content-encoding'][0] ?? '');
            if (! $this->transportDecodesContent() && ! in_array($encoding, ['', 'identity'], true)) {
                $state->response->cancel();
                $this->settleTransport($state, $outcomes, 'invalid_content_encoding');

                return;
            }
        }
        if ($chunk->isLast()) {
            $this->settleParsed($state, $outcomes);

            return;
        }

        $content = $chunk->getContent();
        if (strlen($state->body) + strlen($content) > $this->maxResponseBytes) {
            $state->response->cancel();
            $this->settleTransport($state, $outcomes, 'response_too_large');

            return;
        }
        $state->body .= $content;
    }

    /** @param array<string, EngineOutcome> $outcomes */
    private function settleParsed(EngineResponseState $state, array &$outcomes): void
    {
        try {
            $parsed = $state->engine->parse(new EngineHttpResponse(
                $state->httpStatus ?? 200,
                $state->effectiveUrl,
                $state->headers,
                $state->body,
            ));
        } catch (\Throwable) {
            $this->settle($state, $outcomes, EngineParseResult::PARSE_ERROR, [], 'unexpected_markup');

            return;
        }
        $results = array_values(array_filter(
            $parsed->results,
            static fn (mixed $result): bool => $result instanceof RawSearchResult,
        ));
        if ($parsed->status === EngineParseResult::SUCCESS_WITH_RESULTS && $results === []) {
            $this->settle($state, $outcomes, EngineParseResult::PARSE_ERROR, [], 'unexpected_markup');

            return;
        }

        $this->settle(
            $state,
            $outcomes,
            $parsed->status,
            $results,
            $parsed->error,
        );
    }

    /**
     * @param array<int, EngineResponseState> $states
     * @param array<string, EngineOutcome> $outcomes
     */
    private function expireStates(array $states, array &$outcomes, float $overallDeadline): void
    {
        $now = self::now();
        foreach ($states as $state) {
            if ($state->settled) {
                continue;
            }
            if ($now >= $overallDeadline) {
                $state->response->cancel();
                $this->settleTransport($state, $outcomes, 'overall_timeout');
            } elseif ($now >= $state->deadline) {
                $state->response->cancel();
                $this->settleTransport($state, $outcomes, 'timeout');
            }
        }
    }

    /** @param array<string, EngineOutcome> $outcomes */
    private function settleTransport(
        EngineResponseState $state,
        array &$outcomes,
        string $error,
    ): void {
        $this->settle($state, $outcomes, EngineStat::TRANSPORT_ERROR, [], $error);
    }

    /**
     * @param list<RawSearchResult> $results
     * @param array<string, EngineOutcome> $outcomes
     */
    private function settle(
        EngineResponseState $state,
        array &$outcomes,
        string $status,
        array $results,
        ?string $error,
    ): void {
        if ($state->settled) {
            return;
        }
        $state->settled = true;
        $outcomes[$state->engine->id()] = new EngineOutcome(
            $state->engine,
            $results,
            new EngineStat(
                $state->engine->id(),
                $status,
                min(10, count($results)),
                self::elapsedMs($state->startedAt),
                $state->httpStatus,
                $error,
            ),
        );
    }

    private function transportFailure(
        EngineInterface $engine,
        float $startedAt,
        ?int $httpStatus,
        string $error,
    ): EngineOutcome {
        return new EngineOutcome($engine, [], new EngineStat(
            $engine->id(),
            EngineStat::TRANSPORT_ERROR,
            0,
            self::elapsedMs($startedAt),
            $httpStatus,
            $error,
        ));
    }

    private function requestOptions(EngineInterface $engine, EngineRequest $request, float $timeout): array
    {
        $headers = $request->headers;
        $cookie = $this->warmup->cookieFor($engine, $request->url);
        if ($cookie !== null) {
            $headers['Cookie'] = $cookie;
        }

        return $this->baseOptions($headers, $timeout);
    }

    /** @param array<string, string> $headers */
    private function baseOptions(array $headers, float $timeout): array
    {
        $headers = array_replace([
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            'Accept-Encoding' => 'identity',
            'Upgrade-Insecure-Requests' => '1',
        ], $headers);
        $options = [
            'headers' => $headers,
            'max_redirects' => 3,
            'timeout' => $timeout,
            'max_duration' => $timeout,
        ];
        if ($this->transportDecodesContent() && defined('CURLOPT_ENCODING')) {
            $encoding = $this->curlSupportsBrotli() ? 'gzip, br' : 'gzip';
            $options['headers']['Accept-Encoding'] = $encoding;
            $options['extra']['curl'][(int) constant('CURLOPT_ENCODING')] = $encoding;
        }

        return $options;
    }

    /** @param array<int, EngineResponseState> $states */
    private function cancelStates(array $states): void
    {
        foreach ($states as $state) {
            if (! $state->settled) {
                $state->response->cancel();
            }
        }
    }

    private function transportDecodesContent(): bool
    {
        return $this->client instanceof CurlHttpClient && defined('CURLOPT_ENCODING');
    }

    private function curlSupportsBrotli(): bool
    {
        if (! function_exists('curl_version') || ! defined('CURL_VERSION_BROTLI')) {
            return false;
        }
        $version = curl_version();

        return isset($version['features'])
            && (($version['features'] & (int) constant('CURL_VERSION_BROTLI')) !== 0);
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private static function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((self::now() - $startedAt) * 1000));
    }
}
