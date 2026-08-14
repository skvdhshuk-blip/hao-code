<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiProviderBuildRequestHeadersConcern
{

    /**
     * Build the request headers for one /v1/responses call: the hardcoded
     * auth/content defaults merged with caller-supplied custom headers.
     * Custom values win same-name (case-insensitive) except `Authorization`,
     * which always stays under the auth logic.
     *
     * @return array<string, string>
     */
    private function buildRequestHeaders(): array
    {
        return RequestHeaders::mergeCustom([
            'authorization' => 'Bearer ' . $this->resolveApiKey(),
            'content-type' => 'application/json',
            'accept' => 'text/event-stream',
        ], $this->resolveCustomHeaders());
    }

    private function resolveApiKey(): string
    {
        $apiKey = $this->settingsManager?->getApiKey() ?: $this->apiKey;
        if (trim($apiKey) === '') {
            throw new \RuntimeException(
                'API key is required for provider type "openai" before provider request.',
            );
        }

        return $apiKey;
    }

    private function resolveBaseUrl(): string
    {
        return $this->settingsManager?->getBaseUrl() ?: $this->baseUrl;
    }

    private function resolveMaxTokens(): int
    {
        if ($this->settingsManager) {
            return (int) ($this->settingsManager->getMaxTokens() ?? $this->maxTokens);
        }

        return $this->maxTokens;
    }

    private function resolveThinkingEnabled(): bool
    {
        return $this->settingsManager?->isThinkingEnabled() ?? $this->thinkingEnabled;
    }

    private function resolveThinkingBudget(): int
    {
        return $this->settingsManager?->getThinkingBudget() ?? $this->thinkingBudget;
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiErrorException(
                'Failed to encode OpenAI request payload: ' . $e->getMessage(),
                'request_encoding_error',
                previous: $e,
            );
        }
    }

    private function throwForHttpError(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 400) {
            return;
        }

        $body = trim(BoundedResponseBodyReader::read(
            $this->httpClient,
            $response,
            self::MAX_ERROR_BODY_BYTES,
        ));
        $url = EndpointRedactor::origin((string) $response->getInfo('url'));
        $message = $body !== '' ? $body : "HTTP {$statusCode} returned for \"{$url}\".";
        $errorType = 'http_error';

        $decoded = json_decode($body, true);
        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            if (is_string($decoded['error']['message'] ?? null)) {
                $message = $decoded['error']['message'];
            }
            if (is_string($decoded['error']['type'] ?? null)) {
                $errorType = $decoded['error']['type'];
            } elseif (is_string($decoded['error']['code'] ?? null)) {
                $errorType = $decoded['error']['code'];
            }
        }

        throw new ApiErrorException($message, $errorType, $statusCode);
    }

    private function shouldRetry(\Throwable $e, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($e instanceof ApiErrorException) {
            return in_array($e->getErrorType(), [
                'rate_limit_exceeded',
                'rate_limit_error',
                'server_error',
                'api_error',
                'stream_timeout',
            ]);
        }

        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
            return true;
        }
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return true;
        }

        return false;
    }

    private function getRetryDelay(int $attempt, \Throwable $e): float
    {
        $retryAfter = $this->lastRateLimitHeaders['retry-after'] ?? null;
        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            return max(0.0, min((float) $retryAfter, 120));
        }

        return RetryDelay::withJitter(min(2 ** $attempt, 10), 10);
    }

    private function extractRateLimitHeaders(ResponseInterface $response): void
    {
        $headers = $response->getHeaders(false);
        $this->lastRateLimitHeaders = [];

        $prefixes = [
            'x-ratelimit-',
            'retry-after',
        ];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($lower, $prefix) || $lower === 'retry-after') {
                    $this->lastRateLimitHeaders[$lower] = $values[0] ?? '';
                    break;
                }
            }
        }
    }

    private function normalizeTransportException(\Throwable $e): \Throwable
    {
        if ($e instanceof ApiErrorException) {
            return $e;
        }

        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return new ApiErrorException(
                'Network transport error while streaming OpenAI response: ' . $e->getMessage(),
                'transport_error',
                previous: $e,
            );
        }

        return $e;
    }
}
