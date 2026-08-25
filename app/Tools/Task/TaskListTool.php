<?php

namespace HaoCode\Tools\Task;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TaskListTool extends BaseTool
{
    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
    ) {}

    public function name(): string { return 'TaskList'; }

    public function description(): string
    {
        return 'List all tasks, optionally filtered by status.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Filter by status: pending, in_progress, completed'],
            ],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $tasks = $this->taskManager->list($input['status'] ?? null);

        if (empty($tasks)) {
            return ToolResult::success('No tasks found.');
        }

        $lines = ["Tasks (" . count($tasks) . "):"];
        $backgroundAgents = $this->backgroundAgentManager;
        foreach ($tasks as $task) {
            $age = time() - $task->createdAt;
            $status = $task->status;
            $details = [];
            $agent = $backgroundAgents->get($task->id);
            if ($agent !== null) {
                $details[] = 'agent:' . ($agent['status'] ?? 'unknown');
                $pending = (int) ($agent['pending_messages'] ?? 0);
                if ($pending > 0) {
                    $details[] = $pending . ' msg queued';
                }
                if (! empty($agent['stop_requested'])) {
                    $details[] = 'stop requested';
                }
            }

            $suffix = $details === [] ? '' : ' · ' . implode(' · ', $details);
            $lines[] = "  {$task->id} [{$status}] {$task->subject} ({$age}s{$suffix})";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool { return true; }
}
