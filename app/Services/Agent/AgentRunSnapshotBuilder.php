<?php

namespace HaoCode\Services\Agent;

/**
 * Builds the serializable state needed to resume an interrupted agent run.
 *
 * @internal
 */
final class AgentRunSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        int $turnCount,
        int $maxTurns,
        ?string $cwd,
        ?AgentRunContext $runContext,
        array $allowedTools,
        ?array $activeSkillAllowedTools,
        ?string $activeSkillModelOverride,
        string $activeSkillContext,
        float $estimatedCost,
        int $totalInputTokens,
        int $totalOutputTokens,
        int $totalCacheCreationTokens,
        int $totalCacheReadTokens,
        int $lastTurnInputTokens,
        ?\HaoCode\Sdk\Sandbox\SandboxRuntime $sandboxRuntime,
    ): array {
        return [
            'cwd' => $cwd,
            'project_directory' => $runContext?->projectDirectory,
            'worktree_path' => $runContext?->worktreePath,
            'worktree_branch' => $runContext?->worktreeBranch,
            'managed_worktree' => $runContext?->managedWorktree ?? false,
            'background_owner_agent_id' => $runContext?->backgroundOwnerAgentId,
            'model' => $runContext?->settings->getModel(),
            'system_prompt' => $runContext?->settings->getSystemPrompt(),
            'append_system_prompt' => $runContext?->settings->getAppendSystemPrompt(),
            'omit_project_instructions' => $runContext?->omitProjectInstructions ?? false,
            'agent_type' => $runContext?->agentType,
            'read_only' => $runContext?->readOnly ?? false,
            'max_turns_remaining' => max(1, $maxTurns - $turnCount),
            'allowed_tools' => $allowedTools,
            'active_skill_allowed_tools' => $activeSkillAllowedTools,
            'active_skill_model_override' => $activeSkillModelOverride,
            'active_skill_context' => $activeSkillContext,
            // Prefer shared tree totals so nested AgentAsTool usage survives HITL resume.
            'estimated_cost_usd' => $estimatedCost,
            'budget_ledger_id' => $runContext?->budgetLedger?->getId(),
            'budget_limit_usd' => $runContext?->budgetLedger?->getLimit(),
            'total_input_tokens' => $totalInputTokens,
            'total_output_tokens' => $totalOutputTokens,
            'total_cache_creation_tokens' => $totalCacheCreationTokens,
            'total_cache_read_tokens' => $totalCacheReadTokens,
            'last_turn_input_tokens' => $lastTurnInputTokens,
            'sandbox_lease' => $sandboxRuntime?->exportLease(),
        ];
    }
}
