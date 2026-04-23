<?php

namespace HaoCode\Sdk;

/**
 * Wrapper for structured (JSON) responses from the agent.
 *
 * Provides array access, property access, and toArray() for convenience.
 *
 * @example
 *   $result = HaoCode::structured('Classify this ticket', $schema);
 *   $result->category;      // 'shipping'
 *   $result['priority'];    // 'high'
 *   $result->toArray();     // ['category' => 'shipping', 'priority' => 'high', ...]
 *
 * @api
 */
class StructuredResult implements \ArrayAccess, \Stringable
{
    private readonly array $data;

    /** @api */
    public readonly string $rawText;

    /** @api */
    public readonly ?QueryResult $queryResult;

    /** @api */
    public function __construct(array $data, string $rawText = '', ?QueryResult $queryResult = null)
    {
        $this->data = $data;
        $this->rawText = $rawText;
        $this->queryResult = $queryResult;
    }

    /** @api */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /** @api */
    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /** @api */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @api */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @api */
    public function __toString(): string
    {
        return $this->toJson(JSON_PRETTY_PRINT);
    }

    /** @api */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    /** @api */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    /** @api */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('StructuredResult is immutable.');
    }

    /** @api */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('StructuredResult is immutable.');
    }
}
