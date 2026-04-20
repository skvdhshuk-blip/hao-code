<?php

namespace HaoCode\Tools\PlanMode;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class ExitPlanModeTool extends BaseTool
{
    public function name(): string
    {
        return 'ExitPlanMode';
    }

    public function description(): string
    {
        return 'Signal that planning is complete. The assistant must ask the user to run /plan off before implementation can begin.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => (object) [],
        ], []);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        return ToolResult::success(
            'Planning is complete. Ask the user to run /plan off before implementing changes.'
        );
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
