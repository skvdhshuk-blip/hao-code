<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Run\RunJournal;

/** Owns durable run snapshot construction and restoration. @internal */
final class AgentSnapshotCoordinator
{
    /** @return array<string, mixed> */
    public function build(
        int $turnCount,
        int $maxTurns,
        string $cwd,
        ?AgentRunContext $runContext,
        array $allowedTools,
        ToolOrchestrator $orchestrator,
        ?string $baseModel,
        float $estimatedCost,
        int $totalInputTokens,
        int $totalOutputTokens,
        int $totalCacheCreationTokens,
        int $totalCacheReadTokens,
        int $lastTurnInputTokens,
        ?SandboxRuntime $sandboxRuntime,
        ?RunJournal $runJournal,
    ): array {
        $snapshot = AgentRunSnapshotBuilder::build(
            turnCount: $turnCount,
            maxTurns: $maxTurns,
            cwd: $cwd,
            runContext: $runContext,
            allowedTools: $allowedTools,
            activeSkillAllowedTools: $orchestrator->getActiveSkillAllowedTools(),
            activeSkillModelOverride: $orchestrator->getActiveSkillModelOverride(),
            activeSkillContext: $orchestrator->getActiveSkillContext(),
            baseModel: $baseModel,
            estimatedCost: $estimatedCost,
            totalInputTokens: $totalInputTokens,
            totalOutputTokens: $totalOutputTokens,
            totalCacheCreationTokens: $totalCacheCreationTokens,
            totalCacheReadTokens: $totalCacheReadTokens,
            lastTurnInputTokens: $lastTurnInputTokens,
            sandboxRuntime: $sandboxRuntime,
        );
        if ($runJournal?->invocationId() !== null) {
            $snapshot['run_invocation_id'] = $runJournal->invocationId();
        }

        return $snapshot;
    }

    /**
     * @return array{input: int, output: int, cache_creation: int, cache_read: int, last_input: int}
     */
    public function restore(
        array $snapshot,
        ?RunJournal $runJournal,
        ToolOrchestrator $orchestrator,
        CostTracker $costTracker,
        ?AgentRunContext $runContext,
    ): array {
        if (is_string($snapshot['run_invocation_id'] ?? null)
            && trim($snapshot['run_invocation_id']) !== '') {
            $runJournal?->restoreInvocation($snapshot['run_invocation_id']);
        }
        if (is_array($snapshot['allowed_tools'] ?? null)) {
            $orchestrator->setResumeAllowedTools($snapshot['allowed_tools']);
        }
        $orchestrator->restoreSkillScope(
            is_array($snapshot['active_skill_allowed_tools'] ?? null)
                ? $snapshot['active_skill_allowed_tools']
                : null,
            is_string($snapshot['active_skill_model_override'] ?? null)
                ? $snapshot['active_skill_model_override']
                : null,
            is_string($snapshot['active_skill_context'] ?? null)
                ? $snapshot['active_skill_context']
                : null,
        );
        $metrics = [
            'input' => max(0, (int) ($snapshot['total_input_tokens'] ?? 0)),
            'output' => max(0, (int) ($snapshot['total_output_tokens'] ?? 0)),
            'cache_creation' => max(0, (int) ($snapshot['total_cache_creation_tokens'] ?? 0)),
            'cache_read' => max(0, (int) ($snapshot['total_cache_read_tokens'] ?? 0)),
            'last_input' => max(0, (int) ($snapshot['last_turn_input_tokens'] ?? 0)),
        ];
        if (is_numeric($snapshot['estimated_cost_usd'] ?? null)) {
            $costTracker->setTotalCost(max(0.0, (float) $snapshot['estimated_cost_usd']));
        }
        $accumulator = $runContext?->usageAccumulator;
        if ($accumulator !== null
            && $accumulator->getInputTokens() === 0
            && $accumulator->getOutputTokens() === 0
            && $accumulator->getCacheCreationTokens() === 0
            && $accumulator->getCacheReadTokens() === 0
            && array_sum(array_slice($metrics, 0, 4)) > 0) {
            $accumulator->add(
                $metrics['input'],
                $metrics['output'],
                $metrics['cache_creation'],
                $metrics['cache_read'],
            );
        }

        return $metrics;
    }
}
