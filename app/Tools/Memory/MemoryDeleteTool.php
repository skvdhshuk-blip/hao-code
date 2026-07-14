<?php

namespace HaoCode\Tools\Memory;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class MemoryDeleteTool extends BaseTool
{
    public function __construct(
        private readonly MemoryStoreInterface $memoryStore,
    ) {}

    public function name(): string
    {
        return 'MemoryDelete';
    }

    public function description(): string
    {
        return 'Delete durable long-term memory. Use only when the user explicitly asks to forget or remove stored information.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Memory key to delete.'],
            ],
            'required' => ['key'],
        ], [
            'key' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        if (! $this->memoryStore->delete($input['key'])) {
            return ToolResult::error("Memory '{$input['key']}' not found.");
        }

        return ToolResult::success("Memory '{$input['key']}' deleted.");
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
