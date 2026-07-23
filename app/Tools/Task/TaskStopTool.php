<?php

namespace HaoCode\Tools\Task;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TaskStopTool extends BaseTool
{
    public function name(): string { return 'TaskStop'; }

    public function description(): string
    {
        return 'Stop a running task by its ID.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The task ID to stop'],
            ],
            'required' => ['id'],
        ], ['id' => 'required|string|regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $backgroundAgentManager = \HaoCode\Support\Runtime\SdkRuntime::app(BackgroundAgentManager::class);
        try {
            $agent = $backgroundAgentManager->refreshStatus($input['id']);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (($agent['status'] ?? null) === 'waiting_for_input') {
            $sessionId = $agent['child_session_id'] ?? null;
            $interruptId = $agent['pending_interrupt']['id'] ?? null;
            if (! is_string($sessionId) || $sessionId === ''
                || ! is_string($interruptId) || $interruptId === '') {
                return ToolResult::error(
                    "Background agent {$input['id']} has an invalid pending interrupt state.",
                );
            }
            try {
                \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class)->cancelInterrupt(
                    $sessionId,
                    $interruptId,
                    'Background task stopped by user.',
                );
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to cancel pending interrupt: {$e->getMessage()}");
            }

            $message = 'Stopped by user while waiting for human input.';
            $backgroundAgentManager->markCompleted($input['id'], $message);
            $backgroundAgentManager->finalizeStoredWorktree($input['id']);
            \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class)
                ->update($input['id'], 'completed', $message);

            return ToolResult::success("Background agent {$input['id']} stopped and its pending interrupt was cancelled.");
        }

        if ($agent !== null && !in_array($agent['status'] ?? '', ['completed', 'error', 'dead'], true)) {
            $backgroundAgentManager->requestStop($input['id']);
            \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class)->transition(
                $input['id'],
                ['pending', 'in_progress'],
                'in_progress',
                'Stop requested by user.',
            );

            return ToolResult::success("Stop requested for background agent {$input['id']}.");
        }

        $manager = \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class);
        $task = $manager->stop($input['id']);

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        return ToolResult::success("Task {$task->id} stopped.");
    }

    public function isReadOnly(array $input): bool { return false; }
}
