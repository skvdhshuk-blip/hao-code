<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;

/**
 * 根据单次 SDK 配置创建隔离的 Agent 运行上下文。
 *
 * @internal
 */
final class AgentRunContextFactory
{
    public static function make(HaoCodeConfig $config): AgentRunContext
    {
        $projectDirectory = ($config->cwd ?? getcwd()) ?: '/';
        $workingDirectory = $config->effectiveWorkingDirectory() ?? $projectDirectory;
        $settings = new SettingsManager($projectDirectory);
        $settings->set('permission_mode', $config->permissionMode);

        if ($config->systemPrompt !== null) {
            $settings->set('system_prompt', $config->systemPrompt);
        }
        if ($config->appendSystemPrompt !== null) {
            $settings->set('append_system_prompt', $config->appendSystemPrompt);
        }
        if ($config->memorySummaryLevel !== 'l0') {
            $settings->set('memory_summary_level', $config->memorySummaryLevel);
        }
        if ($config->memoryStoragePath !== null) {
            $settings->set('memory_storage_path', $config->memoryStoragePath);
        }

        $skillLoader = new SkillLoader($projectDirectory);
        foreach ($config->skills as $skill) {
            $skillLoader->registerSkillDefinition($skill->toDefinition());
        }

        return new AgentRunContext(
            $workingDirectory,
            $projectDirectory,
            $settings,
            $skillLoader,
            new CancellationToken(),
        );
    }
}
