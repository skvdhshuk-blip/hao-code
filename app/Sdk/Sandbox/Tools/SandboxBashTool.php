<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxBashTool extends SandboxTool
{
    public function name(): string { return 'Bash'; }

    public function description(): string
    {
        return 'Executes a foreground shell command inside the configured HaoCode sandbox, not on the host project cwd.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'Foreground command to execute in the sandbox.'],
                'description' => ['type' => 'string', 'description' => 'Short description of the command.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout in milliseconds, max 600000.'],
                'run_in_background' => ['type' => 'boolean', 'description' => 'Background commands are not supported in sandbox.'],
            ],
            'required' => ['command'],
        ], [
            'command' => 'required|string',
            'description' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1000|max:600000',
            'run_in_background' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        if (($input['run_in_background'] ?? false) === true) {
            return ToolResult::error('Sandbox Bash does not support background commands.');
        }
        if ($context->isAborted()) {
            return ToolResult::error('Command interrupted by user.', ['exitCode' => 130, 'aborted' => true]);
        }

        try {
            $result = $this->runtime->backend->exec(
                (string) $input['command'],
                $context->workingDirectory,
                (int) ($input['timeout'] ?? 120000),
                $context->shouldAbort,
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        if (($result['aborted'] ?? false) === true) {
            return ToolResult::error('Command interrupted by user.', [
                'exitCode' => 130,
                'aborted' => true,
            ]);
        }

        $output = '';
        if ($result['stdout'] !== '') $output .= $result['stdout'];
        if ($result['stderr'] !== '') $output .= ($output !== '' ? "\n" : '')."STDERR:\n".$result['stderr'];
        if ($output === '') $output = '(no output)';
        if ($result['timedOut']) $output .= "\n\nCommand timed out.";

        return $result['exitCode'] === 0 && ! $result['timedOut']
            ? ToolResult::success($output, ['exitCode' => $result['exitCode']])
            : ToolResult::error($output, ['exitCode' => $result['exitCode'], 'timedOut' => $result['timedOut']]);
    }

    public function isReadOnly(array $input): bool { return false; }
    public function getActivityDescription(array $input): ?string { return 'Running sandbox command'; }
}
