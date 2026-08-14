<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiProviderConstructConcern
{

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com',
        private int $maxTokens = 16384,
        private bool $thinkingEnabled = false,
        private int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        private ?\HaoCode\Services\Settings\SettingsManager $settingsManager = null,
        private readonly int $idleTimeoutSeconds = 60,
        private readonly float $streamPollTimeoutSeconds = 1.0,
        ?callable $timeProvider = null,
        array $headers = [],
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
        ]);
        $this->timeProvider = $timeProvider ?? static fn (): float => microtime(true);
        $this->headers = RequestHeaders::sanitize($headers);
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

                if (! $this->shouldRetry($e, $attempt)) {
                    throw $this->normalizeTransportException($e);
                }

                $delay = $this->getRetryDelay($attempt, $e);
                usleep((int) ($delay * 1000000));
            }
        }
    }

    public function getLastRateLimitHeaders(): array
    {
        return $this->lastRateLimitHeaders;
    }

    /**
     * Clone this provider while retaining its configured transport.
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
        $provider->settingsManager = null;

        return $provider;
    }

    private function doStreamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent,
        ?callable $shouldAbort,
    ): \Generator {
        $baseUrl = $this->resolveBaseUrl();

        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/') . '/v1/responses', [
            'headers' => $this->buildRequestHeaders(),
            'body' => $this->encodePayload($payload),
            'buffer' => false,
            'http_version' => '1.1',
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        if ($shouldAbort && $shouldAbort()) {
            $response->cancel();

            return;
        }

        $this->throwForHttpError($response);
        $this->extractRateLimitHeaders($response);

        $state = new OpenAiTranslatorState();
        $currentEvent = null;
        $currentDataLines = [];
        $currentDataBytes = 0;
        $lineReader = new BoundedSseLineBuffer(self::MAX_SSE_LINE_BYTES);
        $lastActivityAt = ($this->timeProvider)();

        try {
            foreach ($this->httpClient->stream($response, $this->streamPollTimeoutSeconds) as $chunk) {
                if ($shouldAbort && $shouldAbort()) {
                    $response->cancel();

                    return;
                }

                if ($chunk->isTimeout()) {
                    if (($this->timeProvider)() - $lastActivityAt >= $this->idleTimeoutSeconds) {
                        $response->cancel();

                        throw new ApiErrorException(
                            "Streaming response stalled for more than {$this->idleTimeoutSeconds}s without new data. Retry the turn.",
                            'stream_timeout',
                        );
                    }

                    continue;
                }

                $content = $chunk->getContent();
                $lastActivityAt = ($this->timeProvider)();

                foreach ($lineReader->push($content) as $line) {
                    foreach ($this->processSseLine(
                        rtrim($line, "\r"),
                        $currentEvent,
                        $currentDataLines,
                        $currentDataBytes,
                        $state,
                        $onRawEvent,
                    ) as $emitted) {
                        if ($shouldAbort && $shouldAbort()) {
                            $response->cancel();

                            return;
                        }

                        yield $emitted;
                    }
                }
            }
        } catch (\LengthException $e) {
            $response->cancel();

            throw new ApiErrorException(
                'Streaming SSE line exceeded the configured size limit.',
                'protocol_error',
                previous: $e,
            );
        } catch (\Throwable $e) {
            if ($shouldAbort && $shouldAbort()) {
                $response->cancel();

                return;
            }

            throw $e;
        }

        foreach ($lineReader->push('', true) as $line) {
            foreach ($this->processSseLine(
                rtrim($line, "\r"),
                $currentEvent,
                $currentDataLines,
                $currentDataBytes,
                $state,
                $onRawEvent,
            ) as $emitted) {
                yield $emitted;
            }
        }

        foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $currentDataBytes, $state, $onRawEvent) as $emitted) {
            yield $emitted;
        }
    }

    /**
     * Build an OpenAI Responses API request body from the Anthropic-shaped
     * system prompt, messages and tools.
     *
     * @param array $systemPrompt Anthropic-shaped system prompt blocks
     * @param array $messages     Anthropic-shaped messages
     * @param array $tools        [{name, description, input_schema}]
     */
    public function buildPayload(array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->resolveModel(),
            'input' => $this->translateMessagesToInput($messages),
            'stream' => true,
            'max_output_tokens' => $this->resolveMaxTokens(),
            'store' => false,
        ];

        $instructions = $this->extractSystemText($systemPrompt);
        if ($instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        if ($tools !== []) {
            $payload['tools'] = $this->translateTools($tools);
        }

        $reasoning = $this->resolveReasoning();
        if ($reasoning !== null) {
            $payload['reasoning'] = $reasoning;
        }

        return $payload;
    }

    /**
     * Map the translator's synthesized Anthropic event stream for a single
     * SSE envelope. Shared SSE parsing logic with the Anthropic provider.
     */
    private function processSseLine(
        string $line,
        ?string &$currentEvent,
        array &$currentDataLines,
        int &$currentDataBytes,
        OpenAiTranslatorState $state,
        ?callable $onRawEvent,
    ): array {
        $events = [];

        if (str_starts_with($line, 'event:')) {
            foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $currentDataBytes, $state, $onRawEvent) as $emitted) {
                $events[] = $emitted;
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
            foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $currentDataBytes, $state, $onRawEvent) as $emitted) {
                $events[] = $emitted;
            }

            return $events;
        }

        return $events;
    }

    /**
     * Convert the currently-buffered SSE envelope into zero or more
     * Anthropic-shaped StreamEvents.
     */
    private function flushPendingSseEvent(
        ?string &$currentEvent,
        array &$currentDataLines,
        int &$currentDataBytes,
        OpenAiTranslatorState $state,
        ?callable $onRawEvent,
    ): array {
        if ($currentEvent === null || $currentDataLines === []) {
            $currentEvent = null;
            $currentDataLines = [];
            $currentDataBytes = 0;

            return [];
        }

        $rawData = implode("\n", $currentDataLines);
        $eventName = $currentEvent;

        $currentEvent = null;
        $currentDataLines = [];
        $currentDataBytes = 0;

        if ($rawData === '[DONE]') {
            return [];
        }

        $decoded = StreamEvent::decodeSseData($rawData, 'OpenAI Responses');

        $translated = $this->translateOpenAiEvent($eventName, $decoded, $state);

        if ($onRawEvent) {
            foreach ($translated as $event) {
                $onRawEvent($event);
            }
        }

        return $translated;
    }
}
