<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolResult;

trait StreamingToolExecutorAbortedResultConcern
{

    private function abortedResult(array $block): array
    {
        return [
            'tool_use_id' => $block['id'],
            'content' => 'Tool execution aborted',
            'is_error' => true,
        ];
    }

    private function timedOutResult(array $block): array
    {
        return [
            'tool_use_id' => $block['id'],
            'content' => 'Tool execution timed out.',
            'is_error' => true,
        ];
    }

    private function resultArrayToToolResult(array $result): ToolResult
    {
        return new ToolResult(
            output: (string) ($result['content'] ?? ''),
            isError: (bool) ($result['is_error'] ?? false),
        );
    }
}
