<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiChatProviderTranslateToolsConcern
{

    private function translateTools(array $tools): array
    {
        $translated = [];
        foreach ($tools as $tool) {
            $schema = $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if (is_array($schema) && ! isset($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }
            $translated[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) ($tool['name'] ?? ''),
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => $schema,
                ],
            ];
        }

        return $translated;
    }

    private function extractSystemText(array $systemPrompt): string
    {
        $parts = [];
        foreach ($systemPrompt as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function stringifyToolResultContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif (($block['type'] ?? '') === 'image') {
                $parts[] = '[image omitted]';
            }
        }

        return implode("\n", $parts);
    }

    private function imageBlockToDataUri(array $block): ?string
    {
        $source = $block['source'] ?? [];
        if (! is_array($source)) {
            return null;
        }
        if (($source['type'] ?? '') === 'url' && is_string($source['url'] ?? null)) {
            return $source['url'];
        }
        if (($source['type'] ?? '') === 'base64'
            && is_string($source['media_type'] ?? null)
            && is_string($source['data'] ?? null)) {
            return 'data:' . $source['media_type'] . ';base64,' . $source['data'];
        }

        return null;
    }

    private function mapUsage(array $usage): array
    {
        if ($usage === []) {
            return [];
        }

        $contextInput = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cacheRead = (int) ($usage['prompt_cache_hit_tokens']
            ?? $usage['prompt_tokens_details']['cached_tokens']
            ?? 0);
        $cacheMiss = (int) ($usage['prompt_cache_miss_tokens'] ?? 0);
        $input = $cacheMiss > 0
            ? $cacheMiss
            : max(0, $contextInput - $cacheRead);
        $reasoning = (int) ($usage['completion_tokens_details']['reasoning_tokens']
            ?? $usage['reasoning_tokens']
            ?? 0);

        $mapped = [
            'input_tokens' => $input,
            'output_tokens' => $output,
        ];
        if ($reasoning > 0) {
            $mapped['thinking_tokens'] = $reasoning;
        }
        if ($cacheRead > 0) {
            $mapped['context_input_tokens'] = $contextInput;
            $mapped['cache_read_input_tokens'] = $cacheRead;
        }

        return $mapped;
    }

    private function resolveModel(): string
    {
        return $this->settingsManager?->getModel() ?: $this->model;
    }

    private function resolveApiKey(): string
    {
        $apiKey = $this->settingsManager?->getApiKey() ?: $this->apiKey;
        if (trim($apiKey) === '') {
            throw new \RuntimeException(
                'API key is required for provider type "openai_chat" before provider request.',
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
                'Failed to encode chat.completions request payload: ' . $e->getMessage(),
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

        $this->lastRateLimitHeaders = $this->filterRateLimitHeaders($headers);
    }

    /**
     * Parse the response-header lines exposed by PHP's native stream wrapper.
     *
     * @param mixed $wrapperData
     * @return array<string, string>
     */
    private function extractRateLimitHeadersFromWrapperData(mixed $wrapperData): array
    {
        if (! is_array($wrapperData)) {
            return $this->lastRateLimitHeaders;
        }

        $headers = [];
        foreach ($wrapperData as $line) {
            if (! is_string($line)) {
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            if (! $this->isRateLimitHeader($name)) {
                continue;
            }

            $headers[$name] = trim(substr($line, $separator + 1));
        }

        $this->lastRateLimitHeaders = $headers;

        return $headers;
    }

    /**
     * @param mixed $wrapperData
     */
    private function extractStatusCodeFromWrapperData(mixed $wrapperData): int
    {
        if (! is_array($wrapperData)) {
            return 0;
        }

        $statusCode = 0;
        foreach ($wrapperData as $line) {
            if (is_string($line)
                && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d+)#i', trim($line), $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private function filterRateLimitHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if ($this->isRateLimitHeader($lower)) {
                $filtered[$lower] = $values[0] ?? '';
            }
        }

        return $filtered;
    }

    private function isRateLimitHeader(string $lowerName): bool
    {
        return str_starts_with($lowerName, 'x-ratelimit-') || $lowerName === 'retry-after';
    }

    private function normalizeTransportException(\Throwable $e): \Throwable
    {
        if ($e instanceof ApiErrorException) {
            return $e;
        }
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return new ApiErrorException(
                'Network transport error while streaming chat.completions response: ' . $e->getMessage(),
                'transport_error',
                previous: $e,
            );
        }

        return $e;
    }
}
