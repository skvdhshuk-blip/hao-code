<?php

namespace HaoCode\Tools\Task;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TaskGetTool extends BaseTool
{
    public function name(): string { return 'TaskGet'; }

    public function description(): string
    {
        return 'Get details of a specific task by its ID.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The task ID'],
            ],
            'required' => ['id'],
        ], ['id' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $manager = \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class);
        $task = $manager->get($input['id']);

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        $age = time() - $task->createdAt;
        $lines = [
            "Task: {$task->id}",
            "Subject: {$task->subject}",
            "Status: {$task->status}",
            "Active: {$task->activeForm}",
            "Age: {$age}s",
        ];

        if ($task->description) {
            $lines[] = "Description: {$task->description}";
        }

        if ($task->result) {
            $lines[] = "Result: {$task->result}";
        }

        $agent = \HaoCode\Support\Runtime\SdkRuntime::app(BackgroundAgentManager::class)->refreshStatus($task->id);
        if ($agent !== null) {
            $lines[] = "Agent status: {$agent['status']}";
            if (! empty($agent['pid'])) {
                $lines[] = "PID: {$agent['pid']}";
            }
            $lines[] = "Pending messages: " . ($agent['pending_messages'] ?? 0);
            if (! empty($agent['stop_requested'])) {
                $lines[] = 'Stop requested: yes';
            }
            if (! empty($agent['error'])) {
                $lines[] = "Agent error: {$agent['error']}";
            }
            if (! empty($agent['last_result'])) {
                $lines[] = "Last response: {$agent['last_result']}";
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool { return true; }
}
