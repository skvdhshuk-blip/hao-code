<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class AgentRunGrepTool extends AgentRunSandboxTool
{
    public function name(): string { return 'Grep'; }

    public function description(): string
    {
        return 'Searches for text or regex matches inside files in the Alibaba Cloud AgentRun sandbox filesystem.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Regex pattern to search for.'],
                'path' => ['type' => 'string', 'description' => 'Sandbox file or directory to search. Defaults to sandbox working directory.'],
                'glob' => ['type' => 'string', 'description' => 'Glob pattern to filter files.'],
                'output_mode' => ['type' => 'string', 'enum' => ['content', 'files_with_matches', 'count']],
                '-i' => ['type' => 'boolean'],
                'head_limit' => ['type' => 'integer'],
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
        $path = $input['path'] ?? $this->remoteCwd($context);
        $mode = $input['output_mode'] ?? 'files_with_matches';
        $headLimit = (int) ($input['head_limit'] ?? 250);
        $glob = $input['glob'] ?? null;

        $cmd = ['rg', '--no-heading'];
        if (($input['-i'] ?? false) === true) {
            $cmd[] = '-i';
        }
        if ($mode === 'count') {
            $cmd[] = '--count';
        } elseif ($mode === 'files_with_matches') {
            $cmd[] = '-l';
        } else {
            $cmd[] = '--line-number';
        }
        if ($headLimit > 0) {
            $cmd[] = '--max-count='.$headLimit;
        }
        if (is_string($glob) && $glob !== '') {
            $cmd[] = '--glob='.$this->shellQuote($glob);
        }
        $cmd[] = '--';
        $cmd[] = $this->shellQuote($pattern);
        $cmd[] = $this->shellQuote((string) $path);
        $rg = implode(' ', $cmd);
        $fallback = 'grep -RIn '.(($input['-i'] ?? false) ? '-i ' : '').$this->shellQuote($pattern).' '.$this->shellQuote((string) $path).' | head -n '.max(1, $headLimit);
        $noMatches = 'No matches found for pattern: '.$pattern;
        $command = '('.$rg.') 2>/tmp/haocode_rg_err || '
            .'(code=$?; if [ "$code" -eq 1 ]; then echo '.$this->shellQuote($noMatches)
            .'; elif command -v grep >/dev/null 2>&1; then '.$fallback
            .'; else cat /tmp/haocode_rg_err; fi)';

        try {
            $result = $this->client->cmd($command, $this->remoteCwd($context), 30);
        } catch (\Throwable $e) {
            return ToolResult::error('Sandbox grep failed: '.$e->getMessage());
        }

        return ToolResult::success($this->formatCommandResult($result));
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
