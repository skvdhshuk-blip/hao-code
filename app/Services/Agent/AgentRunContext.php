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
final readonly class AgentRunContext
{
    public function __construct(
        public string $workingDirectory,
        public string $projectDirectory,
        public SettingsManager $settings,
        public SkillLoader $skillLoader,
        public CancellationToken $cancellationToken,
        public array $interruptOn = [],
        public bool $enableAskUser = false,
        public ?string $agentId = null,
        public ?string $teamName = null,
        public ?array $responseSchema = null,
        public ?MemoryStoreInterface $memoryStore = null,
        public bool $includeMemoryInTextOnly = false,
        public array $memoryTools = [],
    ) {}

    public function fork(
        ?string $workingDirectory = null,
        bool $readOnly = false,
        ?array $interruptOn = null,
        ?string $agentId = null,
        ?string $teamName = null,
    ): self
    {
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
        );
    }
}
