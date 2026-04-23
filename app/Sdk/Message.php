<?php

namespace HaoCode\Sdk;

/**
 * SDK message — typed envelope for streaming events.
 *
 * Modeled after Claude Agent SDK's SDKMessage types:
 * - text: streaming text delta
 * - tool_start: tool execution began
 * - tool_result: tool execution completed
 * - turn: a new agent turn started
 * - result: final result with usage/cost
 * - error: an error occurred
 *
 * @api
 */
class Message
{
    /** @internal */
    private function __construct(
        /** @api */
        public readonly string $type,
        /** @api */
        public readonly ?string $text = null,
        /** @api */
        public readonly ?string $toolName = null,
        /** @api */
        public readonly ?array $toolInput = null,
        /** @api */
        public readonly ?string $toolOutput = null,
        /** @api */
        public readonly ?bool $toolIsError = null,
        /** @api */
        public readonly ?int $turnNumber = null,
        /** @api */
        public readonly ?string $sessionId = null,
        /** @api */
        public readonly ?array $usage = null,
        /** @api */
        public readonly ?float $cost = null,
        /** @api */
        public readonly ?string $error = null,
    ) {}

    /** @api */
    public static function text(string $delta): self
    {
        return new self(type: 'text', text: $delta);
    }

    /** @api */
    public static function toolStart(string $toolName, array $input): self
    {
        return new self(type: 'tool_start', toolName: $toolName, toolInput: $input);
    }

    /** @api */
    public static function toolResult(string $toolName, string $output, bool $isError = false): self
    {
        return new self(type: 'tool_result', toolName: $toolName, toolOutput: $output, toolIsError: $isError);
    }

    /** @api */
    public static function turn(int $number): self
    {
        return new self(type: 'turn', turnNumber: $number);
    }

    /** @api */
    public static function result(string $text, array $usage, float $cost, ?string $sessionId = null): self
    {
        return new self(type: 'result', text: $text, usage: $usage, cost: $cost, sessionId: $sessionId);
    }

    /** @api */
    public static function error(string $message): self
    {
        return new self(type: 'error', error: $message);
    }

    /** @api */
    public function isResult(): bool
    {
        return $this->type === 'result';
    }

    /** @api */
    public function isError(): bool
    {
        return $this->type === 'error';
    }
}
