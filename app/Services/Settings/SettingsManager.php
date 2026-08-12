<?php

namespace HaoCode\Services\Settings;

use HaoCode\Services\Permissions\PermissionMode;

class SettingsManager
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const DEFAULT_MAX_TOKENS = 16384;

    private const DEFAULT_APPROVAL_POLICY = 'on-request';

    private const DEFAULT_SANDBOX_MODE = 'workspace-write';

    /** @var list<string> */
    private const PROVIDER_IDENTITY_OVERRIDES = [
        'api_key',
        'model',
        'provider_type',
        'api_base_url',
        'max_tokens',
        'context_window',
    ];

    private ?array $cachedSettings = null;

    private array $runtimeOverrides = [];

    /** @var (\Closure(ResolvedProviderConfig, self): void)|null */
    private ?\Closure $runtimeConfigurationValidator = null;

    private readonly SettingsFileStore $fileStore;

    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {
        $this->fileStore = new SettingsFileStore($workingDirectory);
    }

    public function getApiKey(): string
    {
        return $this->resolveProviderConfig()->apiKey;
    }

    public function getModel(): string
    {
        return $this->resolveProviderConfig()->model;
    }

    public function getBaseUrl(): string
    {
        return $this->resolveProviderConfig()->baseUrl;
    }

    public function getMaxTokens(): int
    {
        return $this->resolveProviderConfig()->maxTokens;
    }

    /**
     * Resolve provider type, credentials, endpoint, model, and token limit
     * together so an explicit provider switch cannot reuse another vendor's
     * active provider entry or environment credential.
     */
    public function resolveProviderConfig(): ResolvedProviderConfig
    {
        $settings = $this->loadProjectSettings();
        $providerType = $this->getProviderType();
        $hasExplicitProviderType = array_key_exists('provider_type', $this->runtimeOverrides);
        $hasExplicitConnectionSettings = array_intersect(
            ['api_key', 'model', 'api_base_url', 'max_tokens', 'context_window'],
            array_keys($this->runtimeOverrides),
        ) !== [];
        $providerName = $this->resolveProviderNameForType(
            $providerType,
            $hasExplicitProviderType,
            $hasExplicitConnectionSettings,
        );
        $providerConfig = $providerName !== null ? $this->getProviderConfig($providerName) : null;

        $legacyAllowed = $providerType === 'anthropic';
        $apiKey = array_key_exists('api_key', $this->runtimeOverrides)
            ? trim(is_string($this->runtimeOverrides['api_key'])
                ? $this->runtimeOverrides['api_key']
                : '')
            : ($this->firstNonEmptyString(
                $providerConfig['api_key'] ?? null,
                $legacyAllowed ? ($settings['api_key'] ?? null) : null,
                $legacyAllowed ? \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_key') : null,
                $providerType === 'anthropic'
                    ? (getenv('ANTHROPIC_API_KEY') ?: null)
                    : (getenv('OPENAI_API_KEY') ?: null),
            ) ?? '');

        $runtimeModel = $this->resolveModelOverride($this->runtimeOverrides['model'] ?? null, $settings);
        $settingsModel = $legacyAllowed
            ? $this->resolveModelOverride($settings['model'] ?? null, $settings)
            : null;
        $model = $this->firstNonEmptyString(
            $runtimeModel,
            $providerConfig['model'] ?? null,
            $settingsModel,
        );
        if ($model === null && $providerType !== 'anthropic') {
            throw new \RuntimeException(
                "A model is required for provider type \"{$providerType}\". "
                .'Pass HaoCodeConfig(model: ...) or configure a default model for the selected provider.',
            );
        }
        if ($model === null) {
            $model = $this->firstNonEmptyString(
                \HaoCode\Support\Runtime\SdkRuntime::config('haocode.model', ModelCatalog::SONNET),
            ) ?? ModelCatalog::SONNET;
        }

        $defaultBaseUrl = in_array($providerType, ['openai', 'openai_chat'], true)
            ? 'https://api.openai.com'
            : self::DEFAULT_BASE_URL;
        $baseUrl = $this->firstNonEmptyString(
            $this->runtimeOverrides['api_base_url'] ?? null,
            $providerConfig['api_base_url'] ?? null,
            $legacyAllowed ? ($settings['api_base_url'] ?? null) : null,
            $legacyAllowed ? \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_base_url') : null,
        ) ?? $defaultBaseUrl;

        $maxTokens = $this->firstNumericValue(
            $this->runtimeOverrides['max_tokens'] ?? null,
            $providerConfig['max_tokens'] ?? null,
            $legacyAllowed ? ($settings['max_tokens'] ?? null) : null,
            $legacyAllowed ? \HaoCode\Support\Runtime\SdkRuntime::config('haocode.max_tokens') : null,
        ) ?? self::DEFAULT_MAX_TOKENS;
        $contextWindow = $this->firstNumericValue(
            $this->runtimeOverrides['context_window'] ?? null,
            $providerConfig['context_window'] ?? null,
            $legacyAllowed ? ($settings['context_window'] ?? null) : null,
            \HaoCode\Support\Runtime\SdkRuntime::config('haocode.context_window'),
        );
        if ($contextWindow === null || $contextWindow <= 0) {
            $contextWindow = 200000;
        }

        // Kimi's Anthropic-compatible coding endpoint expects its own model name.
        if (str_contains(strtolower(rtrim($baseUrl, '/')), 'api.kimi.com/coding')
            && str_starts_with($model, 'claude-')) {
            $model = 'kimi-for-coding';
        }

        return new ResolvedProviderConfig(
            providerType: $providerType,
            providerName: $providerName,
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            maxTokens: $maxTokens,
            contextWindow: $contextWindow,
        );
    }

    public function getContextWindow(): int
    {
        return $this->resolveProviderConfig()->contextWindow;
    }

    public function getActiveProviderName(): ?string
    {
        return $this->getModelProvider();
    }

    public function getModelProvider(): ?string
    {
        return $this->resolveSelectedProviderName($this->loadProjectSettings());
    }

    /**
     * @return array<string, array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null, context_window: int|null, type: string}>
     */
    public function getConfiguredProviders(): array
    {
        $settings = $this->loadProjectSettings();
        $providers = $this->configuredProvidersFromSettings($settings);
        $normalized = [];

        foreach ($providers as $name => $provider) {
            $normalized[$name] = $this->normalizeProviderConfig($name, $provider);
        }

        return $normalized;
    }

    /**
     * @return array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null, context_window: int|null, type: string}|null
     */
    public function getProviderConfig(?string $name = null): ?array
    {
        $providers = $this->getConfiguredProviders();
        $selected = $name !== null ? $this->normalizeProviderName($name) : $this->getActiveProviderName();

        if ($selected === null || ! array_key_exists($selected, $providers)) {
            return null;
        }

        return $providers[$selected];
    }

    /**
     * Return the wire-format type of the active provider.
     *
     * Defaults to "anthropic" when no provider is configured or when
     * the provider entry omits a "type" field (back-compat).
     */
    public function getProviderType(): string
    {
        if (isset($this->runtimeOverrides['provider_type'])) {
            return ProviderType::normalizeRequired((string) $this->runtimeOverrides['provider_type']);
        }

        $config = $this->getProviderConfig();

        if ($config === null) {
            return ProviderType::ANTHROPIC;
        }

        $type = $config['type'] ?? null;

        return is_string($type) && $type !== ''
            ? ProviderType::normalizeRequired($type)
            : ProviderType::ANTHROPIC;
    }

    /**
     * Resolve Phoenix / OpenTelemetry settings, honouring env var overrides.
     * Always returns the full shape so consumers can pluck fields without
     * null checks; `enabled` is false when nothing is configured.
     *
     * @return array{enabled: bool, endpoint: string, api_key: string, project_name: string, redact_messages: bool}
     */
    public function getTelemetryConfig(): array
    {
        $settings = $this->loadProjectSettings();
        $raw = is_array($settings['telemetry']['phoenix'] ?? null)
            ? $settings['telemetry']['phoenix']
            : [];

        $enabled = $this->resolveBoolSetting(
            getenv('HAOCODE_PHOENIX_ENABLED'),
            $raw['enabled'] ?? null,
            false,
        );

        $endpoint = $this->firstNonEmptyString(
            getenv('HAOCODE_PHOENIX_ENDPOINT') ?: null,
            $raw['endpoint'] ?? null,
        ) ?? '';

        $apiKey = $this->firstNonEmptyString(
            getenv('HAOCODE_PHOENIX_API_KEY') ?: null,
            $raw['api_key'] ?? null,
            $raw['apiKey'] ?? null,
        ) ?? '';

        $projectName = $this->firstNonEmptyString(
            getenv('HAOCODE_PHOENIX_PROJECT') ?: null,
            $raw['project_name'] ?? null,
            $raw['projectName'] ?? null,
        ) ?? 'hao-code';

        $redact = $this->resolveBoolSetting(
            getenv('HAOCODE_PHOENIX_REDACT'),
            $raw['redact_messages'] ?? $raw['redactMessages'] ?? null,
            false,
        );

        return [
            'enabled' => $enabled,
            'endpoint' => $endpoint,
            'api_key' => $apiKey,
            'project_name' => $projectName,
            'redact_messages' => $redact,
        ];
    }

    private function resolveBoolSetting(mixed $envValue, mixed $settingValue, bool $default): bool
    {
        if ($envValue !== false && $envValue !== '') {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_bool($settingValue)) {
            return $settingValue;
        }

        if (is_string($settingValue) && $settingValue !== '') {
            return filter_var($settingValue, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($settingValue)) {
            return $settingValue !== 0;
        }

        return $default;
    }

    public function getResolvedModelIdentifier(): string
    {
        $settings = $this->loadProjectSettings();
        $provider = $this->getActiveProviderName();
        $runtimeModel = $this->runtimeOverrides['model'] ?? null;
        $settingsModel = $settings['model'] ?? null;
        $runtimeSelection = $this->parseQualifiedModel($runtimeModel, $settings);
        $settingsSelection = $this->parseQualifiedModel($settingsModel, $settings);

        if ($runtimeSelection['provider'] === null && is_string($runtimeModel) && trim($runtimeModel) !== '' && str_contains($runtimeModel, '/')) {
            return trim($runtimeModel);
        }

        if ($provider !== null) {
            return $provider.'/'.$this->getModel();
        }

        if ($settingsSelection['provider'] === null && is_string($settingsModel) && trim($settingsModel) !== '' && str_contains($settingsModel, '/')) {
            return trim($settingsModel);
        }

        return $this->getModel();
    }

    public function getPermissionMode(): PermissionMode
    {
        $settings = $this->loadProjectSettings();
        $this->validateExplicitSecurityModes($settings);
        $this->validateExplicitSecurityModes($this->runtimeOverrides);
        $this->validateConfiguredSecurityModes();

        if (array_key_exists('permission_mode', $this->runtimeOverrides)) {
            return $this->normalizePermissionModeValue($this->runtimeOverrides['permission_mode']);
        }

        if (array_key_exists('approval_policy', $this->runtimeOverrides)
            || array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            return $this->permissionModeFromModernConfig(
                array_key_exists('approval_policy', $this->runtimeOverrides)
                    ? $this->normalizeApprovalPolicyValue($this->runtimeOverrides['approval_policy'])
                    : null,
                array_key_exists('sandbox_mode', $this->runtimeOverrides)
                    ? $this->normalizeSandboxModeValue($this->runtimeOverrides['sandbox_mode'])
                    : null,
            );
        }

        if (array_key_exists('permission_mode', $settings)) {
            return $this->normalizePermissionModeValue($settings['permission_mode']);
        }

        if (array_key_exists('approval_policy', $settings) || array_key_exists('sandbox_mode', $settings)) {
            return $this->permissionModeFromModernConfig(
                array_key_exists('approval_policy', $settings)
                    ? $this->normalizeApprovalPolicyValue($settings['approval_policy'])
                    : null,
                array_key_exists('sandbox_mode', $settings)
                    ? $this->normalizeSandboxModeValue($settings['sandbox_mode'])
                    : null,
            );
        }

        $configApprovalPolicy = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.approval_policy');
        $configSandboxMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.sandbox_mode');
        if ($configApprovalPolicy !== null || $configSandboxMode !== null) {
            return $this->permissionModeFromModernConfig(
                $configApprovalPolicy === null
                    ? null
                    : $this->normalizeApprovalPolicyValue($configApprovalPolicy),
                $configSandboxMode === null
                    ? null
                    : $this->normalizeSandboxModeValue($configSandboxMode),
            );
        }

        return $this->normalizePermissionModeValue(
            \HaoCode\Support\Runtime\SdkRuntime::config('haocode.permission_mode', PermissionMode::Default->value),
        );
    }

    public function getApprovalPolicy(): string
    {
        $settings = $this->loadProjectSettings();
        $this->validateExplicitSecurityModes($settings);
        $this->validateExplicitSecurityModes($this->runtimeOverrides);
        $this->validateConfiguredSecurityModes();

        if (array_key_exists('approval_policy', $this->runtimeOverrides)) {
            return $this->normalizeApprovalPolicyValue($this->runtimeOverrides['approval_policy']);
        }

        if (array_key_exists('permission_mode', $this->runtimeOverrides)
            || array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
        }

        if (array_key_exists('approval_policy', $settings)) {
            return $this->normalizeApprovalPolicyValue($settings['approval_policy']);
        }

        if (array_key_exists('permission_mode', $settings) || array_key_exists('sandbox_mode', $settings)) {
            return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
        }

        $configApprovalPolicy = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.approval_policy');
        if ($configApprovalPolicy !== null) {
            return $this->normalizeApprovalPolicyValue($configApprovalPolicy);
        }

        return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
    }

    public function getSandboxMode(): string
    {
        $settings = $this->loadProjectSettings();
        $this->validateExplicitSecurityModes($settings);
        $this->validateExplicitSecurityModes($this->runtimeOverrides);
        $this->validateConfiguredSecurityModes();

        if (array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            return $this->normalizeSandboxModeValue($this->runtimeOverrides['sandbox_mode']);
        }

        if (array_key_exists('permission_mode', $this->runtimeOverrides)
            || array_key_exists('approval_policy', $this->runtimeOverrides)) {
            return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
        }

        if (array_key_exists('sandbox_mode', $settings)) {
            return $this->normalizeSandboxModeValue($settings['sandbox_mode']);
        }

        if (array_key_exists('permission_mode', $settings) || array_key_exists('approval_policy', $settings)) {
            return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
        }

        $configSandboxMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.sandbox_mode');
        if ($configSandboxMode !== null) {
            return $this->normalizeSandboxModeValue($configSandboxMode);
        }

        return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
    }

    public function getAppendSystemPrompt(): ?string
    {
        if (array_key_exists('append_system_prompt', $this->runtimeOverrides)) {
            return $this->runtimeOverrides['append_system_prompt'];
        }

        $settings = $this->loadProjectSettings();

        return $settings['append_system_prompt'] ?? null;
    }

    public function getSystemPrompt(): ?string
    {
        if (array_key_exists('system_prompt', $this->runtimeOverrides)) {
            return $this->runtimeOverrides['system_prompt'];
        }

        $settings = $this->loadProjectSettings();

        return $settings['system_prompt'] ?? null;
    }

    /**
     * Get the memory summary level for system prompt injection.
     *
     * @return string 'l0' (compact), 'l1' (detailed), or 'l2' (full).
     */
    public function getMemorySummaryLevel(): string
    {
        if (array_key_exists('memory_summary_level', $this->runtimeOverrides)) {
            return $this->runtimeOverrides['memory_summary_level'];
        }

        $settings = $this->loadProjectSettings();

        return $settings['memory_summary_level'] ?? 'l0';
    }

    /**
     * Get the custom memory storage path, if configured.
     *
     * Returns null when using the default ~/.haocode/memory.json.
     */
    public function getMemoryStoragePath(): ?string
    {
        if (array_key_exists('memory_storage_path', $this->runtimeOverrides)) {
            $val = $this->runtimeOverrides['memory_storage_path'];
            return is_string($val) && $val !== '' ? $val : null;
        }

        $settings = $this->loadProjectSettings();

        $val = $settings['memory_storage_path'] ?? null;
        return is_string($val) && $val !== '' ? $val : null;
    }

    public function getAllowRules(): array
    {
        $settings = $this->loadProjectSettings();

        return $settings['permissions']['allow'] ?? [];
    }

    public function getDenyRules(): array
    {
        $settings = $this->loadProjectSettings();

        return $settings['permissions']['deny'] ?? [];
    }

    /** @return string[] Paths to policy YAML files */
    public function getPolicyFiles(): array
    {
        $settings = $this->loadProjectSettings();

        return $settings['permissions']['policy_files'] ?? [];
    }

    public function getSessionPath(): string
    {
        return \HaoCode\Support\Runtime\SdkRuntime::config(
            'haocode.session_path',
            \HaoCode\Support\Runtime\SdkRuntime::storagePath('app/haocode/sessions'),
        );
    }

    public function getOutputStyle(): ?string
    {
        return $this->runtimeOverrides['output_style']
            ?? $this->loadProjectSettings()['output_style']
            ?? null;
    }

    /**
     * Set a runtime override for a config key.
     */
    public function set(string $key, mixed $value): void
    {
        $allowedKeys = [
            'api_key',
            'model',
            'active_provider',
            'model_provider',
            'provider_type',
            'api_base_url',
            'max_tokens',
            'context_window',
            'permission_mode',
            'approval_policy',
            'sandbox_mode',
            'output_style',
            'append_system_prompt',
            'system_prompt',
            'memory_summary_level',
            'memory_storage_path',
            'thinking_enabled',
            'thinking_budget',
            'effort_level',
            'oauth_bearer',
            'headers',
        ];
        if (! in_array($key, $allowedKeys, true)) {
            return;
        }

        $previousOverrides = $this->runtimeOverrides;

        try {
            if ($key === 'active_provider' || $key === 'model_provider') {
                // A named provider owns its complete connection identity. Never
                // carry credentials, endpoint, model, or token limits from the
                // previously selected provider across this boundary. Run-level
                // OAuth/header policy remains explicit and is revalidated.
                if ($this->normalizeProviderName($value) !== null) {
                    $this->clearRuntimeProviderIdentity();
                }

                $this->runtimeOverrides['active_provider'] = $value;
                $this->runtimeOverrides['model_provider'] = $value;
                $this->assertRuntimeConfigurationSupported();

                return;
            }

            if ($key === 'model' && is_string($value)) {
                $selection = $this->parseQualifiedModel($value, $this->loadProjectSettings());
                if ($selection['provider'] !== null) {
                    $this->clearRuntimeProviderIdentity();
                    $this->runtimeOverrides['active_provider'] = $selection['provider'];
                    $this->runtimeOverrides['model_provider'] = $selection['provider'];
                    $this->runtimeOverrides['model'] = $selection['model'];
                    $this->assertRuntimeConfigurationSupported();

                    return;
                }
            }

            if ($key === 'permission_mode') {
                unset($this->runtimeOverrides['approval_policy'], $this->runtimeOverrides['sandbox_mode']);
            }

            if ($key === 'approval_policy' || $key === 'sandbox_mode') {
                unset($this->runtimeOverrides['permission_mode']);
            }

            $this->runtimeOverrides[$key] = $value;
            $this->assertRuntimeConfigurationSupported();
        } catch (\Throwable $exception) {
            $this->runtimeOverrides = $previousOverrides;

            throw $exception;
        }
    }

    /**
     * Bind the run-level capability and budget guard used by runtime changes
     * and by the final request boundary.
     *
     * @internal
     */
    public function setRuntimeConfigurationValidator(?callable $validator): void
    {
        $this->runtimeConfigurationValidator = $validator === null
            ? null
            : \Closure::fromCallable($validator);
    }

    /** @internal */
    public function assertRuntimeConfigurationSupported(): void
    {
        if ($this->runtimeConfigurationValidator === null) {
            return;
        }

        ($this->runtimeConfigurationValidator)($this->resolveProviderConfig(), $this);
    }

    private function clearRuntimeProviderIdentity(): void
    {
        foreach (self::PROVIDER_IDENTITY_OVERRIDES as $providerSetting) {
            unset($this->runtimeOverrides[$providerSetting]);
        }
    }

    /**
     * Add an allow rule persistently to project settings.
     */
    public function addAllowRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            if (! in_array($rule, $settings['permissions']['allow'] ?? [], true)) {
                $settings['permissions']['allow'][] = $rule;
            }
        });
    }

    /**
     * Add a deny rule persistently to project settings.
     */
    public function addDenyRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            if (! in_array($rule, $settings['permissions']['deny'] ?? [], true)) {
                $settings['permissions']['deny'][] = $rule;
            }
        });
    }

    /**
     * Remove an allow rule from project settings.
     */
    public function removeAllowRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            $key = array_search($rule, $settings['permissions']['allow'] ?? []);
            if ($key !== false) {
                unset($settings['permissions']['allow'][$key]);
                $settings['permissions']['allow'] = array_values($settings['permissions']['allow']);
            }
        });
    }

    /**
     * Remove a deny rule from project settings.
     */
    public function removeDenyRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            $key = array_search($rule, $settings['permissions']['deny'] ?? []);
            if ($key !== false) {
                unset($settings['permissions']['deny'][$key]);
                $settings['permissions']['deny'] = array_values($settings['permissions']['deny']);
            }
        });
    }

    public function isThinkingEnabled(): bool
    {
        if (array_key_exists('thinking_enabled', $this->runtimeOverrides)) {
            return (bool) $this->runtimeOverrides['thinking_enabled'];
        }

        $envValue = getenv('HAOCODE_THINKING');

        return $envValue !== false && filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
    }

    public function getThinkingBudget(): int
    {
        if (array_key_exists('thinking_budget', $this->runtimeOverrides)) {
            return (int) $this->runtimeOverrides['thinking_budget'];
        }

        $envValue = getenv('HAOCODE_THINKING_BUDGET');

        return $envValue !== false && is_numeric($envValue) ? (int) $envValue : 10000;
    }

    public function getEffortLevel(): string
    {
        return $this->runtimeOverrides['effort_level'] ?? 'auto';
    }

    /**
     * Whether the resolved API key is an Anthropic OAuth access token that
     * must be sent as `Authorization: Bearer` instead of `x-api-key`.
     * Defaults to false (plain API key behaviour).
     */
    public function isOauthBearer(): bool
    {
        if (array_key_exists('oauth_bearer', $this->runtimeOverrides)) {
            return (bool) $this->runtimeOverrides['oauth_bearer'];
        }

        return false;
    }

    /**
     * Custom HTTP request headers for this run (runtime overrides only).
     * Providers merge these into their hardcoded request headers; custom
     * values win same-name except Authorization/x-api-key. Returns an empty
     * array when no override is set.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $raw = $this->runtimeOverrides['headers'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        return \HaoCode\Services\Api\RequestHeaders::sanitize($raw);
    }

    /**
     * Get all current settings as a flat array.
     */
    public function all(): array
    {
        return [
            'model' => $this->getModel(),
            'model_identifier' => $this->getResolvedModelIdentifier(),
            'active_provider' => $this->getActiveProviderName(),
            'configured_providers' => array_keys($this->getConfiguredProviders()),
            'api_base_url' => $this->getBaseUrl(),
            'max_tokens' => $this->getMaxTokens(),
            'context_window' => $this->getContextWindow(),
            'permission_mode' => $this->getPermissionMode()->value,
            'output_style' => $this->getOutputStyle(),
            'thinking_enabled' => $this->isThinkingEnabled(),
            'thinking_budget' => $this->getThinkingBudget(),
            'effort_level' => $this->getEffortLevel(),
            'api_key_set' => ! empty($this->getApiKey()),
        ];
    }

    /**
     * Get available models.
     */
    public static function getAvailableModels(): array
    {
        return ModelCatalog::availableModels();
    }

    private function loadProjectSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        ['global' => $globalPath, 'project' => $projectPath] = $this->fileStore->paths();
        $global = $this->loadSettingsFile($globalPath);
        $project = $this->loadSettingsFile($projectPath);
        $this->validateExplicitSecurityModes($global);
        $this->validateExplicitSecurityModes($project);

        $globalPerms = is_array($global['permissions'] ?? null) ? $global['permissions'] : [];
        $projectPerms = is_array($project['permissions'] ?? null) ? $project['permissions'] : [];

        unset($global['permissions'], $project['permissions']);

        $this->cachedSettings = array_merge($global, $project);

        $providers = $this->mergeProviderMaps($global, $project);
        unset($this->cachedSettings['providers']);
        if ($providers !== []) {
            $this->cachedSettings['provider'] = $providers;
        }

        // Permissions accumulate across both files — project rules ADD to global rules
        // rather than replacing them. This prevents silent loss of global deny/allow rules.
        $this->cachedSettings['permissions'] = [
            'allow' => array_merge($globalPerms['allow'] ?? [], $projectPerms['allow'] ?? []),
            'deny' => array_merge($globalPerms['deny'] ?? [], $projectPerms['deny'] ?? []),
            'policy_files' => array_merge($globalPerms['policy_files'] ?? [], $projectPerms['policy_files'] ?? []),
        ];

        return $this->cachedSettings;
    }

    private function loadSettingsFile(string $path): array
    {
        return $this->fileStore->read($path);
    }

    private function resolveSelectedProviderName(array $settings, bool $allowImplicitFallback = true): ?string
    {
        $providers = $this->configuredProvidersFromSettings($settings);
        if ($providers === []) {
            return null;
        }

        $runtimeSelection = $this->parseQualifiedModel($this->runtimeOverrides['model'] ?? null, $settings);
        if ($runtimeSelection['provider'] !== null) {
            return $runtimeSelection['provider'];
        }

        $runtimeProviderValue = array_key_exists('model_provider', $this->runtimeOverrides)
            ? $this->runtimeOverrides['model_provider']
            : (array_key_exists('active_provider', $this->runtimeOverrides)
                ? $this->runtimeOverrides['active_provider']
                : null);

        if (array_key_exists('model_provider', $this->runtimeOverrides)
            || array_key_exists('active_provider', $this->runtimeOverrides)) {
            $runtimeProvider = $this->normalizeProviderName($runtimeProviderValue);
            if ($runtimeProvider !== null && array_key_exists($runtimeProvider, $providers)) {
                return $runtimeProvider;
            }
        } else {
            $settingsProvider = $this->normalizeProviderName(
                $settings['model_provider']
                    ?? $settings['active_provider']
                    ?? \HaoCode\Support\Runtime\SdkRuntime::config('haocode.model_provider')
                    ?? \HaoCode\Support\Runtime\SdkRuntime::config('haocode.active_provider')
                    ?? null,
            );
            if ($settingsProvider !== null && array_key_exists($settingsProvider, $providers)) {
                return $settingsProvider;
            }
        }

        $settingsSelection = $this->parseQualifiedModel($settings['model'] ?? null, $settings);
        if ($settingsSelection['provider'] !== null) {
            return $settingsSelection['provider'];
        }

        if ($allowImplicitFallback && ! $this->hasLegacyTopLevelConfig($settings)) {
            return array_key_first($providers);
        }

        return null;
    }

    private function resolveProviderNameForType(
        string $providerType,
        bool $explicitProviderType,
        bool $hasExplicitConnectionSettings = false,
    ): ?string
    {
        if (! $explicitProviderType) {
            return $this->getActiveProviderName();
        }

        $providers = $this->getConfiguredProviders();
        $activeProvider = $this->resolveSelectedProviderName($this->loadProjectSettings(), false);
        if ($activeProvider !== null
            && isset($providers[$activeProvider])
            && $providers[$activeProvider]['type'] === $providerType) {
            return $activeProvider;
        }

        $matches = array_keys(array_filter(
            $providers,
            static fn (array $provider): bool => $provider['type'] === $providerType,
        ));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            if ($hasExplicitConnectionSettings) {
                return null;
            }
            throw new \RuntimeException(
                "Provider type \"{$providerType}\" matches multiple configured providers: "
                .implode(', ', $matches)
                .'. Select one with model_provider/active_provider or pass explicit connection settings.',
            );
        }

        return null;
    }

    private function normalizePermissionModeValue(mixed $value): PermissionMode
    {
        if (! is_string($value) || PermissionMode::tryFrom($value) === null) {
            $display = is_scalar($value) ? (string) $value : get_debug_type($value);
            throw new \InvalidArgumentException(
                "Invalid permission mode '{$display}'. Expected default, plan, accept_edits, or bypass_permissions.",
            );
        }

        return PermissionMode::from($value);
    }

    /**
     * Validate every explicitly present security field before precedence is
     * applied, so a valid legacy override cannot hide a misspelled modern mode.
     *
     * @param array<string, mixed> $values
     */
    private function validateExplicitSecurityModes(array $values): void
    {
        if (array_key_exists('permission_mode', $values)) {
            $this->normalizePermissionModeValue($values['permission_mode']);
        }
        if (array_key_exists('approval_policy', $values)) {
            $this->normalizeApprovalPolicyValue($values['approval_policy']);
        }
        if (array_key_exists('sandbox_mode', $values)) {
            $this->normalizeSandboxModeValue($values['sandbox_mode']);
        }
    }

    private function validateConfiguredSecurityModes(): void
    {
        $permissionMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.permission_mode');
        $approvalPolicy = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.approval_policy');
        $sandboxMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.sandbox_mode');

        if ($permissionMode !== null) {
            $this->normalizePermissionModeValue($permissionMode);
        }
        if ($approvalPolicy !== null) {
            $this->normalizeApprovalPolicyValue($approvalPolicy);
        }
        if ($sandboxMode !== null) {
            $this->normalizeSandboxModeValue($sandboxMode);
        }
    }

    private function permissionModeFromModernConfig(mixed $approvalPolicy, mixed $sandboxMode): PermissionMode
    {
        $normalizedSandbox = $sandboxMode === null
            ? null
            : $this->normalizeSandboxModeValue($sandboxMode);
        if ($normalizedSandbox === 'read-only') {
            return PermissionMode::Plan;
        }

        if ($normalizedSandbox === 'danger-full-access') {
            return PermissionMode::BypassPermissions;
        }

        $normalizedApproval = $approvalPolicy === null
            ? null
            : $this->normalizeApprovalPolicyValue($approvalPolicy);
        if ($normalizedApproval === 'never') {
            return PermissionMode::BypassPermissions;
        }

        if ($normalizedApproval === 'on-failure') {
            return PermissionMode::AcceptEdits;
        }

        return PermissionMode::Default;
    }

    private function approvalPolicyFromPermissionMode(PermissionMode $mode): string
    {
        return match ($mode) {
            PermissionMode::AcceptEdits => 'on-failure',
            PermissionMode::BypassPermissions => 'never',
            default => self::DEFAULT_APPROVAL_POLICY,
        };
    }

    private function sandboxModeFromPermissionMode(PermissionMode $mode): string
    {
        return match ($mode) {
            PermissionMode::Plan => 'read-only',
            PermissionMode::BypassPermissions => 'danger-full-access',
            default => self::DEFAULT_SANDBOX_MODE,
        };
    }

    private function normalizeApprovalPolicy(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'untrusted', 'on-request', 'on-failure', 'never' => strtolower(trim($value)),
            default => null,
        };
    }

    private function normalizeApprovalPolicyValue(mixed $value): string
    {
        $normalized = $this->normalizeApprovalPolicy($value);
        if ($normalized === null) {
            $display = is_scalar($value) ? (string) $value : get_debug_type($value);
            throw new \InvalidArgumentException(
                "Invalid approval policy '{$display}'. Expected untrusted, on-request, on-failure, or never.",
            );
        }

        return $normalized;
    }

    private function normalizeSandboxMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'read-only', 'workspace-write', 'danger-full-access' => strtolower(trim($value)),
            default => null,
        };
    }

    private function normalizeSandboxModeValue(mixed $value): string
    {
        $normalized = $this->normalizeSandboxMode($value);
        if ($normalized === null) {
            $display = is_scalar($value) ? (string) $value : get_debug_type($value);
            throw new \InvalidArgumentException(
                "Invalid sandbox mode '{$display}'. Expected read-only, workspace-write, or danger-full-access.",
            );
        }

        return $normalized;
    }

    private function resolveModelOverride(mixed $value, array $settings): ?string
    {
        $selection = $this->parseQualifiedModel($value, $settings);

        return $selection['model'];
    }

    /**
     * @return array{provider: string|null, model: string|null}
     */
    private function parseQualifiedModel(mixed $value, array $settings): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['provider' => null, 'model' => null];
        }

        $model = trim($value);
        if (! str_contains($model, '/')) {
            return ['provider' => null, 'model' => $model];
        }

        [$candidateProvider, $candidateModel] = explode('/', $model, 2);
        $candidateProvider = $this->normalizeProviderName($candidateProvider);
        $candidateModel = trim($candidateModel);

        if ($candidateProvider !== null
            && $candidateModel !== ''
            && array_key_exists($candidateProvider, $this->configuredProvidersFromSettings($settings))) {
            return ['provider' => $candidateProvider, 'model' => $candidateModel];
        }

        return ['provider' => null, 'model' => $model];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredProvidersFromSettings(array $settings): array
    {
        $providers = [];

        foreach (['provider', 'providers'] as $key) {
            $raw = $settings[$key] ?? null;
            if (! is_array($raw)) {
                continue;
            }

            foreach ($raw as $name => $provider) {
                $normalizedName = $this->normalizeProviderName($name);
                if ($normalizedName === null || ! is_array($provider)) {
                    continue;
                }

                $providers[$normalizedName] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergeProviderMaps(array $global, array $project): array
    {
        return array_replace_recursive(
            $this->configuredProvidersFromSettings($global),
            $this->configuredProvidersFromSettings($project),
        );
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null, context_window: int|null, type: string}
     */
    private function normalizeProviderConfig(string $name, array $provider): array
    {
        $options = is_array($provider['options'] ?? null) ? $provider['options'] : [];

        $rawType = $this->firstNonEmptyString(
            $provider['type'] ?? null,
            $options['type'] ?? null,
        );
        // Explicit type values fail closed. When type is omitted, only treat the
        // provider name as a type if it is itself a known alias; otherwise keep
        // the historical Anthropic default for unnamed/legacy entries.
        if ($rawType !== null) {
            $type = ProviderType::normalizeRequired($rawType);
        } else {
            $type = ProviderType::tryFromName($name) ?? ProviderType::ANTHROPIC;
        }

        return [
            'api_key' => $this->firstNonEmptyString(
                $provider['api_key'] ?? null,
                $provider['apiKey'] ?? null,
                $options['apiKey'] ?? null,
                $options['api_key'] ?? null,
            ),
            'api_base_url' => $this->firstNonEmptyString(
                $provider['api_base_url'] ?? null,
                $provider['apiBaseUrl'] ?? null,
                $provider['base_url'] ?? null,
                $provider['baseURL'] ?? null,
                $options['baseURL'] ?? null,
                $options['base_url'] ?? null,
                $options['apiBaseUrl'] ?? null,
            ),
            'model' => $this->firstNonEmptyString(
                $provider['model'] ?? null,
                $provider['default_model'] ?? null,
                $provider['defaultModel'] ?? null,
            ),
            'max_tokens' => $this->firstNumericValue(
                $provider['max_tokens'] ?? null,
                $provider['maxTokens'] ?? null,
                $options['maxTokens'] ?? null,
                $options['max_tokens'] ?? null,
            ),
            'context_window' => $this->firstNumericValue(
                $provider['context_window'] ?? null,
                $provider['contextWindow'] ?? null,
                $options['contextWindow'] ?? null,
                $options['context_window'] ?? null,
            ),
            'type' => $type,
        ];
    }

    private function hasLegacyTopLevelConfig(array $settings): bool
    {
        $model = $this->parseQualifiedModel($settings['model'] ?? null, $settings);

        return $this->firstNonEmptyString($settings['api_key'] ?? null) !== null
            || $this->firstNonEmptyString($settings['api_base_url'] ?? null) !== null
            || ($model['provider'] === null && $model['model'] !== null);
    }

    private function normalizeProviderName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = trim($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function firstNumericValue(mixed ...$values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Modify project settings file and invalidate cache.
     */
    private function modifyProjectSettings(callable $modifier): void
    {
        $projectPath = $this->fileStore->paths()['project'];
        $this->fileStore->update($projectPath, function (array &$settings) use ($modifier): void {
            if (! isset($settings['permissions']) || ! is_array($settings['permissions'])) {
                $settings['permissions'] = ['allow' => [], 'deny' => []];
            }

            $modifier($settings);
        });
        $this->cachedSettings = null;
    }

}
