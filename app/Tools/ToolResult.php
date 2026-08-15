<?php

namespace HaoCode\Tools;

class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $isError = false,
        public readonly ?array $metadata = null,
        private readonly ?ToolOutcome $terminalOutcome = null,
        public readonly ?array $data = null,
        public readonly ?string $safeError = null,
    ) {}

    public static function success(string $output, ?array $metadata = null, ?array $data = null): self
    {
        return new self($output, false, $metadata, data: $data);
    }

    public static function error(
        string $output,
        ?array $metadata = null,
        ?array $data = null,
        ?string $safeError = null,
    ): self {
        return new self($output, true, $metadata, data: $data, safeError: $safeError ?? $output);
    }

    public static function aborted(string $output = 'Tool execution aborted', ?array $metadata = null): self
    {
        return new self(
            $output,
            true,
            $metadata,
            ToolOutcome::Aborted,
            safeError: $output,
        );
    }

    public function outcome(): ToolOutcome
    {
        return $this->terminalOutcome ?? ($this->isError ? ToolOutcome::Failed : ToolOutcome::Completed);
    }

    /**
     * Replace model-visible content without dropping outcome, metadata, or data.
     *
     * @internal
     */
    public function withOutput(string $output): self
    {
        return new self(
            $output,
            $this->isError,
            $this->metadata,
            $this->terminalOutcome,
            $this->data,
            $this->safeError,
        );
    }

    /** @internal */
    public function appendOutput(string $suffix): self
    {
        return $this->withOutput($this->output.$suffix);
    }

    /** @internal */
    public function withMetadata(?array $metadata): self
    {
        return new self(
            $this->output,
            $this->isError,
            $metadata,
            $this->terminalOutcome,
            $this->data,
            $this->safeError,
        );
    }

    /**
     * Convert to a scalar-only representation safe for process IPC.
     *
     * @internal
     *
     * @return array{output: string, is_error: bool, metadata: ?array, outcome: string, data: ?array, safe_error: ?string}
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'is_error' => $this->isError,
            'metadata' => $this->metadata,
            'outcome' => $this->outcome()->value,
            'data' => $this->data,
            'safe_error' => $this->safeError,
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
        $data = $value['data'] ?? null;
        $safeError = $value['safe_error'] ?? null;

        if (! is_string($output)
            || ! is_bool($isError)
            || ($metadata !== null && ! is_array($metadata))
            || ($data !== null && ! is_array($data))
            || ($safeError !== null && ! is_string($safeError))
            || $outcome === null) {
            throw new \InvalidArgumentException('Invalid ToolResult IPC payload.');
        }

        return new self($output, $isError, $metadata, $outcome, $data, $safeError);
    }

    /**
     * Restore the provider-shaped boundary block used by the current loop.
     *
     * @internal
     */
    public static function fromApiFormat(array $value): self
    {
        $output = $value['content'] ?? null;
        $isError = $value['is_error'] ?? false;
        if (! is_string($output) || ! is_bool($isError)) {
            throw new \InvalidArgumentException('Invalid tool result content block.');
        }

        return $isError ? self::error($output) : self::success($output);
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
