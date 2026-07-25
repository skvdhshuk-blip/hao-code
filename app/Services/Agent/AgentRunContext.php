<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;

/**
 * 每次 Agent 运行独享的不可变上下文，避免 SDK 查询之间共享可变配置。
 *
 * @internal
 */
final class AgentRunContext
{
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
    ) {}

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
        );
    }
}
