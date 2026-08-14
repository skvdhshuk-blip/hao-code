<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiProviderTranslateOpenAiEventConcern
{

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

    /**
     * Custom request headers for this run (run-scoped settings win over the
     * constructor map).
     *
     * @return array<string, string>
     */
    private function resolveCustomHeaders(): array
    {
        return $this->settingsManager?->getHeaders() ?: $this->headers;
    }
}
