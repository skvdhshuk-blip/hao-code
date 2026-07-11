<?php

namespace HaoCode\Services\Api;

use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI Chat Completions API (/v1/chat/completions) streaming provider.
 *
 * Covers the same surface as {@see OpenAiProvider} but targets the older,
 * more widely-supported Chat Completions interface. Required for proxies
 * that haven't adopted Responses yet (aihubmix, DeepSeek, vLLM, many
 * OpenAI-compatible gateways).
 *
 * Wire differences vs. Responses:
 *   - Payload uses a flat `messages` array with roles (system/user/assistant/tool)
 *     instead of nested "input" items.
 *   - Tools are nested under `{type:'function', function:{...}}` rather than flat.
 *   - SSE frames have no `event:` line — only `data:` JSON deltas.
 *   - Tool-call streaming uses `choices[0].delta.tool_calls[]` with its own
 *     `index` namespace, which we remap onto synthesized Anthropic content
 *     block indices.
 *   - Reasoning appears as `delta.reasoning_content` (DeepSeek / some proxies).
 *   - Usage arrives on the final delta only when `stream_options.include_usage`
 *     is set; we always request it.
 */
class OpenAiChatProvider implements LlmProvider
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
        private ?\HaoCode\Services\Settings\SettingsManager $settingsManager = null,
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

    /**
     * Public for testing — build the Chat Completions request body from the
     * caller-facing Anthropic-shaped inputs.
     */
    public function buildPayload(array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->resolveModel(),
            'messages' => $this->translateMessages($systemPrompt, $messages),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_tokens' => $this->resolveMaxTokens(),
        ];

        if ($tools !== []) {
            $payload['tools'] = $this->translateTools($tools);
        }

        // DeepSeek R1 and other reasoning-capable models honour this hint;
        // models that don't understand it simply ignore the field.
        if ($this->resolveThinkingEnabled()) {
            $budget = $this->resolveThinkingBudget();
            $payload['reasoning_effort'] = match (true) {
                $budget >= 16000 => 'high',
                $budget >= 4000 => 'medium',
                default => 'low',
            };
        }

        return $payload;
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
        $body = $this->encodePayload($payload);
        $url = rtrim($baseUrl, '/') . '/v1/chat/completions';
        $debug = getenv('HAOCODE_STREAM_DEBUG') === '1';

        // 用 PHP 原生 stream wrappers 实现 SSE 流式读取，绕开 Symfony HttpClient + Curl
        // 在某些 SSE/chunked-transfer 网关下被 16KB write-buffer 提前 close stream 的问题。
        // PHP 的 http:// wrapper 自己管 chunked decoding，对大量 SSE event 友好。
        $headers = [
            'Authorization: Bearer ' . $this->resolveApiKey(),
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Connection: close',
        ];
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'protocol_version' => 1.1,
                'timeout' => $this->idleTimeoutSeconds,
                'ignore_errors' => true, // 让我们自己处理 4xx/5xx
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        if ($shouldAbort && $shouldAbort()) {
            return;
        }

        $fp = @fopen($url, 'r', false, $ctx);
        if ($fp === false) {
            $err = error_get_last()['message'] ?? 'unknown';
            throw new ApiErrorException("Failed to open stream to {$url}: {$err}", 'transport_error');
        }

        // 解析响应头拿状态码
        $meta = stream_get_meta_data($fp);
        $statusLine = $meta['wrapper_data'][0] ?? '';
        $statusCode = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', (string) $statusLine, $m)) {
            $statusCode = (int) $m[1];
        }
        if ($debug) fwrite(STDERR, "[stream] opened, status={$statusCode}\n");

        if ($statusCode >= 400) {
            // 读出全部 body 用作错误信息
            $errBody = stream_get_contents($fp) ?: '';
            fclose($fp);
            $msg = $errBody !== '' ? $errBody : "HTTP {$statusCode}";
            $errorType = 'http_error';
            $decoded = json_decode($errBody, true);
            if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
                $errorType = (string) ($decoded['error']['type'] ?? $errorType);
                $msg = (string) ($decoded['error']['message'] ?? $msg);
            }
            throw new ApiErrorException($msg, $errorType, $statusCode);
        }

        $state = new OpenAiChatTranslatorState();
        $lineBuffer = '';
        $loopStart = ($this->timeProvider)();
        $lastActivityAt = $loopStart;
        $chunkCount = 0;
        $totalBytes = 0;
        stream_set_timeout($fp, max(1, (int) $this->streamPollTimeoutSeconds));

        try {
            while (!feof($fp)) {
                if ($shouldAbort && $shouldAbort()) {
                    fclose($fp);

                    return;
                }

                $data = fread($fp, 65536);
                if ($data === false || $data === '') {
                    // 看是 EOF 还是 timeout
                    $meta = stream_get_meta_data($fp);
                    if ($meta['timed_out'] ?? false) {
                        if (($this->timeProvider)() - $lastActivityAt >= $this->idleTimeoutSeconds) {
                            fclose($fp);

                            throw new ApiErrorException(
                                "Streaming response stalled for more than {$this->idleTimeoutSeconds}s without new data. Retry the turn.",
                                'stream_timeout',
                            );
                        }
                        continue;
                    }
                    if (feof($fp)) break;
                    continue;
                }

                $chunkCount++;
                $totalBytes += strlen($data);
                $lineBuffer .= $data;
                $lastActivityAt = ($this->timeProvider)();
                if ($debug && ($chunkCount <= 5 || $chunkCount % 50 === 0)) {
                    $elapsed = round($lastActivityAt - $loopStart, 2);
                    fwrite(STDERR, "[stream] chunk#{$chunkCount} +" . strlen($data) . "B total={$totalBytes} t={$elapsed}s\n");
                }

                while (($newlinePos = strpos($lineBuffer, "\n")) !== false) {
                    $line = rtrim(substr($lineBuffer, 0, $newlinePos), "\r");
                    $lineBuffer = substr($lineBuffer, $newlinePos + 1);

                    foreach ($this->processSseLine($line, $state, $onRawEvent) as $emitted) {
                        if ($shouldAbort && $shouldAbort()) {
                            fclose($fp);

                            return;
                        }

                        yield $emitted;
                    }
                }
            }
            if ($debug) {
                $elapsed = round(($this->timeProvider)() - $loopStart, 2);
                fwrite(STDERR, "[stream] EOF chunks={$chunkCount} bytes={$totalBytes} t={$elapsed}s\n");
            }
        } catch (\Throwable $e) {
            if ($debug) fwrite(STDERR, "[stream] EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n");
            if (is_resource($fp)) fclose($fp);
            if ($shouldAbort && $shouldAbort()) {
                return;
            }
            throw $e;
        }

        if (is_resource($fp)) fclose($fp);

        if ($lineBuffer !== '') {
            if ($debug) fwrite(STDERR, "[stream] flushing tail lineBuffer=" . strlen($lineBuffer) . "B\n");
            foreach ($this->processSseLine(rtrim($lineBuffer, "\r"), $state, $onRawEvent) as $emitted) {
                yield $emitted;
            }
        }

        // Emit deferred message_delta/stop if the server omitted a final
        // usage-only frame (some proxies stop after [DONE]).
        foreach ($this->finalizeIfNeeded($state) as $emitted) {
            yield $emitted;
        }
    }

    /**
     * Parse one SSE line and emit zero or more translated events.
     *
     * Chat Completions streams are simpler than Responses: every payload
     * arrives as `data: {json}` and there is no `event:` header. The only
     * non-data line we care about is `data: [DONE]`.
     *
     * @return StreamEvent[]
     */
    private function processSseLine(string $line, OpenAiChatTranslatorState $state, ?callable $onRawEvent): array
    {
        if ($line === '' || ! str_starts_with($line, 'data:')) {
            return [];
        }

        $raw = substr($line, 5);
        if (str_starts_with($raw, ' ')) {
            $raw = substr($raw, 1);
        }
        $raw = trim($raw);

        if ($raw === '' || $raw === '[DONE]') {
            return [];
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return [];
        }

        // Some proxies send errors as data payloads mid-stream.
        if (isset($data['error']) && is_array($data['error'])) {
            $message = (string) ($data['error']['message'] ?? 'Chat completions stream error');
            $errorType = (string) ($data['error']['type'] ?? $data['error']['code'] ?? 'api_error');
            throw new ApiErrorException($message, $errorType);
        }

        $events = $this->translateChunk($data, $state);

        if ($onRawEvent) {
            foreach ($events as $event) {
                $onRawEvent($event);
            }
        }

        return $events;
    }

    /**
     * @return StreamEvent[]
     */
    private function translateChunk(array $data, OpenAiChatTranslatorState $state): array
    {
        $events = [];

        if (! $state->messageStartEmitted) {
            $state->messageStartEmitted = true;
            $events[] = new StreamEvent('message_start', [
                'type' => 'message_start',
                'message' => [
                    'id' => (string) ($data['id'] ?? ''),
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => (string) ($data['model'] ?? $this->resolveModel()),
                    'content' => [],
                    'stop_reason' => null,
                    'usage' => $this->mapUsage($data['usage'] ?? []),
                ],
            ]);
        }

        $choice = $data['choices'][0] ?? null;
        if (is_array($choice)) {
            $delta = $choice['delta'] ?? [];
            if (is_array($delta)) {
                $events = array_merge($events, $this->translateDelta($delta, $state));
            }

            $finishReason = $choice['finish_reason'] ?? null;
            if (is_string($finishReason) && $finishReason !== '') {
                $state->pendingFinishReason = $finishReason;
            }
        }

        // Final usage-only frame (choices may be empty, but usage populated).
        // We intentionally do NOT finalize here — Chat Completions emits the
        // finish_reason on one chunk and the include_usage totals on a
        // separate trailing chunk, so eager finalization would race past the
        // usage data. The stream-end path in doStreamMessages() calls
        // finalizeIfNeeded() once, after both have been observed.
        if (isset($data['usage']) && is_array($data['usage'])) {
            $state->pendingUsage = $data['usage'];
        }

        return $events;
    }

    /**
     * @return StreamEvent[]
     */
    private function translateDelta(array $delta, OpenAiChatTranslatorState $state): array
    {
        $events = [];

        // Reasoning fragments. DeepSeek uses `reasoning_content`; a few
        // proxies use `reasoning` as either string or {content: "..."}.
        $reasoning = $this->extractReasoningDelta($delta);
        if ($reasoning !== '') {
            if ($state->thinkingBlockIndex === null) {
                $state->thinkingBlockIndex = $state->nextBlockIndex++;
                $events[] = new StreamEvent('content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $state->thinkingBlockIndex,
                    'content_block' => ['type' => 'thinking', 'thinking' => ''],
                ]);
            }
            $events[] = new StreamEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $state->thinkingBlockIndex,
                'delta' => ['type' => 'thinking_delta', 'thinking' => $reasoning],
            ]);
        }

        // Plain text content.
        $content = $delta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            // Reasoning block (if any) must be closed before visible text
            // appears — mirrors Anthropic's ordering guarantee.
            if ($state->thinkingBlockIndex !== null && ! $state->thinkingBlockStopped) {
                $state->thinkingBlockStopped = true;
                $events[] = new StreamEvent('content_block_stop', [
                    'type' => 'content_block_stop',
                    'index' => $state->thinkingBlockIndex,
                ]);
            }

            if ($state->textBlockIndex === null) {
                $state->textBlockIndex = $state->nextBlockIndex++;
                $events[] = new StreamEvent('content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $state->textBlockIndex,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]);
            }

            $events[] = new StreamEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $state->textBlockIndex,
                'delta' => ['type' => 'text_delta', 'text' => $content],
            ]);
        }

        // Tool calls. Each stream-index slot accumulates a single tool_use
        // block. The first fragment carries id + name; subsequent fragments
        // only carry argument deltas.
        $toolCalls = $delta['tool_calls'] ?? null;
        if (is_array($toolCalls)) {
            if ($state->textBlockIndex !== null && ! $state->textBlockStopped) {
                $state->textBlockStopped = true;
                $events[] = new StreamEvent('content_block_stop', [
                    'type' => 'content_block_stop',
                    'index' => $state->textBlockIndex,
                ]);
            }
            if ($state->thinkingBlockIndex !== null && ! $state->thinkingBlockStopped) {
                $state->thinkingBlockStopped = true;
                $events[] = new StreamEvent('content_block_stop', [
                    'type' => 'content_block_stop',
                    'index' => $state->thinkingBlockIndex,
                ]);
            }

            foreach ($toolCalls as $fragment) {
                if (! is_array($fragment)) {
                    continue;
                }

                $streamIndex = (int) ($fragment['index'] ?? 0);

                if (! isset($state->toolCallBlockIndexByStreamIndex[$streamIndex])) {
                    $blockIndex = $state->nextBlockIndex++;
                    $state->toolCallBlockIndexByStreamIndex[$streamIndex] = $blockIndex;
                    $state->hasToolCall = true;
                    $events[] = new StreamEvent('content_block_start', [
                        'type' => 'content_block_start',
                        'index' => $blockIndex,
                        'content_block' => [
                            'type' => 'tool_use',
                            'id' => (string) ($fragment['id'] ?? ''),
                            'name' => (string) ($fragment['function']['name'] ?? ''),
                            'input' => new \stdClass(),
                        ],
                    ]);
                } else {
                    $blockIndex = $state->toolCallBlockIndexByStreamIndex[$streamIndex];
                }

                $argumentsDelta = $fragment['function']['arguments'] ?? null;
                if (is_string($argumentsDelta) && $argumentsDelta !== '') {
                    $events[] = new StreamEvent('content_block_delta', [
                        'type' => 'content_block_delta',
                        'index' => $blockIndex,
                        'delta' => [
                            'type' => 'input_json_delta',
                            'partial_json' => $argumentsDelta,
                        ],
                    ]);
                }
            }
        }

        return $events;
    }

    /**
     * @return StreamEvent[]
     */
    private function finalizeIfNeeded(OpenAiChatTranslatorState $state): array
    {
        if ($state->pendingFinishReason === null || $state->pendingFinishReasonEmitted) {
            return [];
        }

        $events = [];

        if ($state->thinkingBlockIndex !== null && ! $state->thinkingBlockStopped) {
            $state->thinkingBlockStopped = true;
            $events[] = new StreamEvent('content_block_stop', [
                'type' => 'content_block_stop',
                'index' => $state->thinkingBlockIndex,
            ]);
        }
        if ($state->textBlockIndex !== null && ! $state->textBlockStopped) {
            $state->textBlockStopped = true;
            $events[] = new StreamEvent('content_block_stop', [
                'type' => 'content_block_stop',
                'index' => $state->textBlockIndex,
            ]);
        }
        foreach ($state->toolCallBlockIndexByStreamIndex as $streamIndex => $blockIndex) {
            if (isset($state->toolCallBlocksClosed[$streamIndex])) {
                continue;
            }
            $state->toolCallBlocksClosed[$streamIndex] = true;
            $events[] = new StreamEvent('content_block_stop', [
                'type' => 'content_block_stop',
                'index' => $blockIndex,
            ]);
        }

        $state->pendingFinishReasonEmitted = true;

        $events[] = new StreamEvent('message_delta', [
            'type' => 'message_delta',
            'delta' => [
                'stop_reason' => $this->mapFinishReason($state->pendingFinishReason, $state->hasToolCall),
                'stop_sequence' => null,
            ],
            'usage' => $this->mapUsage($state->pendingUsage),
        ]);
        $events[] = new StreamEvent('message_stop', ['type' => 'message_stop']);

        return $events;
    }

    private function mapFinishReason(string $finishReason, bool $hasToolCall): string
    {
        return match ($finishReason) {
            'tool_calls', 'function_call' => 'tool_use',
            'length' => 'max_tokens',
            'content_filter' => 'stop_sequence',
            default => $hasToolCall ? 'tool_use' : 'end_turn',
        };
    }

    /**
     * Chat Completions providers disagree on the reasoning field:
     *   - DeepSeek: `delta.reasoning_content` (string)
     *   - OpenRouter / some proxies: `delta.reasoning` (string) or
     *     `delta.reasoning: {content: "..."}`
     */
    private function extractReasoningDelta(array $delta): string
    {
        $candidate = $delta['reasoning_content'] ?? null;
        if (is_string($candidate)) {
            return $candidate;
        }

        $candidate = $delta['reasoning'] ?? null;
        if (is_string($candidate)) {
            return $candidate;
        }
        if (is_array($candidate) && is_string($candidate['content'] ?? null)) {
            return $candidate['content'];
        }

        return '';
    }

    private function translateMessages(array $systemPrompt, array $messages): array
    {
        $out = [];

        $systemText = $this->extractSystemText($systemPrompt);
        if ($systemText !== '') {
            $out[] = ['role' => 'system', 'content' => $systemText];
        }

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                if (trim($content) === '') {
                    continue;
                }
                $out[] = ['role' => $role, 'content' => $content];
                continue;
            }

            if (! is_array($content)) {
                continue;
            }

            if ($role === 'user') {
                $this->appendUserBlocks($out, $content);
            } elseif ($role === 'assistant') {
                $this->appendAssistantBlocks($out, $content);
            }
        }

        return $out;
    }

    private function appendUserBlocks(array &$out, array $content): void
    {
        $parts = [];
        $trailingToolResults = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $parts[] = ['type' => 'text', 'text' => $text];
            } elseif ($type === 'image') {
                $url = $this->imageBlockToDataUri($block);
                if ($url !== null) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                }
            } elseif ($type === 'tool_result') {
                $trailingToolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                    'content' => $this->stringifyToolResultContent($block['content'] ?? ''),
                ];
            }
        }

        if ($parts !== []) {
            // Collapse a single text-only block to a plain string — many
            // proxies mishandle the array form for trivial messages.
            if (count($parts) === 1 && $parts[0]['type'] === 'text') {
                $out[] = ['role' => 'user', 'content' => $parts[0]['text']];
            } else {
                $out[] = ['role' => 'user', 'content' => $parts];
            }
        }

        foreach ($trailingToolResults as $toolMessage) {
            $out[] = $toolMessage;
        }
    }

    private function appendAssistantBlocks(array &$out, array $content): void
    {
        $textParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text !== '') {
                    $textParts[] = $text;
                }
            } elseif ($type === 'tool_use') {
                $rawInput = $block['input'] ?? new \stdClass();
                $arguments = is_string($rawInput) ? $rawInput : json_encode($rawInput, JSON_UNESCAPED_UNICODE);
                if ($arguments === false || $arguments === '' || $arguments === 'null') {
                    $arguments = '{}';
                }
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => $arguments,
                    ],
                ];
            }
            // thinking blocks: dropped (no faithful replay without encrypted signature)
        }

        if ($textParts === [] && $toolCalls === []) {
            return;
        }

        $message = ['role' => 'assistant'];
        if ($textParts !== []) {
            $message['content'] = implode("\n", $textParts);
        } else {
            $message['content'] = null;
        }
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        $out[] = $message;
    }

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

        $input = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
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

        return $mapped;
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

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-ratelimit-') || $lower === 'retry-after') {
                $this->lastRateLimitHeaders[$lower] = $values[0] ?? '';
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
                'Network transport error while streaming chat.completions response: ' . $e->getMessage(),
                'transport_error',
                previous: $e,
            );
        }

        return $e;
    }
}

/**
 * Mutable per-turn translator state for the Chat Completions stream.
 *
 * Stream events don't carry explicit content-block indices, so we allocate
 * our own Anthropic-style indices as text / reasoning / tool_use fragments
 * appear, and remember enough state to emit matching content_block_stop
 * events at the right time.
 */
class OpenAiChatTranslatorState
{
    public bool $messageStartEmitted = false;
    public int $nextBlockIndex = 0;
    public ?int $textBlockIndex = null;
    public bool $textBlockStopped = false;
    public ?int $thinkingBlockIndex = null;
    public bool $thinkingBlockStopped = false;
    /** @var array<int, int> stream tool_call index → synthesized content_block index */
    public array $toolCallBlockIndexByStreamIndex = [];
    /** @var array<int, true> */
    public array $toolCallBlocksClosed = [];
    public bool $hasToolCall = false;
    public ?string $pendingFinishReason = null;
    public bool $pendingFinishReasonEmitted = false;
    /** @var array<string, mixed> */
    public array $pendingUsage = [];
}
