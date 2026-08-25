<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxGlobTool extends SandboxTool
{
    public function name(): string { return 'Glob'; }

    public function description(): string
    {
        return 'Finds files by glob pattern inside the configured HaoCode sandbox filesystem.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Glob pattern, e.g. **/*.php.'],
                'path' => ['type' => 'string', 'description' => 'Sandbox directory to search. Defaults to current sandbox cwd.'],
            ],
            'required' => ['pattern'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = trim((string) $input['pattern']);
        $path = isset($input['path']) ? (string) $input['path'] : $context->workingDirectory;
        if ($context->isAborted()) {
            return ToolResult::aborted('Sandbox Glob search aborted.');
        }

        $this->beginSearchMetadata();
        try {
            $matches = $this->runtime->backend->glob($pattern, $path);
        } catch (\Throwable $e) {
            $this->consumeSearchMetadata();
            return ToolResult::error('Glob search failed: '.$e->getMessage());
        }
        $metadata = $this->consumeSearchMetadata();

        if ($matches === []) {
            if (($metadata['searchLimited'] ?? false) === true) {
                return ToolResult::success(
                    "Search was limited before a match could be confirmed for pattern: {$pattern}",
                    $metadata,
                );
            }

            return ToolResult::success("No files matched pattern: {$pattern}", $metadata);
        }

        $total = count($matches);
        $shown = array_slice($matches, 0, 100);
        // Backends may cap the returned window before the tool sees it. Keep
        // the host Glob heading and make that boundary visible without
        // inventing a total count that the backend cannot know.
        $limited = ($metadata['searchLimited'] ?? false) === true || $total >= 100;
        $output = "Found {$total} file(s) matching '{$pattern}'";
        if ($limited) {
            $output .= ' (showing first '.count($shown).')';
        }
        $output .= ":\n\n";
        foreach ($shown as $match) {
            $output .= "  {$match}\n";
        }
        if ($limited) {
            $output .= "\n[Results are bounded. Narrow your pattern to see more.]";
        }

        return ToolResult::success($output, $metadata);
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['path'])) {
            $input['path'] = $this->resolveRemotePath((string) $input['path'], $context);
        }
        return $input;
    }

    public function isReadOnly(array $input): bool { return true; }
    public function getActivityDescription(array $input): ?string { return 'Searching sandbox for '.($input['pattern'] ?? 'files'); }
    public function isSearchOrReadCommand(array $input): array { return ['isSearch' => true, 'isRead' => false, 'isList' => true]; }
}
