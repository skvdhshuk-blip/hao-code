<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class AgentRunReadTool extends AgentRunSandboxTool
{
    public function name(): string { return 'Read'; }

    public function description(): string
    {
        return 'Reads a file from the Alibaba Cloud AgentRun sandbox filesystem. Relative paths are resolved against the sandbox working directory.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Sandbox file path to read.'],
                'offset' => ['type' => 'integer', 'description' => 'The line number to start reading from (1-based).'],
                'limit' => ['type' => 'integer', 'description' => 'The number of lines to read.'],
            ],
            'required' => ['file_path'],
        ], [
            'file_path' => 'required|string',
            'offset' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $path = $input['file_path'];
        $offset = (int) ($input['offset'] ?? 1);
        $limit = (int) ($input['limit'] ?? 2000);

        try {
            $result = $this->client->readFile($path);
        } catch (\Throwable $e) {
            return ToolResult::error("Sandbox file does not exist or is not readable: {$path}. {$e->getMessage()}");
        }

        $content = $this->extractContent($result);
        $lines = preg_split('/\R/', $content) ?: [];
        $total = count($lines);
        $selected = array_slice($lines, max(0, $offset - 1), $limit);

        $out = "File: {$path} ({$total} lines total, AgentRun sandbox)\n";
        if ($offset > 1 || $limit < $total) {
            $end = $offset + count($selected) - 1;
            $out .= "Lines {$offset}-{$end}\n";
        }
        $out .= str_repeat('-', 60)."\n";
        foreach ($selected as $i => $line) {
            $out .= sprintf("%6d\t%s\n", $offset + $i, $line);
        }

        $context->recordFileRead($path, $content, $offset, $limit, $offset > 1 || $limit < $total);

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

    public function isReadOnly(array $input): bool { return true; }
    public function maxResultSizeChars(): int { return PHP_INT_MAX; }
    public function getActivityDescription(array $input): ?string { return 'Reading sandbox '.basename($input['file_path'] ?? 'file'); }
    public function isSearchOrReadCommand(array $input): array { return ['isSearch' => false, 'isRead' => true, 'isList' => false]; }

    private function extractContent(array $result): string
    {
        foreach (['content', 'data.content', 'result.content', 'text', 'data'] as $path) {
            $value = $this->scalarValue($result, [$path]);
            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
