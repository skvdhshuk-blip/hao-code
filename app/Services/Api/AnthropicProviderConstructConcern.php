<?php

namespace HaoCode\Services\Api;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Services\Settings\ModelCatalog;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait AnthropicProviderConstructConcern
{

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
}
