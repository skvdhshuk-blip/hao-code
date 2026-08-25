<?php

namespace HaoCode\Services\Settings;

use HaoCode\Services\Permissions\PermissionMode;

trait SettingsManagerConstructConcern
{

    public function __construct(
        private readonly ?string $workingDirectory = null,
        array $runtimeDefaults = [],
    ) {
        $this->runtimeDefaults = $runtimeDefaults;
        $globalSettingsPath = $runtimeDefaults['global_settings_path'] ?? null;
        $this->fileStore = new SettingsFileStore(
            $workingDirectory,
            is_string($globalSettingsPath) && trim($globalSettingsPath) !== '' ? $globalSettingsPath : null,
        );
    }

    private function runtimeDefault(string $key, mixed $default = null): mixed
    {
        return $this->runtimeDefaults[$key] ?? $default;
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
                $legacyAllowed ? $this->runtimeDefault('api_key') : null,
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
                $this->runtimeDefault('model', ModelCatalog::SONNET),
            ) ?? ModelCatalog::SONNET;
        }

        $defaultBaseUrl = in_array($providerType, ['openai', 'openai_chat'], true)
            ? 'https://api.openai.com'
            : self::DEFAULT_BASE_URL;
        $baseUrl = $this->firstNonEmptyString(
            $this->runtimeOverrides['api_base_url'] ?? null,
            $providerConfig['api_base_url'] ?? null,
            $legacyAllowed ? ($settings['api_base_url'] ?? null) : null,
            $legacyAllowed ? $this->runtimeDefault('api_base_url') : null,
        ) ?? $defaultBaseUrl;

        $maxTokens = $this->firstNumericValue(
            $this->runtimeOverrides['max_tokens'] ?? null,
            $providerConfig['max_tokens'] ?? null,
            $legacyAllowed ? ($settings['max_tokens'] ?? null) : null,
            $legacyAllowed ? $this->runtimeDefault('max_tokens') : null,
        ) ?? self::DEFAULT_MAX_TOKENS;
        $contextWindow = $this->firstNumericValue(
            $this->runtimeOverrides['context_window'] ?? null,
            $providerConfig['context_window'] ?? null,
            $legacyAllowed ? ($settings['context_window'] ?? null) : null,
            $this->runtimeDefault('context_window'),
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

        $configApprovalPolicy = $this->runtimeDefault('approval_policy');
        $configSandboxMode = $this->runtimeDefault('sandbox_mode');
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
            $this->runtimeDefault('permission_mode', PermissionMode::Default->value),
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

        $configApprovalPolicy = $this->runtimeDefault('approval_policy');
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

        $configSandboxMode = $this->runtimeDefault('sandbox_mode');
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
}
