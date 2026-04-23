<?php

namespace HaoCode\Sdk;

/**
 * Result of an SDK query — carries the response text plus usage metadata.
 *
 * Implements Stringable so it works as a drop-in replacement for string:
 *   echo HaoCode::query('...');  // still works
 *   $result->cost;               // but also carries metadata
 *
 * @api
 */
class QueryResult implements \Stringable
{
    /** @api */
    public function __construct(
        /** @api */
        public readonly string $text,
        /** @api */
        public readonly array $usage,
        /** @api */
        public readonly float $cost,
        /** @api */
        public readonly ?string $sessionId = null,
        /** @api */
        public readonly int $turnsUsed = 0,
    ) {}

    /** @api */
    public function __toString(): string
    {
        return $this->text;
    }

    /** @api */
    public function inputTokens(): int
    {
        return $this->usage['input_tokens'] ?? 0;
    }

    /** @api */
    public function outputTokens(): int
    {
        return $this->usage['output_tokens'] ?? 0;
    }
}
