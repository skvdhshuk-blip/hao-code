<?php

namespace HaoCode\Services\Agent;

/**
 * Agent 核心链路内部使用的强类型工具调用。
 *
 * @internal
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
        public string $rawInput = '',
        public ?string $inputError = null,
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
