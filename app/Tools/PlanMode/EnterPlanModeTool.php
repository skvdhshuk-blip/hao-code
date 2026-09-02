<?php

namespace HaoCode\Tools\PlanMode;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class EnterPlanModeTool extends BaseTool
{
    public function name(): string
    {
        return 'EnterPlanMode';
    }

    public function description(): string
    {
        return 'Enter plan mode. In plan mode, the agent explores the codebase and designs an implementation approach without making changes. Use this when you need to plan before implementing.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => (object) [],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        // The mode itself is enforced by the permission system; this only tells the
        // model where to draft, since the plan file is the one path it may write.
        $path = $context->planFilePath;
        $where = is_string($path) && $path !== ''
            ? "Write and refine the plan in {$path}, the only file you may modify in plan mode. "
            : 'Keep the plan in your response, then pass it as the `plan` argument of ExitPlanMode. ';

        return ToolResult::success(
            'Entering plan mode. Explore the codebase and design an implementation plan without '
            .'making changes. '.$where
            .'Call ExitPlanMode when the plan is complete.',
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
