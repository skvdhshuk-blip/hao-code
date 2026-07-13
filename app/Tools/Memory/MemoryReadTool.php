<?php

namespace HaoCode\Tools\Memory;

use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Read memory entries at different summary levels.
 *
 * The system prompt shows the agent L0 summaries (compact one-liners).
 * When the agent needs more detail on a specific memory, it uses this tool
 * to fetch L1 (structured overview) or L2 (full content).
 */
class MemoryReadTool extends BaseTool
{
    public function name(): string
    {
        return 'MemoryRead';
    }

    public function description(): string
    {
        return <<<DESC
Read a specific memory entry at a chosen detail level. Use this when a memory mentioned in the system prompt needs more detail.

Levels:
- l1: Structured overview with key points (~500 tokens) — use for context
- l2: Full original content — use when you need every detail

Use the "keys" parameter to list available memory keys first.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'The memory key to read. Use "keys" to list all available keys.',
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Detail level: l1 (overview) or l2 (full content). Default: l1.',
                    'enum' => ['l1', 'l2'],
                ],
            ],
            'required' => ['key'],
        ], [
            'key' => 'required|string',
            'level' => 'nullable|string|in:l1,l2',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $key = $input['key'];
        $level = $input['level'] ?? 'l1';

        /** @var SessionMemory $memory */
        $memory = \HaoCode\Support\Runtime\SdkRuntime::app(SessionMemory::class);

        // List all keys
        if ($key === 'keys') {
            $entries = $memory->list();
            if (empty($entries)) {
                return ToolResult::success('No persistent memories stored yet.');
            }

            $lines = [];
            foreach ($entries as $k => $entry) {
                $l0 = $entry['l0'] ?? '';
                $tokens = $entry['l0_tokens'] ?? 0;
                $mode = ($entry['summary_mode'] ?? '') === 'llm' ? '' : ' [raw]';
                $lines[] = "- {$k}{$mode} ({$tokens}tk): {$l0}";
            }

            return ToolResult::success("Available memory keys:\n\n" . implode("\n", $lines));
        }

        // Read specific key at requested level
        $content = $memory->getSummary($key, $level);

        if ($content === null) {
            $available = array_keys($memory->list());

            return ToolResult::error("Memory key '{$key}' not found. Available keys: " . implode(', ', $available));
        }

        $entry = $memory->getEntry($key);
        $l2Tokens = $entry['l2_tokens'] ?? 0;
        $mode = ($entry['summary_mode'] ?? '') === 'llm' ? 'LLM-generated' : 'rule-based';

        $header = "Memory: {$key} (level: {$level}, {$l2Tokens} tokens total, summaries: {$mode})\n\n";

        return ToolResult::success($header . $content);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
