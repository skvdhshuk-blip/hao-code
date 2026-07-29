<?php

namespace HaoCode\Tools;

class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $isError = false,
        public readonly ?array $metadata = null,
        private readonly ?ToolOutcome $terminalOutcome = null,
    ) {}

    public static function success(string $output, ?array $metadata = null): self
    {
        return new self($output, false, $metadata);
    }

    public static function error(string $output, ?array $metadata = null): self
    {
        return new self($output, true, $metadata);
    }

    public static function aborted(string $output = 'Tool execution aborted', ?array $metadata = null): self
    {
        return new self($output, true, $metadata, ToolOutcome::Aborted);
    }

    public function outcome(): ToolOutcome
    {
        return $this->terminalOutcome ?? ($this->isError ? ToolOutcome::Failed : ToolOutcome::Completed);
    }

    /**
     * Convert to a scalar-only representation safe for process IPC.
     *
     * @internal
     *
     * @return array{output: string, is_error: bool, metadata: ?array, outcome: string}
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'is_error' => $this->isError,
            'metadata' => $this->metadata,
            'outcome' => $this->outcome()->value,
        ];
    }

    /**
     * Restore a result from its scalar-only IPC representation.
     *
     * @internal
     */
    public static function fromArray(array $value): self
    {
        $output = $value['output'] ?? null;
        $isError = $value['is_error'] ?? null;
        $metadata = $value['metadata'] ?? null;
        $outcome = is_string($value['outcome'] ?? null)
            ? ToolOutcome::tryFrom($value['outcome'])
            : null;

        if (! is_string($output)
            || ! is_bool($isError)
            || ($metadata !== null && ! is_array($metadata))
            || $outcome === null) {
            throw new \InvalidArgumentException('Invalid ToolResult IPC payload.');
        }

        return new self($output, $isError, $metadata, $outcome);
    }

    /**
     * Convert to the Anthropic API tool_result content block format.
     */
    public function toApiFormat(string $toolUseId): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $this->output,
            'is_error' => $this->isError,
        ];
    }
}
