<?php

declare(strict_types=1);

namespace HaoCode\Services\Api;

use HaoCode\Services\Settings\ProviderType;

/**
 * Run-local registry for provider wire adapters.
 *
 * @internal
 */
final class ProviderRegistry
{
    /** @var array<string, LlmProvider> */
    private array $providers = [];

    /**
     * @param array<string, LlmProvider> $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $type => $provider) {
            $this->register($type, $provider);
        }
    }

    public function register(string $type, LlmProvider $provider): void
    {
        $this->providers[ProviderType::normalizeRequired($type)] = $provider;
    }

    public function get(string $type): LlmProvider
    {
        $normalized = ProviderType::normalizeRequired($type);
        $provider = $this->providers[$normalized] ?? null;

        if ($provider === null) {
            throw new \LogicException("Provider type \"{$normalized}\" is not registered.");
        }

        return $provider;
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = array_keys($this->providers);
        sort($types);

        return $types;
    }
}
