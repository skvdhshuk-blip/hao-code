<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxReadTool extends SandboxTool
{
    public function name(): string { return 'Read'; }

    public function description(): string
    {
        return 'Reads a file from the configured HaoCode sandbox filesystem. Relative paths are resolved inside the sandbox working directory.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Sandbox file path to read.'],
                'offset' => ['type' => 'integer', 'description' => 'Line number to start from, 1-based.'],
                'limit' => ['type' => 'integer', 'description' => 'Number of lines to read.'],
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
            $content = $this->runtime->backend->readFile($path);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $total = count($lines);
        if ($offset > $total && $total > 0) {
            return ToolResult::error("Offset {$offset} exceeds file length ({$total} lines).");
        }
        $selected = array_slice($lines, max(0, $offset - 1), $limit);
        $isPartial = $offset > 1 || $limit < $total;

        $output = "File: {$path} ({$total} lines total, sandbox)\n";
        if ($isPartial) {
            $end = $offset + count($selected) - 1;
            $output .= "Lines {$offset}-{$end}\n";
        }
        $output .= str_repeat('-', 60)."\n";
        foreach ($selected as $i => $line) {
            $output .= sprintf("%6d\t%s\n", $offset + $i, $line);
        }

        $context->recordFileRead($path, $content, $offset, $limit, $isPartial);

        return ToolResult::success($output);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $path = trim((string) ($input['file_path'] ?? ''));
        if ($path === '') return 'file_path must not be empty.';
        if ($this->isBareLineReference($path)) return 'file_path must include an actual path, not only a line reference like ":12".';
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
}
