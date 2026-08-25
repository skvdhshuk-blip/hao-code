<?php

namespace HaoCode\Tools\Task;

use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TaskUpdateTool extends BaseTool
{
    public function __construct(private readonly TaskManager $taskManager) {}

    public function name(): string { return 'TaskUpdate'; }

    public function description(): string
    {
        return 'Update a task status and optional result.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Task ID'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed'], 'description' => 'New status'],
                'result' => ['type' => 'string', 'description' => 'Optional result/output text'],
            ],
            'required' => ['id', 'status'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $task = $this->taskManager->update(
            id: $input['id'],
            status: $input['status'],
            result: $input['result'] ?? null,
        );

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        return ToolResult::success("Task {$task->id} updated: status={$task->status}");
    }

    public function isReadOnly(array $input): bool { return false; }
}
