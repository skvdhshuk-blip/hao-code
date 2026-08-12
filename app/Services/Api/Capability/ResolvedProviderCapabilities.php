<?php

declare(strict_types=1);

namespace HaoCode\Services\Api\Capability;

use HaoCode\Services\Api\EndpointRedactor;

/**
 * Provider capabilities resolved across provider, model, and endpoint levels.
 *
 * @internal
 */
final class ResolvedProviderCapabilities
{
    /**
     * @param array<string, array{status: CapabilityStatus, source: string, reason: string|null}> $provider
     * @param array<string, array{status: CapabilityStatus, source: string, reason: string|null}> $modelLevel
     * @param array<string, array{status: CapabilityStatus, source: string, reason: string|null}> $endpointLevel
     * @param array<string, array{status: CapabilityStatus, source: string, reason: string|null}> $effective
     */
    public function __construct(
        public readonly string $providerType,
        public readonly string $model,
        public readonly string $endpoint,
        public readonly array $provider,
        public readonly array $modelLevel,
        public readonly array $endpointLevel,
        public readonly array $effective,
    ) {}

    public function status(string $capability): CapabilityStatus
    {
        return $this->effective[$capability]['status'] ?? CapabilityStatus::Unknown;
    }

    /** @return array{status: CapabilityStatus, source: string, reason: string|null} */
    public function decision(string $capability): array
    {
        return $this->effective[$capability] ?? [
            'status' => CapabilityStatus::Unknown,
            'source' => 'unmatched',
            'reason' => 'No provider, model, or endpoint rule describes this capability.',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_type' => $this->providerType,
            'model' => $this->model,
            'endpoint' => $this->displayEndpoint(),
            'provider' => $this->serializeDecisions($this->provider),
            'model_level' => $this->serializeDecisions($this->modelLevel),
            'endpoint_level' => $this->serializeDecisions($this->endpointLevel),
            'effective' => $this->serializeDecisions($this->effective),
        ];
    }

    public function displayEndpoint(): string
    {
        return EndpointRedactor::origin($this->endpoint);
    }

    /**
     * @param array<string, array{status: CapabilityStatus, source: string, reason: string|null}> $decisions
     * @return array<string, array{status: string, source: string, reason: string|null}>
     */
    private function serializeDecisions(array $decisions): array
    {
        $serialized = [];
        foreach ($decisions as $capability => $decision) {
            $serialized[$capability] = [
                'status' => $decision['status']->value,
                'source' => $decision['source'],
                'reason' => $decision['reason'],
            ];
        }

        ksort($serialized);

        return $serialized;
    }
}
