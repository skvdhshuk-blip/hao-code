<?php

namespace HaoCode\Tools\Team;

use HaoCode\Services\Agent\TeamResultCollector;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TeamCollectTool extends BaseTool
{
    public function __construct(private readonly TeamResultCollector $collector) {}

    public function name(): string { return 'TeamCollect'; }

    public function description(): string
    {
        return 'Collect the complete, structured results and errors from every member of a team.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'Team name']],
            'required' => ['name'],
        ], ['name' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $result = $this->collector->collect($input['name']);
        if ($result === null) {
            return ToolResult::error("Team not found: {$input['name']}");
        }

        return ToolResult::success(
            (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $result['summary'],
        );
    }

    public function isReadOnly(array $input): bool { return true; }
}
