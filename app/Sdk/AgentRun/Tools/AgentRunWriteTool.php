<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Services\Security\SecretScanner;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class AgentRunWriteTool extends AgentRunSandboxTool
{
    public function name(): string { return 'Write'; }

    public function description(): string
    {
        return 'Writes a file inside the Alibaba Cloud AgentRun sandbox filesystem. It never writes to the PHP host filesystem.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Sandbox file path to write.'],
                'content' => ['type' => 'string', 'description' => 'Content to write.'],
            ],
            'required' => ['file_path', 'content'],
        ], [
            'file_path' => 'required|string',
            'content' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $path = $input['file_path'];
        $content = (string) $input['content'];

        $exists = false;
        try {
            $this->client->stat($path);
            $exists = true;
        } catch (\Throwable) {
            $exists = false;
        }

        if ($exists && ! $context->wasFileRead($path)) {
            return ToolResult::error("Read tool first: {$path} already exists in sandbox and must be read before overwriting.");
        }

        try {
            $this->client->writeFile($path, $content);
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to write sandbox file {$path}: {$e->getMessage()}");
        }

        $context->recordFileRead($path, $content, 1, null, false);
        $lines = substr_count($content, "\n") + ($content !== '' ? 1 : 0);
        $bytes = strlen($content);
        $out = 'Successfully '.($exists ? 'updated' : 'created')." sandbox file {$path} ({$lines} lines, {$bytes} bytes)";

        $secrets = (new SecretScanner())->scan($content);
        if ($secrets !== []) {
            $types = array_unique(array_map(fn (array $s): string => (string) $s['type'], $secrets));
            $out .= "\n\nWARNING: Potential secrets detected: ".implode(', ', $types).'.';
        }

        return ToolResult::success($out);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $path = trim((string) ($input['file_path'] ?? ''));
        if ($path === '') {
            return 'file_path must not be empty.';
        }
        if ($this->isBareLineReference($path)) {
            return 'file_path must include an actual path, not only a line reference like ":12".';
        }

        return null;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $input['file_path'] = $this->resolveRemotePath((string) $input['file_path'], $context);
        }

        return $input;
    }

    public function isReadOnly(array $input): bool { return false; }
    public function getActivityDescription(array $input): ?string { return 'Writing sandbox '.basename($input['file_path'] ?? 'file'); }
}
