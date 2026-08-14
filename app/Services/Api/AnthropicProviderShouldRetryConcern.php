<?php

namespace HaoCode\Services\Api;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Services\Settings\ModelCatalog;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait AnthropicProviderShouldRetryConcern
{

    private function shouldRetry(\Throwable $e, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($e instanceof ApiErrorException) {
            return in_array($e->getErrorType(), [
                'overloaded_error',
                'rate_limit_error',
                'api_error',
                'stream_timeout',
            ], true) || $this->isRateLimitMessage($e->getMessage());
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

        if ($e instanceof ApiErrorException
            && ($e->getErrorType() === 'rate_limit_error' || $this->isRateLimitMessage($e->getMessage()))) {
            return RetryDelay::withJitter(min(2 ** $attempt, 30), 30);
        }

        return RetryDelay::withJitter(min(2 ** $attempt, 10), 10);
    }

    private function isRateLimitMessage(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many requests')
            || str_contains($normalized, '[1302]');
    }

    private function normalizeToolsForProvider(array $tools, string $baseUrl): array
    {
        if (! $this->isZaiAnthropicEndpoint($baseUrl)) {
            return $tools;
        }

        // Z.ai's Anthropic-compatible endpoint currently returns an empty stream
        // when WebFetch is advertised in the tool list, even if the model never
        // selects it. Exclude that single tool so the rest of the coding stack
        // stays usable on glm-* models.
        return array_values(array_filter(
            $tools,
            fn (array $tool): bool => ($tool['name'] ?? null) !== 'WebFetch',
        ));
    }

    private function isZaiAnthropicEndpoint(string $baseUrl): bool
    {
        return str_contains(strtolower(trim($baseUrl)), 'api.z.ai/api/anthropic');
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiErrorException(
                'Failed to encode request payload: ' . $e->getMessage(),
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
        if (is_array($decoded)) {
            if (is_array($decoded['error'] ?? null)) {
                $errorType = is_string($decoded['error']['type'] ?? null)
                    ? $decoded['error']['type']
                    : $errorType;
                $message = is_string($decoded['error']['message'] ?? null)
                    ? $decoded['error']['message']
                    : $message;
            } elseif (is_string($decoded['message'] ?? null)) {
                $message = $decoded['message'];
            } elseif (is_string($decoded['error'] ?? null)) {
                $message = $decoded['error'];
            }
        }

        throw new ApiErrorException($message, $errorType, $statusCode);
    }

    private function preferredHttpVersion(?string $baseUrl = null): ?string
    {
        return '1.1';
    }

    private function normalizeTransportException(\Throwable $e): \Throwable
    {
        if ($e instanceof ApiErrorException) {
            return $e;
        }

        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return new ApiErrorException(
                'Network transport error while streaming response: ' . $e->getMessage(),
                'transport_error',
                previous: $e,
            );
        }

        return $e;
    }

    private function extractRateLimitHeaders(ResponseInterface $response): void
    {
        $headers = $response->getHeaders(false);
        $this->lastRateLimitHeaders = [];

        $prefixes = [
            'anthropic-ratelimit-',
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

    public function getLastRateLimitHeaders(): array
    {
        return $this->lastRateLimitHeaders;
    }

    /**
     * Clone this provider while rebinding its runtime settings.
     *
     * The transport is intentionally retained so SDK test doubles and custom
     * HTTP clients continue to work for isolated agent runs.
     */
    public function withSettingsManager(\HaoCode\Services\Settings\SettingsManager $settingsManager): self
    {
        $provider = clone $this;
        $provider->settingsManager = $settingsManager;

        return $provider;
    }

    public function withApiKey(string $apiKey): self
    {
        $provider = clone $this;
        $provider->apiKey = $apiKey;
        $provider->model = $this->resolveModel();
        $provider->baseUrl = $this->resolveBaseUrl();
        $provider->maxTokens = $this->resolveMaxTokens();
        $provider->thinkingEnabled = $this->resolveThinkingEnabled();
        $provider->thinkingBudget = $this->resolveThinkingBudget();
        $provider->oauthBearer = $this->resolveOauthBearer();
        $provider->settingsManager = null;

        return $provider;
    }

    private function cancelResponse(ResponseInterface $response): void
    {
        $response->cancel();
    }

    private function formatIdleTimeoutMessage(): string
    {
        $seconds = max(1, $this->idleTimeoutSeconds);

        return "Streaming response stalled for more than {$seconds}s without new data. Retry the turn.";
    }
}
