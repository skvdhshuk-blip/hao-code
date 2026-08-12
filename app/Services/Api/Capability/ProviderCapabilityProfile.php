<?php

declare(strict_types=1);

namespace HaoCode\Services\Api\Capability;

/**
 * Capability rules for one provider wire format.
 *
 * @internal
 */
final class ProviderCapabilityProfile
{
    /**
     * @param array<string, CapabilityStatus|array{status: CapabilityStatus, reason?: string}> $providerCapabilities
     * @param list<array{pattern: string, label: string, capabilities: array<string, CapabilityStatus|array{status: CapabilityStatus, reason?: string}>}> $modelRules
     * @param list<array{pattern: string, label: string, capabilities: array<string, CapabilityStatus|array{status: CapabilityStatus, reason?: string}>}> $endpointRules
     */
    public function __construct(
        public readonly string $providerType,
        private readonly array $providerCapabilities,
        private readonly array $modelRules = [],
        private readonly array $endpointRules = [],
    ) {}

    public function resolve(string $model, string $endpoint): ResolvedProviderCapabilities
    {
        $provider = $this->decisions(
            $this->providerCapabilities,
            'provider:'.$this->providerType,
        );
        $modelLevel = $this->resolveRules($this->modelRules, $model, 'model');
        $endpointLevel = $this->resolveRules($this->endpointRules, $endpoint, 'endpoint');

        return new ResolvedProviderCapabilities(
            providerType: $this->providerType,
            model: $model,
            endpoint: $endpoint,
            provider: $provider,
            modelLevel: $modelLevel,
            endpointLevel: $endpointLevel,
            effective: array_replace($provider, $modelLevel, $endpointLevel),
        );
    }

    /**
     * @param list<array{pattern: string, label: string, capabilities: array<string, CapabilityStatus|array{status: CapabilityStatus, reason?: string}>}> $rules
     * @return array<string, array{status: CapabilityStatus, source: string, reason: string|null}>
     */
    private function resolveRules(array $rules, string $value, string $level): array
    {
        $resolved = [];
        foreach ($rules as $rule) {
            $matches = preg_match($rule['pattern'], $value);
            if ($matches === false) {
                throw new \LogicException("Invalid {$level} capability pattern: {$rule['pattern']}");
            }
            if ($matches !== 1) {
                continue;
            }

            $resolved = array_replace(
                $resolved,
                $this->decisions($rule['capabilities'], $level.':'.$rule['label']),
            );
        }

        return $resolved;
    }

    /**
     * @param array<string, CapabilityStatus|array{status: CapabilityStatus, reason?: string}> $capabilities
     * @return array<string, array{status: CapabilityStatus, source: string, reason: string|null}>
     */
    private function decisions(array $capabilities, string $source): array
    {
        $decisions = [];
        foreach ($capabilities as $capability => $value) {
            $status = $value instanceof CapabilityStatus ? $value : ($value['status'] ?? null);
            if (! $status instanceof CapabilityStatus) {
                throw new \LogicException("Capability \"{$capability}\" has no valid status.");
            }
            $decisions[$capability] = [
                'status' => $status,
                'source' => $source,
                'reason' => is_array($value) && is_string($value['reason'] ?? null)
                    ? $value['reason']
                    : null,
            ];
        }

        return $decisions;
    }
}
