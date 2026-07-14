<?php

namespace HaoCode\Tools\Memory;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class MemoryWriteTool extends BaseTool
{
    public function __construct(
        private readonly MemoryStoreInterface $memoryStore,
    ) {}

    public function name(): string
    {
        return 'MemoryWrite';
    }

    public function description(): string
    {
        return 'Store or update durable long-term memory. Use only when the user explicitly asks to remember or update something.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Stable key identifying the memory.'],
                'value' => ['type' => 'string', 'description' => 'Durable information to remember. Never include credentials or secrets.'],
                'type' => ['type' => 'string', 'description' => 'Optional category, such as note, preference, decision, or workflow.'],
            ],
            'required' => ['key', 'value'],
        ], [
            'key' => 'required|string',
            'value' => 'required|string',
            'type' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $this->memoryStore->write($input['key'], $input['value'], $input['type'] ?? 'note');

        return ToolResult::success("Memory '{$input['key']}' stored.");
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
