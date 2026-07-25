<?php

namespace HaoCode\Services\Settings;

/**
 * One provider connection resolved as an indivisible unit.
 *
 * @internal
 */
final class ResolvedProviderConfig
{
    public function __construct(
        public readonly string $providerType,
        public readonly ?string $providerName,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly string $baseUrl,
        public readonly int $maxTokens,
        public readonly int $contextWindow,
    ) {}
}
