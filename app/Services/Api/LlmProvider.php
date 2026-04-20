<?php

namespace HaoCode\Services\Api;

/**
 * Low-level streaming LLM provider contract.
 *
 * Every implementation is responsible for translating the caller-facing
 * Anthropic-shaped request (system prompt blocks, messages with tool_use /
 * tool_result content blocks, tools as {name, description, input_schema})
 * into its own wire format, and emitting {@see StreamEvent} values that
 * match the Anthropic SSE event surface StreamProcessor consumes:
 *
 *   - message_start           {message: {id, model, usage}}
 *   - content_block_start     {index, content_block: {type, ...}}
 *                             type ∈ {text, tool_use, thinking}
 *   - content_block_delta     {index, delta: {type: text_delta|input_json_delta|thinking_delta|signature_delta, ...}}
 *   - content_block_stop      {index}
 *   - message_delta           {delta: {stop_reason}, usage?}
 *   - message_stop
 *   - error                   {error: {message, type}}
 *   - ping                    (optional, ignored)
 */
interface LlmProvider
{
    /**
     * @param array $systemPrompt Anthropic-shaped system prompt array (blocks of {type:'text', text:...})
     * @param array $messages     Anthropic-shaped messages
     * @param array $tools        [{name, description, input_schema}]
     * @return \Generator<StreamEvent>
     */
    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator;

    /**
     * @return array<string, string>
     */
    public function getLastRateLimitHeaders(): array;
}
