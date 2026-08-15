<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Settings\ModelCatalog;
use HaoCode\Services\Settings\ResolvedProviderConfig;
use HaoCode\Services\Settings\SettingsManager;

/**
 * Single run-scoped authority for capability and priced-budget validation.
 *
 * @internal
 */
final class RunCapabilityGuard
{
    /** @var list<string>|null */
    private ?array $effectiveToolNames = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $effectiveToolManifest = null;

    /** @var array<string, mixed>|null */
    private ?array $fixedProviderRuntime = null;

    public function __construct(
        private readonly HaoCodeConfig $config,
        private readonly RunCapabilityResolver $resolver,
        private readonly ?float $budgetLimit = null,
        private readonly bool $requireResolvedCredential = true,
        /** @var list<string> */
        private readonly array $injectedCredentialProviderTypes = [],
    ) {}

    /**
     * Providers that do not implement SettingsAwareProvider cannot safely
     * follow Config-tool mutations. Pin the provider-facing settings so a
     * runtime change is rejected instead of only changing SDK bookkeeping.
     */
    public function lockProviderRuntime(
        ResolvedProviderConfig $resolvedProvider,
        SettingsManager $settings,
    ): void {
        $this->fixedProviderRuntime = $this->providerRuntimeState($resolvedProvider, $settings);
    }

    /** @param list<string> $toolNames */
    public function bindEffectiveTools(array $toolNames): void
    {
        $names = [];
        foreach ($toolNames as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[trim($name)] = true;
            }
        }

        $this->effectiveToolNames = array_keys($names);
        sort($this->effectiveToolNames);
        $this->effectiveToolManifest = null;
    }

    /**
     * Bind the validated registry manifest assembled for this exact run.
     *
     * @param array<string, array<string, mixed>> $manifest
     */
    public function bindEffectiveManifest(array $manifest): void
    {
        $names = [];
        foreach ($manifest as $key => $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (! is_string($key) || ! is_string($name) || $key !== $name) {
                throw new \InvalidArgumentException('Effective tool manifest keys must match stable tool names.');
            }
            if (! is_array($entry['input_schema'] ?? null)
                || ! is_string($entry['effect'] ?? null)
                || ! is_string($entry['implementation'] ?? null)) {
                throw new \InvalidArgumentException("Effective tool manifest entry '{$name}' is incomplete.");
            }
            $names[] = $name;
        }

        sort($names);
        ksort($manifest);
        $this->effectiveToolNames = $names;
        $this->effectiveToolManifest = $manifest;
    }

    public function manifest(
        SettingsManager $settings,
        ?ResolvedProviderConfig $resolvedProvider = null,
    ): EffectiveCapabilityManifest {
        return $this->resolver->resolve(
            $this->config,
            $resolvedProvider ?? $settings->resolveProviderConfig(),
            $this->effectiveToolNames,
            $settings->getPermissionMode(),
            [
                \HaoCode\Services\Api\Capability\ProviderCapabilityRegistry::THINKING => $settings->isThinkingEnabled(),
                \HaoCode\Services\Api\Capability\ProviderCapabilityRegistry::OAUTH_BEARER => $settings->isOauthBearer(),
                \HaoCode\Services\Api\Capability\ProviderCapabilityRegistry::CUSTOM_HEADERS => $settings->getHeaders() !== [],
            ],
            $this->effectiveToolManifest,
        );
    }

    public function assertSupported(
        ResolvedProviderConfig $resolvedProvider,
        SettingsManager $settings,
    ): void {
        if ($this->fixedProviderRuntime !== null
            && $this->providerRuntimeState($resolvedProvider, $settings) !== $this->fixedProviderRuntime) {
            throw new \RuntimeException(
                'Runtime provider configuration changes require a provider '
                .'that implements SettingsAwareProvider.',
            );
        }

        $this->manifest($settings, $resolvedProvider)->assertSupported();

        $hasPooledCredential = $this->config->credentialPool?->hasProvider(
            $resolvedProvider->providerType,
        ) ?? false;
        $hasInjectedCredential = in_array(
            $resolvedProvider->providerType,
            $this->injectedCredentialProviderTypes,
            true,
        );
        if ($this->requireResolvedCredential
            && trim($resolvedProvider->apiKey) === ''
            && ! $hasPooledCredential
            && ! $hasInjectedCredential) {
            $environment = $resolvedProvider->providerType === 'anthropic'
                ? 'ANTHROPIC_API_KEY'
                : 'OPENAI_API_KEY';
            throw new \RuntimeException(
                "API key is required for provider type \"{$resolvedProvider->providerType}\". "
                .'Configure a matching provider entry or credential pool, '
                ."or set {$environment} in the process environment.",
            );
        }

        if ($this->budgetLimit !== null
            && ModelCatalog::pricingFor($resolvedProvider->providerType, $resolvedProvider->model) === null) {
            throw new \RuntimeException(
                "Cost budget requires pricing for model \"{$resolvedProvider->model}\" "
                ."on provider type \"{$resolvedProvider->providerType}\". No trusted pricing is configured.",
            );
        }
    }

    /** @return array<string, mixed> */
    private function providerRuntimeState(
        ResolvedProviderConfig $resolvedProvider,
        SettingsManager $settings,
    ): array {
        return [
            'provider_type' => $resolvedProvider->providerType,
            'api_key' => $resolvedProvider->apiKey,
            'model' => $resolvedProvider->model,
            'base_url' => $resolvedProvider->baseUrl,
            'max_tokens' => $resolvedProvider->maxTokens,
            'thinking_enabled' => $settings->isThinkingEnabled(),
            'thinking_budget' => $settings->getThinkingBudget(),
            'effort_level' => $settings->getEffortLevel(),
            'oauth_bearer' => $settings->isOauthBearer(),
            'headers' => $settings->getHeaders(),
        ];
    }
}
