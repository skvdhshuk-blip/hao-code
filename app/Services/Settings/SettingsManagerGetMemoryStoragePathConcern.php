<?php

namespace HaoCode\Services\Settings;

use HaoCode\Services\Permissions\PermissionMode;

trait SettingsManagerGetMemoryStoragePathConcern
{

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
}
