<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxGrepTool extends SandboxTool
{
    public function name(): string { return 'Grep'; }

    public function description(): string
    {
        return 'Searches for text or regex matches inside files in the configured HaoCode sandbox filesystem.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Regex pattern to search for.'],
                'path' => ['type' => 'string', 'description' => 'Sandbox file or directory to search.'],
                'glob' => ['type' => 'string', 'description' => 'Optional glob filter.'],
                'output_mode' => ['type' => 'string', 'enum' => ['content', 'files_with_matches', 'count']],
                '-i' => ['type' => 'boolean', 'description' => 'Case-insensitive search.'],
                'head_limit' => ['type' => 'integer', 'description' => 'Maximum results.'],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
            'glob' => 'nullable|string',
            'output_mode' => 'nullable|string|in:content,files_with_matches,count',
            '-i' => 'nullable|boolean',
            'head_limit' => 'nullable|integer|min:0',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = (string) $input['pattern'];
        $path = $input['path'] ?? $context->workingDirectory;
        $mode = $input['output_mode'] ?? 'files_with_matches';
        $limit = (int) ($input['head_limit'] ?? 250);
        $limit = max(0, min($limit, 1000));
        if ($context->isAborted()) {
            return ToolResult::aborted('Sandbox Grep search aborted.');
        }

        $this->beginSearchMetadata();
        try {
            $matches = $this->runtime->backend->grep(
                $pattern,
                (string) $path,
                isset($input['glob']) ? (string) $input['glob'] : null,
                (bool) ($input['-i'] ?? false),
                $limit,
            );
        } catch (\Throwable $e) {
            $this->consumeSearchMetadata();
            return ToolResult::error('Grep search failed: '.$e->getMessage());
        }
        $metadata = $this->consumeSearchMetadata();

        if ($matches === []) {
            return ToolResult::success("No matches found for pattern: {$pattern}", $metadata);
        }

        if ($mode === 'count') {
            $counts = [];
            foreach ($matches as $match) {
                $counts[$match['file']] = ($counts[$match['file']] ?? 0) + 1;
            }
            $output = '';
            foreach ($counts as $file => $count) {
                $output .= "{$file}:{$count}\n";
            }
            return ToolResult::success(rtrim($output), $metadata);
        }

        if ($mode === 'files_with_matches') {
            return ToolResult::success(
                implode("\n", array_values(array_unique(array_map(fn (array $m): string => $m['file'], $matches)))),
                $metadata,
            );
        }

        $output = '';
        foreach ($matches as $match) {
            $output .= "{$match['file']}:{$match['line']}:{$match['text']}\n";
        }

        return ToolResult::success(rtrim($output), $metadata);
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['path'])) {
            $input['path'] = $this->resolveRemotePath((string) $input['path'], $context);
        }
        return $input;
    }

    public function isReadOnly(array $input): bool { return true; }
    public function getActivityDescription(array $input): ?string { return 'Searching sandbox for '.($input['pattern'] ?? 'pattern'); }
    public function isSearchOrReadCommand(array $input): array { return ['isSearch' => true, 'isRead' => false, 'isList' => false]; }
}
