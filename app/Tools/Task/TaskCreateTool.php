<?php

namespace HaoCode\Tools\Task;

use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TaskCreateTool extends BaseTool
{
    public function name(): string { return 'TaskCreate'; }

    public function description(): string
    {
        return 'Create a new background task to track progress. Tasks have status (pending/in_progress/completed) and can be monitored.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'Short description of the task'],
                'activeForm' => ['type' => 'string', 'description' => 'Present continuous form (e.g. "Running tests")'],
                'description' => ['type' => 'string', 'description' => 'Detailed description'],
            ],
            'required' => ['subject', 'activeForm'],
        ], [
            'subject' => 'required|string',
            'activeForm' => 'required|string',
            'description' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $manager = \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class);
        $task = $manager->create(
            subject: $input['subject'],
            activeForm: $input['activeForm'],
            description: $input['description'] ?? null,
        );

        return ToolResult::success(
            "Created task: {$task->id}\n" .
            "Subject: {$task->subject}\n" .
            "Status: {$task->status}\n" .
            "Use TaskGet to check status or TaskUpdate to change it."
        );
    }

    public function isReadOnly(array $input): bool { return false; }
}
