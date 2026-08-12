<?php

namespace HaoCode\Services\Api;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Services\Settings\ModelCatalog;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Anthropic Messages API streaming provider.
 *
 * This is the original StreamingClient implementation; kept verbatim in
 * behaviour (retries, rate-limit header capture, Z.ai WebFetch workaround,
 * prompt-caching breakpoints, abort handling, idle timeout). Extracted
 * behind {@see LlmProvider} so a second wire format (OpenAI Responses) can
 * coexist without touching the call sites.
 */
class AnthropicProvider implements ApiKeyAwareProvider, SettingsAwareProvider
{
    private const MAX_SSE_LINE_BYTES = 4 * 1024 * 1024;
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;

    private HttpClientInterface $httpClient;
    private int $maxRetries = 3;
    private array $lastRateLimitHeaders = [];
    /** @var array<string, string> */
    private array $headers;
    /** @var callable(): float */
    private $timeProvider;

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com',
        private int $maxTokens = 16384,
        private readonly string $apiVersion = '2023-06-01',
        private bool $thinkingEnabled = false,
        private int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        private ?\HaoCode\Services\Settings\SettingsManager $settingsManager = null,
        private readonly int $idleTimeoutSeconds = 60,
        private readonly float $streamPollTimeoutSeconds = 1.0,
        ?callable $timeProvider = null,
        private bool $oauthBearer = false,
        array $headers = [],
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
        ]);
        $this->timeProvider = $timeProvider ?? static fn (): float => microtime(true);
        $this->headers = RequestHeaders::sanitize($headers);
    }

    private function resolveModel(): string
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getModel() ?? $this->model;
        }

        return $this->model;
    }

    private function resolveMaxTokens(): int
    {
        if ($this->settingsManager) {
            return (int) ($this->settingsManager->getMaxTokens() ?? $this->maxTokens);
        }

        return $this->maxTokens;
    }

    private function resolveApiKey(): string
    {
        $apiKey = $this->settingsManager
            ? ($this->settingsManager->getApiKey() ?: $this->apiKey)
            : $this->apiKey;
        if (trim($apiKey) === '') {
            throw new \RuntimeException(
                'API key is required for provider type "anthropic" before provider request.',
            );
        }

        return $apiKey;
    }

    private function resolveThinkingEnabled(): bool
    {
        if ($this->settingsManager) {
            return $this->settingsManager->isThinkingEnabled();
        }

        return $this->thinkingEnabled;
    }

    private function resolveThinkingBudget(): int
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getThinkingBudget();
        }

        return $this->thinkingBudget;
    }

    /**
     * Map thinkingBudget / effort_level into adaptive effort tiers.
     *
     * @return 'low'|'medium'|'high'|'max'
     */
    private function resolveAdaptiveEffort(): string
    {
        $explicit = $this->settingsManager?->getEffortLevel();
        if (is_string($explicit) && in_array($explicit, ['low', 'medium', 'high', 'max'], true)) {
            return $explicit;
        }

        $budget = $this->resolveThinkingBudget();
        if ($budget >= 32000) {
            return 'max';
        }
        if ($budget >= 16000) {
            return 'high';
        }
        if ($budget >= 8000) {
            return 'medium';
        }

        return 'low';
    }

    private function resolveBaseUrl(): string
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getBaseUrl() ?: $this->baseUrl;
        }

        return $this->baseUrl;
    }

    private function resolveOauthBearer(): bool
    {
        if ($this->settingsManager) {
            return $this->settingsManager->isOauthBearer();
        }

        return $this->oauthBearer;
    }

    /**
     * Custom request headers for this run. A run-scoped SettingsManager
     * (runtime overrides) wins; otherwise the constructor-provided map is
     * used. Empty when neither source defines any.
     *
     * @return array<string, string>
     */
    private function resolveCustomHeaders(): array
    {
        return $this->settingsManager?->getHeaders() ?: $this->headers;
    }

    /**
     * Build the request headers for one /v1/messages call.
     *
     * OAuth access tokens (Claude Pro/Max subscriptions) must be sent as
     * `Authorization: Bearer <token>` with the `oauth-2025-04-20` beta flag;
     * plain API keys keep the `x-api-key` header. The beta flag is merged
     * with the prompt-caching flag rather than replacing it.
     */
    private function buildRequestHeaders(): array
    {
        $betaFeatures = ['prompt-caching-2024-07-31'];
        $headers = [];

        if ($this->resolveOauthBearer()) {
            $headers['Authorization'] = 'Bearer ' . $this->resolveApiKey();
            $betaFeatures[] = 'oauth-2025-04-20';
        } else {
            $headers['x-api-key'] = $this->resolveApiKey();
        }

        $headers['anthropic-version'] = $this->apiVersion;
        $headers['anthropic-beta'] = implode(',', $betaFeatures);
        $headers['content-type'] = 'application/json';
        $headers['accept'] = 'text/event-stream';

        // Caller-supplied headers (e.g. Copilot gateway requirements) win
        // over the hardcoded defaults, but never over the auth headers.
        return RequestHeaders::mergeCustom($headers, $this->resolveCustomHeaders());
    }

    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator {
        $attempt = 0;

        while (true) {
            if ($shouldAbort && $shouldAbort()) {
                return;
            }

            $hasCommittedResponseState = false;

            try {
                foreach ($this->doStreamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort) as $event) {
                    $hasCommittedResponseState = $hasCommittedResponseState || $event->commitsResponseState();
                    yield $event;
                }
                return;
            } catch (\Throwable $e) {
                if ($shouldAbort && $shouldAbort()) {
                    return;
                }

                if ($hasCommittedResponseState) {
                    throw $this->normalizeTransportException($e);
                }

                $attempt++;

                if (!$this->shouldRetry($e, $attempt)) {
                    throw $this->normalizeTransportException($e);
                }

                $delay = $this->getRetryDelay($attempt, $e);
                usleep((int) ($delay * 1000000));
            }
        }
    }

    private function doStreamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent,
        ?callable $shouldAbort,
    ): \Generator {
        $baseUrl = $this->resolveBaseUrl();
        $payload = [
            'model' => $this->resolveModel(),
            'max_tokens' => $this->resolveMaxTokens(),
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => true,
        ];

        $thinkingEnabled = $this->resolveThinkingEnabled();
        if ($thinkingEnabled) {
            if (ModelCatalog::requiresAdaptiveThinking($this->resolveModel())) {
                $payload['thinking'] = ['type' => 'adaptive'];
                $payload['output_config'] = [
                    'effort' => $this->resolveAdaptiveEffort(),
                ];
            } else {
                $thinkingBudget = $this->resolveThinkingBudget();
                $payload['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $thinkingBudget,
                ];
                $payload['max_tokens'] = max($this->resolveMaxTokens(), $thinkingBudget + 4096);
            }
        }

        $tools = $this->normalizeToolsForProvider($tools, $baseUrl);

        if (count($tools) > 0) {
            $toolsWithCache = $tools;
            $lastIdx = count($toolsWithCache) - 1;
            $toolsWithCache[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
            $payload['tools'] = $toolsWithCache;
        }

        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/') . '/v1/messages', [
            'headers' => $this->buildRequestHeaders(),
            'body' => $this->encodePayload($payload),
            'buffer' => false,
            'http_version' => $this->preferredHttpVersion($baseUrl),
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        if ($shouldAbort && $shouldAbort()) {
            $this->cancelResponse($response);

            return;
        }

        $this->throwForHttpError($response);
        $this->extractRateLimitHeaders($response);

        $currentEvent = null;
        $currentDataLines = [];
        $currentDataBytes = 0;
        $lineReader = new BoundedSseLineBuffer(self::MAX_SSE_LINE_BYTES);
        $lastActivityAt = ($this->timeProvider)();

        try {
            foreach ($this->httpClient->stream($response, $this->streamPollTimeoutSeconds) as $chunk) {
                if ($shouldAbort && $shouldAbort()) {
                    $this->cancelResponse($response);

                    return;
                }

                if ($chunk->isTimeout()) {
                    if (($this->timeProvider)() - $lastActivityAt >= $this->idleTimeoutSeconds) {
                        $this->cancelResponse($response);

                        throw new ApiErrorException(
                            $this->formatIdleTimeoutMessage(),
                            'stream_timeout',
                        );
                    }

                    continue;
                }

                $lastActivityAt = ($this->timeProvider)();

                foreach ($lineReader->push($chunk->getContent()) as $line) {
                    $events = $this->processSseLine(
                        rtrim($line, "\r"),
                        $currentEvent,
                        $currentDataLines,
                        $currentDataBytes,
                        $onRawEvent,
                    );

                    foreach ($events as $event) {
                        if ($shouldAbort && $shouldAbort()) {
                            $this->cancelResponse($response);

                            return;
                        }

                        yield $event;
                    }
                }
            }
        } catch (\LengthException $e) {
            $this->cancelResponse($response);

            throw new ApiErrorException(
                'Streaming SSE line exceeded the configured size limit.',
                'protocol_error',
                previous: $e,
            );
        } catch (\Throwable $e) {
            if ($shouldAbort && $shouldAbort()) {
                $this->cancelResponse($response);

                return;
            }

            throw $e;
        }

        if ($shouldAbort && $shouldAbort()) {
            $this->cancelResponse($response);

            return;
        }

        foreach ($lineReader->push('', true) as $line) {
            $events = $this->processSseLine(
                rtrim($line, "\r"),
                $currentEvent,
                $currentDataLines,
                $currentDataBytes,
                $onRawEvent,
            );

            foreach ($events as $event) {
                if ($shouldAbort && $shouldAbort()) {
                    $this->cancelResponse($response);

                    return;
                }

                yield $event;
            }
        }

        $event = $this->emitCurrentEvent($currentEvent, $currentDataLines, $currentDataBytes, $onRawEvent);
        if ($event !== null) {
            if ($shouldAbort && $shouldAbort()) {
                $this->cancelResponse($response);

                return;
            }

            yield $event;
        }
    }

    /**
     * @param array<int, string> $currentDataLines
     */
    private function processSseLine(
        string $line,
        ?string &$currentEvent,
        array &$currentDataLines,
        int &$currentDataBytes,
        ?callable $onRawEvent,
    ): array {
        $events = [];

        if (str_starts_with($line, 'event:')) {
            $pendingEvent = $this->emitCurrentEvent($currentEvent, $currentDataLines, $currentDataBytes, $onRawEvent);
            if ($pendingEvent !== null) {
                $events[] = $pendingEvent;
            }

            $currentEvent = trim(substr($line, 6));
            return $events;
        }

        if (str_starts_with($line, 'data:')) {
            $dataLine = substr($line, 5);
            if (str_starts_with($dataLine, ' ')) {
                $dataLine = substr($dataLine, 1);
            }
            $nextBytes = $currentDataBytes + strlen($dataLine) + ($currentDataLines === [] ? 0 : 1);
            if ($nextBytes > self::MAX_SSE_LINE_BYTES) {
                throw new \LengthException(
                    "SSE event exceeded ".self::MAX_SSE_LINE_BYTES.' bytes',
                );
            }
            $currentDataLines[] = $dataLine;
            $currentDataBytes = $nextBytes;

            return $events;
        }

        if ($line === '') {
            $event = $this->emitCurrentEvent($currentEvent, $currentDataLines, $currentDataBytes, $onRawEvent);
            if ($event !== null) {
                $events[] = $event;
            }

            return $events;
        }

        return $events;
    }

    /**
     * @param array<int, string> $currentDataLines
     */
    private function emitCurrentEvent(
        ?string &$currentEvent,
        array &$currentDataLines,
        int &$currentDataBytes,
        ?callable $onRawEvent,
    ): ?StreamEvent {
        if ($currentEvent === null || $currentDataLines === []) {
            $currentEvent = null;
            $currentDataLines = [];
            $currentDataBytes = 0;

            return null;
        }

        $event = new StreamEvent(
            $currentEvent,
            StreamEvent::decodeSseData(implode("\n", $currentDataLines), 'Anthropic'),
        );

        if ($currentEvent === 'error') {
            $errorMsg = $event->data['error']['message'] ?? 'Unknown API error';
            $errorType = $event->data['error']['type'] ?? 'unknown';
            throw new ApiErrorException($errorMsg, $errorType);
        }

        if ($onRawEvent) {
            $onRawEvent($event);
        }

        $currentEvent = null;
        $currentDataLines = [];
        $currentDataBytes = 0;

        return $event;
    }

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
