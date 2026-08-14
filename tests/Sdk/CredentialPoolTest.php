<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RateLimitTracker;
use HaoCode\Services\Api\ApiKeyAwareProvider;
use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\NoAvailableCredentialException;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;

class CredentialPoolTest extends TestCase
{
    use CredentialPoolTestTestCredentialMakeGeneratesStableIdConcern;
    use CredentialPoolTestTestPooledProviderRejectsReadonlyReflectionFallbackConcern;

    // --- Credential DTO ---

    // --- CredentialPool round-robin ---

    // --- markExhausted / restore ---

    // --- getStats ---

    // --- RateLimitTracker ---

    // --- PooledProvider passthrough when no pool configured ---

    // --- PooledProvider injects credential and retries on 429 ---

    // --- HaoCodeConfig BC: credentialPool is optional ---
}

class LegacyCredentialProviderBase implements LlmProvider
{
    public function __construct(
        private readonly object $state,
        private string $apiKey = 'original-key',
    ) {}

    public function streamMessages(
        array $sp,
        array $msgs,
        array $tools,
        ?callable $onRaw = null,
        ?callable $abort = null,
    ): \Generator {
        $this->state->keys[] = $this->apiKey;
        yield new StreamEvent('ping', []);
    }

    public function getLastRateLimitHeaders(): array
    {
        return [];
    }

    public function originalApiKey(): string
    {
        return $this->apiKey;
    }
}

final class LegacyInheritedCredentialProvider extends LegacyCredentialProviderBase
{
}

final class ReadonlyLegacyCredentialProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey = 'original-key',
    ) {}

    public function streamMessages(
        array $sp,
        array $msgs,
        array $tools,
        ?callable $onRaw = null,
        ?callable $abort = null,
    ): \Generator {
        if (false) {
            yield;
        }
    }

    public function getLastRateLimitHeaders(): array
    {
        return [];
    }
}
