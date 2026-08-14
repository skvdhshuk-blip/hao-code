<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiChatProviderProcessSseLineConcern
{

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

        if ($raw === '') {
            return [];
        }
        if ($raw === '[DONE]') {
            $state->sawDone = true;

            return [];
        }

        $data = StreamEvent::decodeSseData($raw, 'OpenAI Chat Completions');

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
        if ($state->pendingFinishReasonEmitted) {
            return [];
        }

        if ($state->pendingFinishReason === null) {
            // Some compatible gateways send a complete content stream followed
            // directly by [DONE], without choices[0].finish_reason. Once a
            // visible block or tool call exists, treating that EOF as a normal
            // terminal response is safer than making AgentLoop retry (and bill
            // for) an answer that was already fully delivered. Do not invent a
            // terminal event for an empty stream: the normal incomplete-response
            // recovery path still handles that case.
            $hasDeliveredContent = $state->textBlockIndex !== null
                || $state->thinkingBlockIndex !== null
                || $state->hasToolCall;
            if (! $state->sawDone || ! $hasDeliveredContent) {
                return [];
            }

            $state->pendingFinishReason = $state->hasToolCall ? 'tool_calls' : 'stop';
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

        foreach ($trailingToolResults as $toolMessage) {
            $out[] = $toolMessage;
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
    }

    private function appendAssistantBlocks(array &$out, array $content): void
    {
        $textParts = [];
        $reasoningParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text !== '') {
                    $textParts[] = $text;
                }
            } elseif ($type === 'thinking' && $this->isDeepSeekModel()) {
                $thinking = (string) ($block['thinking'] ?? '');
                if ($thinking !== '') {
                    $reasoningParts[] = $thinking;
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
            // Anthropic thinking blocks remain excluded because their replay
            // requires encrypted signatures. DeepSeek explicitly requires its
            // plain reasoning_content to be echoed during tool-call turns.
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
        // DeepSeek requires reasoning_content to be replayed on tool-call turns,
        // but replaying it on plain assistant turns only enlarges the fresh tail
        // and delays prefix-cache hit-rate growth.
        if ($reasoningParts !== [] && $toolCalls !== []) {
            $message['reasoning_content'] = implode('', $reasoningParts);
        }

        $out[] = $message;
    }

    private function isDeepSeekModel(): bool
    {
        return str_starts_with(strtolower($this->resolveModel()), 'deepseek-');
    }

    private function isDeepSeekV4Flash(): bool
    {
        return strtolower($this->resolveModel()) === 'deepseek-v4-flash';
    }
}
