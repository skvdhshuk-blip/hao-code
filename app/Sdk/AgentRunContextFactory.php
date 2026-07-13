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
        $settings->set('thinking_enabled', $config->thinkingEnabled);
        $settings->set('thinking_budget', $config->thinkingBudget);

        if ($config->apiKey !== null) {
            $settings->set('api_key', $config->apiKey);
        }
        if ($config->model !== null) {
            $settings->set('model', $config->model);
        }
        if ($config->baseUrl !== null) {
            $settings->set('api_base_url', $config->baseUrl);
        }
        if ($config->maxTokens !== null) {
            $settings->set('max_tokens', $config->maxTokens);
        }
        if ($config->providerType !== null) {
            $settings->set('provider_type', match ($config->providerType) {
                'openai', 'openai_responses', 'responses' => 'openai',
                'openai_chat', 'openai_chat_completions', 'chat_completions' => 'openai_chat',
                default => 'anthropic',
            });
        }

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

        $skillLoader = new SkillLoader(
            $projectDirectory,
            $config->skillDirectories,
            $config->recursiveSkillDiscovery,
        );
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
