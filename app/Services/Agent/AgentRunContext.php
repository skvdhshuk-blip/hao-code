<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
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
    ) {}

    public function fork(
        ?string $workingDirectory = null,
        ?bool $readOnly = null,
        ?array $interruptOn = null,
        ?string $agentId = null,
        ?string $teamName = null,
        ?bool $omitProjectInstructions = null,
        ?string $agentType = null,
    ): self
    {
        $readOnly ??= $this->readOnly;
        $settings = clone $this->settings;
        if ($readOnly) {
            $settings->set('permission_mode', 'plan');
        }

        return new self(
            $workingDirectory ?? $this->workingDirectory,
            $this->projectDirectory,
            $settings,
            clone $this->skillLoader,
            $this->cancellationToken->fork(),
            $interruptOn ?? $this->interruptOn,
            $this->enableAskUser,
            $agentId ?? $this->agentId,
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
        );
    }
}
