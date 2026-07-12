<?php

namespace HaoCode\Services\Agent;

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
    ) {}

    public function fork(?string $workingDirectory = null, bool $readOnly = false): self
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
        );
    }
}
