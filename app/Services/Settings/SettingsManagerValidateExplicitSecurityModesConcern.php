<?php

namespace HaoCode\Services\Settings;

use HaoCode\Services\Permissions\PermissionMode;

trait SettingsManagerValidateExplicitSecurityModesConcern
{

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
