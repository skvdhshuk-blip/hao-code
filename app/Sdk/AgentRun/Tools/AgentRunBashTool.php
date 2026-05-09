<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class AgentRunBashTool extends AgentRunSandboxTool
{
    /** @var array<string, string> */
    private static array $sessionWorkingDirectories = [];

    public function name(): string { return 'Bash'; }

    public function description(): string
    {
        return <<<DESC
Executes a shell command inside the Alibaba Cloud AgentRun sandbox, not on the PHP host server.
The working directory persists between commands for this HaoCode session.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'Command to execute inside the sandbox.'],
                'description' => ['type' => 'string', 'description' => 'Short description of what this command does.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout in milliseconds. Data API may cap command duration.'],
                'run_in_background' => ['type' => 'boolean', 'description' => 'Not supported by the REST command endpoint.'],
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
            return ToolResult::error('AgentRun REST sandbox does not support background commands. Run a foreground command instead.');
        }
        if ($context->isAborted()) {
            return ToolResult::error('Command interrupted by user.', ['exitCode' => 130, 'aborted' => true]);
        }

        $cwd = self::$sessionWorkingDirectories[$context->sessionId] ?? $this->remoteCwd($context);
        $marker = '__HAOCODE_SANDBOX_CWD__'.bin2hex(random_bytes(6)).'__';
        $command = 'cd '.$this->shellQuote($cwd).' && { '.$input['command']."; }\n"
            ."printf '\\n{$marker}%s' \"\$PWD\"\n";
        $timeoutSeconds = isset($input['timeout']) ? max(1, (int) ceil(((int) $input['timeout']) / 1000)) : 30;

        try {
            $result = $this->client->cmd($command, $cwd, $timeoutSeconds);
        } catch (\Throwable $e) {
            return ToolResult::error('Sandbox command failed: '.$e->getMessage());
        }

        $output = $this->formatCommandResult($result);
        $pos = strrpos($output, $marker);
        if ($pos !== false) {
            $newCwd = trim(substr($output, $pos + strlen($marker)));
            $output = rtrim(substr($output, 0, $pos));
            if ($newCwd !== '') {
                self::$sessionWorkingDirectories[$context->sessionId] = $this->normalizeRemotePath($newCwd);
            }
        }

        return ToolResult::success($output === '' ? '(no output)' : $output, ['sandbox' => 'agentrun']);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        return trim((string) ($input['command'] ?? '')) === '' ? 'command must not be empty.' : null;
    }

    public function isReadOnly(array $input): bool
    {
        $command = trim((string) ($input['command'] ?? ''));

        return preg_match('/^(pwd|ls|find|grep|rg|cat|head|tail|sed -n|wc|du|stat)\b/', $command) === 1;
    }

    public function isSearchOrReadCommand(array $input): array
    {
        $command = trim((string) ($input['command'] ?? ''));
        $isSearch = preg_match('/\b(rg|grep|find)\b/', $command) === 1;
        $isRead = preg_match('/\b(cat|head|tail|sed -n|wc|ls|pwd|stat)\b/', $command) === 1;

        return ['isSearch' => $isSearch, 'isRead' => $isRead, 'isList' => str_starts_with($command, 'ls') || str_starts_with($command, 'find')];
    }
}
