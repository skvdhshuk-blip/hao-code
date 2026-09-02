<?php

declare(strict_types=1);

namespace HaoCode\Tools\Bash;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Fetch the output of a background Bash command on demand.
 *
 * Completed background commands are normally pushed to the model at the next turn
 * boundary. This tool covers the two cases the push does not: a command that is
 * still running, and one whose output was too large to inline in the notice.
 */
class BashOutputTool extends BaseTool
{
    public function name(): string
    {
        return 'BashOutput';
    }

    public function description(): string
    {
        return <<<'DESC'
Retrieve the output of a background Bash command started with run_in_background.

Returns "still running" while the command runs. Once it has finished, the output is
returned exactly once: a result that was already delivered to you automatically is no
longer retrievable here, so read the delivered text instead of calling this again.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'string',
                    'description' => 'The background task id reported when the command was started (for example "bg_1a2b3c4d")',
                ],
            ],
            'required' => ['task_id'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $taskId = trim((string) ($input['task_id'] ?? ''));
        if ($taskId === '') {
            return ToolResult::error('task_id is required.');
        }

        // Scope the lookup to this session so a nested agent cannot consume output
        // its parent run is waiting for.
        $result = BashTool::checkTask($taskId, $context->sessionId);

        return $result ?? ToolResult::error("Unknown background task: {$taskId}.");
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    /**
     * Read-only with respect to the project, but not concurrency-safe: harvesting a
     * finished task unlinks its output file and drops it from the process-local
     * registry. In a forked child that bookkeeping would be lost while the file
     * disappeared for the parent, so this must run in the parent process.
     */
    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }
}
