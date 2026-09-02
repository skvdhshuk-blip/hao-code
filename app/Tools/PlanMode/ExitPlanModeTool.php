<?php

namespace HaoCode\Tools\PlanMode;

use HaoCode\Services\Permissions\PermissionMode;
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
        return 'Signal that the implementation plan is complete. Reads the plan from the plan file, '
            .'or from the optional plan argument. Depending on how the host is configured the plan is '
            .'either approved, and the permission mode switches from plan to default so implementation '
            .'can begin, or the run ends with the plan handed back for review.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'plan' => [
                    'type' => 'string',
                    'description' => 'The full plan text. Optional when the plan file already holds it; '
                        .'when given it is saved to the plan file and used as the final plan.',
                ],
            ],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $runContext = $context->runContext;
        if ($runContext !== null && $runContext->settings->getPermissionMode() !== PermissionMode::Plan) {
            return ToolResult::error(
                'ExitPlanMode is only available in plan mode (current permission mode: '
                .$runContext->settings->getPermissionMode()->value.').',
            );
        }
        if ($runContext !== null && $runContext->readOnly) {
            return ToolResult::error('This agent is read-only; ExitPlanMode is unavailable.');
        }

        $plan = $this->resolvePlan($input, $context);
        if ($plan === '') {
            $path = $context->planFilePath;

            return ToolResult::error(
                $path === null
                    ? 'No plan found. Pass the full plan as the `plan` argument, then call ExitPlanMode again.'
                    : "No plan found. Write the plan to {$path} (or pass it as the `plan` argument), "
                        .'then call ExitPlanMode again.',
            );
        }

        $mode = $runContext?->planExitMode ?? 'return';
        if ($mode === 'return') {
            return $this->handBackToHost($plan, $context);
        }

        // Reaching call() under 'approval' means a human already approved: a
        // configured interrupt always gates the tool before it executes.
        $runContext?->settings->set('permission_mode', 'default');
        $context->turnInjections?->push($this->approvedPlanNotice($plan));

        return ToolResult::success(
            'Plan approved. The permission mode is now `default`; implement the plan now.',
        );
    }

    private function handBackToHost(string $plan, ToolUseContext $context): ToolResult
    {
        $queue = $context->turnInjections;
        if ($queue === null) {
            // No loop to end (a direct tool invocation): return the plan itself so
            // the caller still gets it.
            return ToolResult::success("Plan recorded.\n\n".$plan);
        }

        $queue->requestTermination('plan_ready', $plan);
        $chars = mb_strlen($plan);

        return ToolResult::success(
            "Plan recorded ({$chars} characters). This run ends now with termination reason "
            .'`plan_ready` so the host can review the plan. Do not start implementing.',
        );
    }

    /** @param array<string, mixed> $input */
    private function resolvePlan(array $input, ToolUseContext $context): string
    {
        $plan = trim((string) ($input['plan'] ?? ''));
        $path = $context->planFilePath;

        if ($plan !== '') {
            if (is_string($path) && $path !== '') {
                $directory = dirname($path);
                if (is_dir($directory) || @mkdir($directory, 0755, true) || is_dir($directory)) {
                    @file_put_contents($path, $plan);
                }
            }

            return $plan;
        }

        if (is_string($path) && $path !== '' && is_file($path)) {
            return trim((string) file_get_contents($path));
        }

        return '';
    }

    private function approvedPlanNotice(string $plan): string
    {
        return "# Approved plan\n\n"
            ."The following plan was approved. Plan mode has ended and the permission mode is now "
            ."`default`. Implement it now, step by step, verifying as you go.\n\n"
            ."<plan>\n{$plan}\n</plan>";
    }

    public function isReadOnly(array $input): bool
    {
        // Must stay read-only or plan mode would deny the tool that ends plan mode.
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        // Switches the permission mode and writes the plan file; never fork it.
        return false;
    }
}
