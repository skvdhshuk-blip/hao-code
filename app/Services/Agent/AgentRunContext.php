<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Cost\UsageAccumulator;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;

/**
 * 每次 Agent 运行独享的不可变上下文，避免 SDK 查询之间共享可变配置。
 *
 * @internal
 */
final class AgentRunContext
{
    /** @var null|\Closure(string, array, \HaoCode\Sdk\HaoCodeConfig): \HaoCode\Sdk\StructuredResult */
    public readonly ?\Closure $hitlStructuredRunner;

    public function __construct(
        public readonly string $workingDirectory,
        public readonly string $projectDirectory,
        public readonly SettingsManager $settings,
        public readonly SkillLoader $skillLoader,
        public readonly CancellationToken $cancellationToken,
        public readonly array $interruptOn = [],
        public readonly bool $enableAskUser = false,
        public readonly ?string $agentId = null,
        public readonly ?string $teamName = null,
        public readonly ?array $responseSchema = null,
        public readonly ?MemoryStoreInterface $memoryStore = null,
        public readonly bool $includeMemoryInTextOnly = false,
        public readonly array $memoryTools = [],
        public readonly string $hitlMode = 'ask',
        public readonly ?string $hitlReviewModel = null,
        public readonly ?\HaoCode\Sdk\Sandbox\SandboxConfig $sandbox = null,
        public readonly ?string $hitlAllowlistPath = null,
        public readonly bool $omitProjectInstructions = false,
        public readonly ?string $agentType = null,
        public readonly bool $readOnly = false,
        public readonly ?string $worktreePath = null,
        public readonly ?string $worktreeBranch = null,
        public readonly bool $managedWorktree = false,
        public readonly ?string $backgroundOwnerAgentId = null,
        public readonly ?BudgetLedger $budgetLedger = null,
        public readonly ?UsageAccumulator $usageAccumulator = null,
        public readonly string $contextPreset = ContextPreset::CODING,
        ?callable $hitlStructuredRunner = null,
    ) {
        ContextPreset::assertValid($this->contextPreset);
        $this->hitlStructuredRunner = $hitlStructuredRunner !== null
            ? \Closure::fromCallable($hitlStructuredRunner)
            : null;
    }

    public function fork(
        ?string $workingDirectory = null,
        ?bool $readOnly = null,
        ?array $interruptOn = null,
        ?string $agentId = null,
        ?string $teamName = null,
        ?bool $omitProjectInstructions = null,
        ?string $agentType = null,
        ?string $worktreePath = null,
        ?string $worktreeBranch = null,
        ?bool $managedWorktree = null,
        ?string $projectDirectory = null,
        ?string $backgroundOwnerAgentId = null,
        ?BudgetLedger $budgetLedger = null,
        bool $inheritAgentId = true,
        ?string $contextPreset = null,
    ): self
    {
        $readOnly ??= $this->readOnly;
        $projectDirectory ??= $this->projectDirectory;
        $settings = clone $this->settings;
        if ($readOnly) {
            $settings->set('permission_mode', 'plan');
        }
        $skillLoader = $projectDirectory === $this->projectDirectory
            ? clone $this->skillLoader
            : $this->skillLoader->forWorkingDirectory($projectDirectory);

        return new self(
            $workingDirectory ?? $this->workingDirectory,
            $projectDirectory,
            $settings,
            $skillLoader,
            $this->cancellationToken->fork(),
            $interruptOn ?? $this->interruptOn,
            $this->enableAskUser,
            $inheritAgentId ? ($agentId ?? $this->agentId) : $agentId,
            $teamName ?? $this->teamName,
            $this->responseSchema,
            $this->memoryStore,
            $this->includeMemoryInTextOnly,
            $this->memoryTools,
            $this->hitlMode,
            $this->hitlReviewModel,
            $this->sandbox,
            $this->hitlAllowlistPath,
            $omitProjectInstructions ?? $this->omitProjectInstructions,
            $agentType ?? $this->agentType,
            $readOnly,
            $worktreePath ?? $this->worktreePath,
            $worktreeBranch ?? $this->worktreeBranch,
            $managedWorktree ?? $this->managedWorktree,
            $backgroundOwnerAgentId ?? $this->backgroundOwnerAgentId,
            $budgetLedger ?? $this->budgetLedger,
            // Share the same accumulator so nested agents contribute tokens.
            $this->usageAccumulator,
            $contextPreset ?? $this->contextPreset,
            $this->hitlStructuredRunner,
        );
    }

    /**
     * Rebind this run to a shared usage ledger (AgentAsTool child assembly).
     *
     * @internal
     */
    public function withUsageAccumulator(UsageAccumulator $usageAccumulator): self
    {
        return new self(
            $this->workingDirectory,
            $this->projectDirectory,
            $this->settings,
            $this->skillLoader,
            $this->cancellationToken,
            $this->interruptOn,
            $this->enableAskUser,
            $this->agentId,
            $this->teamName,
            $this->responseSchema,
            $this->memoryStore,
            $this->includeMemoryInTextOnly,
            $this->memoryTools,
            $this->hitlMode,
            $this->hitlReviewModel,
            $this->sandbox,
            $this->hitlAllowlistPath,
            $this->omitProjectInstructions,
            $this->agentType,
            $this->readOnly,
            $this->worktreePath,
            $this->worktreeBranch,
            $this->managedWorktree,
            $this->backgroundOwnerAgentId,
            $this->budgetLedger,
            $usageAccumulator,
            $this->contextPreset,
            $this->hitlStructuredRunner,
        );
    }

    /**
     * Verify the non-expandable resource identity shared by a nested run.
     *
     * @internal
     */
    public function isChildOf(self $parent): bool
    {
        if (! $this->cancellationToken->isDescendantOf($parent->cancellationToken)) {
            return false;
        }
        if ($this->sandbox !== $parent->sandbox
            || $this->memoryStore !== $parent->memoryStore
            || $this->budgetLedger !== $parent->budgetLedger
            || $this->usageAccumulator !== $parent->usageAccumulator) {
            return false;
        }
        if ($parent->contextPreset === ContextPreset::GENERIC
            && $this->contextPreset !== ContextPreset::GENERIC) {
            return false;
        }

        return self::permissionRank($this->settings->getPermissionMode()->value)
            <= self::permissionRank($parent->settings->getPermissionMode()->value);
    }

    private static function permissionRank(string $mode): int
    {
        return match ($mode) {
            'plan' => 0,
            'default' => 1,
            'accept_edits' => 2,
            'bypass_permissions' => 3,
            default => -1,
        };
    }
}
