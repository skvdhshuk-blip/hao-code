<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class AgentRunGlobTool extends AgentRunSandboxTool
{
    public function name(): string { return 'Glob'; }

    public function description(): string
    {
        return 'Finds files by glob pattern inside the Alibaba Cloud AgentRun sandbox filesystem.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Glob pattern, for example **/*.php.'],
                'path' => ['type' => 'string', 'description' => 'Sandbox directory to search. Defaults to sandbox working directory.'],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = (string) $input['pattern'];
        $path = (string) ($input['path'] ?? $this->remoteCwd($context));
        $script = <<<'PY'
import fnmatch, os, sys
root=sys.argv[1]
pattern=sys.argv[2].lstrip('./')
matches=[]
for base, dirs, files in os.walk(root):
    dirs[:] = [d for d in dirs if d not in {'.git', 'node_modules', 'vendor'}]
    for name in files:
        full=os.path.join(base,name)
        rel=os.path.relpath(full, root)
        if fnmatch.fnmatch(rel, pattern) or fnmatch.fnmatch(name, pattern):
            matches.append((os.path.getmtime(full), rel))
matches.sort(reverse=True)
print(f"Found {len(matches)} file(s) matching '{pattern}':")
for _, rel in matches[:100]:
    print('  '+rel)
if len(matches)>100:
    print(f"[{len(matches)-100} more files not shown. Narrow your pattern to see more.]")
PY;
        $command = 'python3 -c '.$this->shellQuote($script).' '.$this->shellQuote($path).' '.$this->shellQuote($pattern);

        try {
            $result = $this->client->cmd($command, $path, 30);
        } catch (\Throwable $e) {
            return ToolResult::error('Sandbox glob failed: '.$e->getMessage());
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
    public function getActivityDescription(array $input): ?string { return 'Finding sandbox files'; }
    public function isSearchOrReadCommand(array $input): array { return ['isSearch' => true, 'isRead' => false, 'isList' => true]; }
}
