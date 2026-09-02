<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\ContextPreset;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Sdk\Internal\StructuredHitlRunner;
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
        $settings = new SettingsManager(
            $projectDirectory,
            \HaoCode\Support\Runtime\SdkRuntime::settingsDefaults(),
        );
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

        // Plan mode ends by a human decision when one can be asked for. A durable
        // session can raise an interrupt, so ExitPlanMode is gated like any other
        // reviewed tool; an ephemeral one cannot, so the tool hands the plan back and
        // ends the run instead of quietly granting itself write access.
        $interruptOn = $config->interruptOn;
        $planExitMode = 'return';
        $startsInPlanMode = $config->permissionMode === 'plan';
        if ($startsInPlanMode && $config->planExitPolicy === 'auto') {
            $planExitMode = 'auto';
        } elseif ($startsInPlanMode
            && ! $config->ephemeral
            && ($interruptOn['ExitPlanMode'] ?? null) !== false
        ) {
            $planExitMode = 'approval';
            $interruptOn['ExitPlanMode'] ??= [
                'allowedDecisions' => ['approve', 'reject', 'respond'],
                'description' => 'Approve the implementation plan and leave plan mode',
            ];
        }

        return new AgentRunContext(
            $workingDirectory,
            $projectDirectory,
            $settings,
            $skillLoader,
            new CancellationToken(),
            $interruptOn,
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
            omitProjectInstructions: false,
            agentType: null,
            readOnly: false,
            worktreePath: null,
            worktreeBranch: null,
            managedWorktree: false,
            backgroundOwnerAgentId: null,
            budgetLedger: null,
            usageAccumulator: new \HaoCode\Services\Cost\UsageAccumulator,
            contextPreset: $config->contextPreset,
            planExitMode: $planExitMode,
            hitlStructuredRunner: new StructuredHitlRunner,
        );
    }

    /**
     * Derive an Agent-as-Tool context without rebuilding credentials, storage,
     * sandbox, cancellation, or other parent-owned resources.
     *
     * @internal
     */
    public static function makeChild(
        HaoCodeConfig $config,
        AgentRunContext $parent,
        string $workingDirectory,
    ): AgentRunContext {
        if ($config->credentialPool !== null) {
            throw new \LogicException(
                'Nested agents inherit the parent provider connection and cannot attach a credential pool.',
            );
        }
        $contextPreset = $parent->contextPreset === ContextPreset::GENERIC
            || $config->contextPreset === ContextPreset::GENERIC
            ? ContextPreset::GENERIC
            : ContextPreset::CODING;
        $interruptOn = array_replace($config->interruptOn, $parent->interruptOn);
        $context = $parent->fork(
            workingDirectory: $workingDirectory,
            readOnly: $parent->readOnly || $config->permissionMode === 'plan',
            interruptOn: $interruptOn,
            contextPreset: $contextPreset,
        );
        $settings = $context->settings;
        $parentProvider = $parent->settings->resolveProviderConfig();

        $settings->set(
            'permission_mode',
            self::stricterPermissionMode(
                $parent->settings->getPermissionMode()->value,
                $config->permissionMode,
            ),
        );
        if ($config->model !== null) {
            $settings->set('model', $config->model);
        }
        if ($config->maxTokens !== null) {
            $settings->set('max_tokens', min($parentProvider->maxTokens, $config->maxTokens));
        }
        $settings->set(
            'thinking_enabled',
            $parent->settings->isThinkingEnabled() && $config->thinkingEnabled,
        );
        $settings->set(
            'thinking_budget',
            min($parent->settings->getThinkingBudget(), $config->thinkingBudget),
        );
        $childPrompt = trim(implode("\n\n", array_filter([
            $config->systemPrompt,
            $config->appendSystemPrompt,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
        if ($childPrompt !== '') {
            $existing = trim((string) $settings->getAppendSystemPrompt());
            $settings->set(
                'append_system_prompt',
                $existing === '' ? $childPrompt : $existing."\n\n".$childPrompt,
            );
        }

        $childProvider = $settings->resolveProviderConfig();
        if ($childProvider->providerType !== $parentProvider->providerType
            || $childProvider->baseUrl !== $parentProvider->baseUrl
            || $childProvider->apiKey !== $parentProvider->apiKey) {
            throw new \LogicException(
                'Nested agents may select a model only within the inherited provider connection.',
            );
        }

        return $context;
    }

    private static function stricterPermissionMode(string $parent, string $child): string
    {
        $rank = [
            'plan' => 0,
            'default' => 1,
            'accept_edits' => 2,
            'bypass_permissions' => 3,
        ];

        return ($rank[$child] ?? -1) < ($rank[$parent] ?? -1) ? $child : $parent;
    }
}
