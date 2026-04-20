<?php

namespace App\Services\Api;

use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI Responses API (/v1/responses) streaming provider.
 *
 * Translates the caller-facing Anthropic-shaped request into a Responses
 * API payload, and maps the Responses event stream back into the
 * Anthropic SSE events StreamProcessor consumes, so the rest of the
 * agent loop is unaware of the wire format swap.
 *
 * Mapping notes:
 *   - Each Responses "output item" (message / reasoning / function_call)
 *     is surfaced as a single Anthropic content block, keyed by
 *     output_index.
 *   - response.output_text.delta           → text_delta
 *   - response.reasoning_summary_text.delta
 *     (or response.reasoning_text.delta)   → thinking_delta
 *   - response.function_call_arguments.delta → input_json_delta
 *   - stop_reason: tool_use when any output item is a function_call,
 *     max_tokens when response.incomplete_details.reason says so,
 *     end_turn otherwise.
 *
 * Prompt-caching is NOT advertised here: the Responses API has no
 * equivalent to Anthropic's cache_control breakpoints, so caller-supplied
 * cache_control hints are stripped during translation.
 */
class OpenAiProvider implements LlmProvider
{
    private HttpClientInterface $httpClient;
    private int $maxRetries = 3;
    private array $lastRateLimitHeaders = [];
    /** @var callable(): float */
    private $timeProvider;

    public function __construct(
        private readonly string $apiKey,
        private string $model,
        private readonly string $baseUrl = 'https://api.openai.com',
        private int $maxTokens = 16384,
        private readonly bool $thinkingEnabled = false,
        private readonly int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        private ?\App\Services\Settings\SettingsManager $settingsManager = null,
        private readonly int $idleTimeoutSeconds = 60,
        private readonly float $streamPollTimeoutSeconds = 1.0,
        ?callable $timeProvider = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
        ]);
        $this->timeProvider = $timeProvider ?? static fn (): float => microtime(true);
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

            $hasYieldedEvents = false;

            try {
                foreach ($this->doStreamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort) as $event) {
                    $hasYieldedEvents = true;
                    yield $event;
                }
                return;
            } catch (\Throwable $e) {
                if ($shouldAbort && $shouldAbort()) {
                    return;
                }

                if ($hasYieldedEvents) {
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
            'headers' => [
                'authorization' => 'Bearer ' . $this->resolveApiKey(),
                'content-type' => 'application/json',
                'accept' => 'text/event-stream',
            ],
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
        $lineBuffer = '';
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

                $lineBuffer .= $chunk->getContent();
                $lastActivityAt = ($this->timeProvider)();

                while (($newlinePos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $newlinePos);
                    $lineBuffer = substr($lineBuffer, $newlinePos + 1);

                    foreach ($this->processSseLine(rtrim($line, "\r"), $currentEvent, $currentDataLines, $state, $onRawEvent) as $emitted) {
                        if ($shouldAbort && $shouldAbort()) {
                            $response->cancel();

                            return;
                        }

                        yield $emitted;
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($shouldAbort && $shouldAbort()) {
                $response->cancel();

                return;
            }

            throw $e;
        }

        if ($lineBuffer !== '') {
            foreach ($this->processSseLine(rtrim($lineBuffer, "\r"), $currentEvent, $currentDataLines, $state, $onRawEvent) as $emitted) {
                yield $emitted;
            }
        }

        foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $state, $onRawEvent) as $emitted) {
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
        OpenAiTranslatorState $state,
        ?callable $onRawEvent,
    ): array {
        $events = [];

        if (str_starts_with($line, 'event:')) {
            foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $state, $onRawEvent) as $emitted) {
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
            $currentDataLines[] = $dataLine;

            return $events;
        }

        if ($line === '') {
            foreach ($this->flushPendingSseEvent($currentEvent, $currentDataLines, $state, $onRawEvent) as $emitted) {
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
        OpenAiTranslatorState $state,
        ?callable $onRawEvent,
    ): array {
        if ($currentEvent === null || $currentDataLines === []) {
            $currentEvent = null;
            $currentDataLines = [];

            return [];
        }

        $rawData = implode("\n", $currentDataLines);
        $eventName = $currentEvent;

        $currentEvent = null;
        $currentDataLines = [];

        if ($rawData === '[DONE]') {
            return [];
        }

        $decoded = json_decode($rawData, true);
        if (! is_array($decoded)) {
            return [];
        }

        $translated = $this->translateOpenAiEvent($eventName, $decoded, $state);

        if ($onRawEvent) {
            foreach ($translated as $event) {
                $onRawEvent($event);
            }
        }

        return $translated;
    }

    /**
     * Translate one OpenAI Responses SSE event into zero or more
     * Anthropic-shaped StreamEvents.
     *
     * @return StreamEvent[]
     */
    private function translateOpenAiEvent(string $eventName, array $data, OpenAiTranslatorState $state): array
    {
        // Responses API nests type inside data too; prefer the explicit
        // event name on the envelope but fall back to data.type.
        $type = $eventName !== '' ? $eventName : (string) ($data['type'] ?? '');
        $events = [];

        switch ($type) {
            case 'response.created':
            case 'response.in_progress':
                if (! $state->messageStartEmitted) {
                    $state->messageStartEmitted = true;
                    $response = $data['response'] ?? [];
                    $events[] = new StreamEvent('message_start', [
                        'type' => 'message_start',
                        'message' => [
                            'id' => (string) ($response['id'] ?? ''),
                            'type' => 'message',
                            'role' => 'assistant',
                            'model' => (string) ($response['model'] ?? $this->resolveModel()),
                            'content' => [],
                            'stop_reason' => null,
                            'usage' => $this->mapUsage($response['usage'] ?? []),
                        ],
                    ]);
                }
                break;

            case 'response.output_item.added':
                $outputIndex = (int) ($data['output_index'] ?? 0);
                $item = $data['item'] ?? [];
                $itemType = (string) ($item['type'] ?? '');

                if ($itemType === 'function_call') {
                    $state->contentBlocks[$outputIndex] = ['type' => 'tool_use', 'call_id' => (string) ($item['call_id'] ?? $item['id'] ?? '')];
                    $state->hasFunctionCall = true;
                    $events[] = new StreamEvent('content_block_start', [
                        'type' => 'content_block_start',
                        'index' => $outputIndex,
                        'content_block' => [
                            'type' => 'tool_use',
                            'id' => $state->contentBlocks[$outputIndex]['call_id'],
                            'name' => (string) ($item['name'] ?? ''),
                            'input' => new \stdClass(),
                        ],
                    ]);
                } elseif ($itemType === 'reasoning') {
                    $state->contentBlocks[$outputIndex] = ['type' => 'thinking'];
                    $events[] = new StreamEvent('content_block_start', [
                        'type' => 'content_block_start',
                        'index' => $outputIndex,
                        'content_block' => [
                            'type' => 'thinking',
                            'thinking' => '',
                        ],
                    ]);
                } elseif ($itemType === 'message') {
                    // Message items get their text content_block_start emitted
                    // lazily on the first output_text.delta so we don't open an
                    // empty block for items that contain only refusals etc.
                    $state->contentBlocks[$outputIndex] = ['type' => 'message', 'text_started' => false];
                }
                break;

            case 'response.output_text.delta':
                $outputIndex = (int) ($data['output_index'] ?? 0);
                $delta = (string) ($data['delta'] ?? '');
                if ($delta === '') {
                    break;
                }

                if (! isset($state->contentBlocks[$outputIndex])) {
                    $state->contentBlocks[$outputIndex] = ['type' => 'message', 'text_started' => false];
                }

                if (empty($state->contentBlocks[$outputIndex]['text_started'])) {
                    $state->contentBlocks[$outputIndex]['text_started'] = true;
                    $state->contentBlocks[$outputIndex]['type'] = 'text';
                    $events[] = new StreamEvent('content_block_start', [
                        'type' => 'content_block_start',
                        'index' => $outputIndex,
                        'content_block' => [
                            'type' => 'text',
                            'text' => '',
                        ],
                    ]);
                }

                $events[] = new StreamEvent('content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $outputIndex,
                    'delta' => [
                        'type' => 'text_delta',
                        'text' => $delta,
                    ],
                ]);
                break;

            case 'response.function_call_arguments.delta':
                $outputIndex = (int) ($data['output_index'] ?? 0);
                $delta = (string) ($data['delta'] ?? '');
                if ($delta === '') {
                    break;
                }
                $events[] = new StreamEvent('content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $outputIndex,
                    'delta' => [
                        'type' => 'input_json_delta',
                        'partial_json' => $delta,
                    ],
                ]);
                break;

            case 'response.reasoning_summary_text.delta':
            case 'response.reasoning_text.delta':
                $outputIndex = (int) ($data['output_index'] ?? 0);
                $delta = (string) ($data['delta'] ?? '');
                if ($delta === '') {
                    break;
                }
                $events[] = new StreamEvent('content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $outputIndex,
                    'delta' => [
                        'type' => 'thinking_delta',
                        'thinking' => $delta,
                    ],
                ]);
                break;

            case 'response.output_item.done':
                $outputIndex = (int) ($data['output_index'] ?? 0);
                if (isset($state->contentBlocks[$outputIndex])) {
                    $events[] = new StreamEvent('content_block_stop', [
                        'type' => 'content_block_stop',
                        'index' => $outputIndex,
                    ]);
                    unset($state->contentBlocks[$outputIndex]);
                }
                break;

            case 'response.completed':
                $response = $data['response'] ?? [];
                $events[] = new StreamEvent('message_delta', [
                    'type' => 'message_delta',
                    'delta' => [
                        'stop_reason' => $this->resolveStopReason($response, $state),
                        'stop_sequence' => null,
                    ],
                    'usage' => $this->mapUsage($response['usage'] ?? []),
                ]);
                $events[] = new StreamEvent('message_stop', ['type' => 'message_stop']);
                break;

            case 'response.incomplete':
                $response = $data['response'] ?? [];
                $events[] = new StreamEvent('message_delta', [
                    'type' => 'message_delta',
                    'delta' => [
                        'stop_reason' => $this->resolveStopReason($response, $state),
                        'stop_sequence' => null,
                    ],
                    'usage' => $this->mapUsage($response['usage'] ?? []),
                ]);
                $events[] = new StreamEvent('message_stop', ['type' => 'message_stop']);
                break;

            case 'response.failed':
            case 'error':
                $errorPayload = $data['error'] ?? ($data['response']['error'] ?? []);
                $message = is_string($errorPayload['message'] ?? null)
                    ? $errorPayload['message']
                    : 'OpenAI streaming error';
                $errorType = is_string($errorPayload['type'] ?? null)
                    ? $errorPayload['type']
                    : (is_string($errorPayload['code'] ?? null) ? $errorPayload['code'] : 'api_error');
                throw new ApiErrorException($message, $errorType);

            default:
                // Other events (content_part.added/done, refusal deltas,
                // reasoning_summary_part.*, response.queued, etc.) are not
                // mapped — the StreamProcessor only needs the coarse-grained
                // block/delta lifecycle we've already covered.
                break;
        }

        return $events;
    }

    /**
     * @param array $response The response object from response.completed / incomplete
     */
    private function resolveStopReason(array $response, OpenAiTranslatorState $state): string
    {
        $incompleteReason = $response['incomplete_details']['reason'] ?? null;
        if ($incompleteReason === 'max_output_tokens') {
            return 'max_tokens';
        }

        if (isset($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $item) {
                if (($item['type'] ?? null) === 'function_call') {
                    return 'tool_use';
                }
            }
        }

        if ($state->hasFunctionCall) {
            return 'tool_use';
        }

        return 'end_turn';
    }

    /**
     * Translate Anthropic-shaped message history into Responses API input items.
     *
     * - plain user/assistant text → message items with input_text/output_text parts
     * - user tool_result blocks   → standalone function_call_output items
     * - assistant tool_use blocks → standalone function_call items
     * - assistant thinking blocks → skipped (cannot be faithfully replayed
     *   without an encrypted reasoning_item signature from a prior OpenAI turn)
     */
    private function translateMessagesToInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                if (trim($content) === '') {
                    continue;
                }

                $input[] = [
                    'type' => 'message',
                    'role' => $role === 'assistant' ? 'assistant' : 'user',
                    'content' => [[
                        'type' => $role === 'assistant' ? 'output_text' : 'input_text',
                        'text' => $content,
                    ]],
                ];
                continue;
            }

            if (! is_array($content)) {
                continue;
            }

            $textParts = [];
            foreach ($content as $block) {
                $blockType = $block['type'] ?? '';

                if ($blockType === 'text') {
                    $text = (string) ($block['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $textParts[] = [
                        'type' => $role === 'assistant' ? 'output_text' : 'input_text',
                        'text' => $text,
                    ];
                } elseif ($blockType === 'image' && $role !== 'assistant') {
                    $imageUrl = $this->imageBlockToDataUri($block);
                    if ($imageUrl !== null) {
                        $textParts[] = [
                            'type' => 'input_image',
                            'image_url' => $imageUrl,
                        ];
                    }
                } elseif ($blockType === 'tool_result') {
                    if ($textParts !== []) {
                        $input[] = [
                            'type' => 'message',
                            'role' => $role === 'assistant' ? 'assistant' : 'user',
                            'content' => $textParts,
                        ];
                        $textParts = [];
                    }
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => (string) ($block['tool_use_id'] ?? ''),
                        'output' => $this->stringifyToolResultContent($block['content'] ?? ''),
                    ];
                } elseif ($blockType === 'tool_use') {
                    if ($textParts !== []) {
                        $input[] = [
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => $textParts,
                        ];
                        $textParts = [];
                    }
                    $rawInput = $block['input'] ?? new \stdClass();
                    $arguments = is_string($rawInput) ? $rawInput : json_encode($rawInput, JSON_UNESCAPED_UNICODE);
                    if ($arguments === false || $arguments === '' || $arguments === 'null') {
                        $arguments = '{}';
                    }
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => $arguments,
                    ];
                }
                // thinking blocks: intentionally dropped
            }

            if ($textParts !== []) {
                $input[] = [
                    'type' => 'message',
                    'role' => $role === 'assistant' ? 'assistant' : 'user',
                    'content' => $textParts,
                ];
            }
        }

        return $input;
    }

    private function translateTools(array $tools): array
    {
        $translated = [];
        foreach ($tools as $tool) {
            // Strip Anthropic-only fields (cache_control) from the schema.
            $schema = $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if (is_array($schema) && ! isset($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }
            $translated[] = [
                'type' => 'function',
                'name' => (string) ($tool['name'] ?? ''),
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => $schema,
            ];
        }

        return $translated;
    }

    private function extractSystemText(array $systemPrompt): string
    {
        $parts = [];
        foreach ($systemPrompt as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? 'text';
            if ($type !== 'text') {
                continue;
            }
            $text = (string) ($block['text'] ?? '');
            if ($text !== '') {
                $parts[] = $text;
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

        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $reasoning = (int) ($usage['reasoning_tokens'] ?? ($usage['output_tokens_details']['reasoning_tokens'] ?? 0));

        $mapped = [
            'input_tokens' => $input,
            'output_tokens' => $output,
        ];

        if ($reasoning > 0) {
            $mapped['thinking_tokens'] = $reasoning;
        }

        return $mapped;
    }

    private function resolveReasoning(): ?array
    {
        if (! $this->resolveThinkingEnabled()) {
            return null;
        }

        $budget = $this->resolveThinkingBudget();
        $effort = match (true) {
            $budget >= 16000 => 'high',
            $budget >= 4000 => 'medium',
            default => 'low',
        };

        return [
            'effort' => $effort,
            'summary' => 'auto',
        ];
    }

    private function resolveModel(): string
    {
        return $this->settingsManager?->getModel() ?: $this->model;
    }

    private function resolveApiKey(): string
    {
        return $this->settingsManager?->getApiKey() ?: $this->apiKey;
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

        $body = trim($response->getContent(false));
        $url = (string) $response->getInfo('url');
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
            return min((float) $retryAfter, 120);
        }

        return min(2 ** $attempt, 10);
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

/**
 * Mutable per-turn translator state. Not part of the public API.
 */
class OpenAiTranslatorState
{
    public bool $messageStartEmitted = false;
    public bool $hasFunctionCall = false;
    /** @var array<int, array{type: string, call_id?: string, text_started?: bool}> */
    public array $contentBlocks = [];
}
