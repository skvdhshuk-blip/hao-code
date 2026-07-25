<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Memory\JsonMemoryStore;
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
            $settings->set(
                'provider_type',
                \HaoCode\Services\Settings\ProviderType::normalizeRequired($config->providerType),
            );
        }
        if ($config->oauthBearer !== null) {
            $settings->set('oauth_bearer', $config->oauthBearer);
        }
        if ($config->headers !== []) {
            $settings->set('headers', $config->headers);
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

        $memoryStore = $config->memoryStore ?? new JsonMemoryStore($config->memoryStoragePath);
        $includeMemoryInTextOnly = $config->memoryStore !== null
            || $config->memoryStoragePath !== null
            || $config->memorySummaryLevel !== 'l0';
        $toolFilter = $config->toolFilter();
        $memoryTools = array_values(array_filter(
            ['MemoryRead', 'MemoryWrite', 'MemoryDelete'],
            static fn (string $name): bool => $toolFilter === null || $toolFilter($name),
        ));

        // HITL mode: an explicit HaoCodeConfig value ('ask' included) always
        // wins; null (unset) falls back to the haocode.hitl_mode config file /
        // HAOCODE_HITL_MODE environment default so deployments can opt in
        // process-wide.
        $hitlMode = $config->hitlMode;
        if ($hitlMode === null) {
            $configuredMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.hitl_mode', 'smart');
            $hitlMode = is_string($configuredMode) && in_array($configuredMode, ['ask', 'smart', 'auto'], true)
                ? $configuredMode
                : 'smart';
        }
        $hitlReviewModel = $config->hitlReviewModel;
        if ($hitlReviewModel === null) {
            $configuredModel = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.hitl_review_model');
            $hitlReviewModel = is_string($configuredModel) && trim($configuredModel) !== ''
                ? $configuredModel
                : null;
        }
        $hitlAllowlistPath = $config->hitlAllowlistPath;
        if ($hitlAllowlistPath === null) {
            $configuredPath = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.hitl_allowlist_path');
            $hitlAllowlistPath = is_string($configuredPath) && trim($configuredPath) !== ''
                ? $configuredPath
                : null;
        }

        return new AgentRunContext(
            $workingDirectory,
            $projectDirectory,
            $settings,
            $skillLoader,
            new CancellationToken(),
            $config->interruptOn,
            $config->enableAskUser,
            null,
            null,
            $config->responseSchema,
            $memoryStore,
            $includeMemoryInTextOnly,
            $memoryTools,
            $hitlMode,
            $hitlReviewModel,
            $config->sandbox,
            $hitlAllowlistPath,
        );
    }
}
