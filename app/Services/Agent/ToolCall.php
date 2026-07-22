<?php

namespace HaoCode\Services\Agent;

/**
 * Agent 核心链路内部使用的强类型工具调用。
 *
 * @internal
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        public readonly string $rawInput = '',
        public readonly ?string $inputError = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input,
            'raw_input' => $this->rawInput,
            'input_json_error' => $this->inputError,
        ];
    }
}
