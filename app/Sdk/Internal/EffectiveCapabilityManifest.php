<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Services\Api\Capability\ResolvedProviderCapabilities;

/**
 * Effective capabilities for one SDK run before external resources are opened.
 *
 * @internal
 */
final class EffectiveCapabilityManifest
{
    /**
     * @param array<string, bool> $agent
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $sandbox
     * @param array<string, mixed> $permission
     * @param list<string> $violations
     */
    public function __construct(
        public readonly ResolvedProviderCapabilities $provider,
        public readonly array $agent,
        public readonly array $tools,
        public readonly array $sandbox,
        public readonly array $permission,
        public readonly array $violations,
    ) {}

    public function assertSupported(): void
    {
        if ($this->violations === []) {
            return;
        }

        throw new UnsupportedCapabilityException(
            "Unsupported run capability configuration before provider request:\n- "
            .implode("\n- ", $this->violations),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'agent' => $this->agent,
            'provider' => $this->provider->toArray(),
            'tools' => $this->tools,
            'sandbox' => $this->sandbox,
            'permission' => $this->permission,
            'violations' => $this->violations,
        ];
    }
}
