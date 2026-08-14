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

trait CredentialPoolTestTestPooledProviderRejectsReadonlyReflectionFallbackConcern
{

    public function test_pooled_provider_rejects_readonly_reflection_fallback(): void
    {
        $pool = new CredentialPool;
        $pool->add('legacy', new Credential(apiKey: 'pool-key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('readonly apiKey');

        iterator_to_array(
            (new PooledProvider(
                new ReadonlyLegacyCredentialProvider,
                $pool,
                'legacy',
            ))->streamMessages([], [], []),
        );
    }

    public function test_pooled_provider_does_not_replay_after_response_state_is_committed(): void
    {
        $pool = new CredentialPool;
        $pool->addMany('anthropic', [
            new Credential(apiKey: 'key-a'),
            new Credential(apiKey: 'key-b'),
        ]);
        $state = (object) ['calls' => 0, 'usedKeys' => []];

        $inner = new class($state) implements ApiKeyAwareProvider
        {
            public function __construct(
                private readonly object $state,
                private string $apiKey = '',
            ) {}

            public function withApiKey(string $apiKey): LlmProvider
            {
                $provider = clone $this;
                $provider->apiKey = $apiKey;

                return $provider;
            }

            public function streamMessages(array $sp, array $msgs, array $tools, ?callable $onRaw = null, ?callable $abort = null): \Generator
            {
                $this->state->calls++;
                $this->state->usedKeys[] = $this->apiKey;
                yield new StreamEvent('content_block_delta', [
                    'delta' => ['type' => 'text_delta', 'text' => 'partial'],
                ]);
                throw new ApiErrorException('Rate limited after output', 'rate_limit_error', 429);
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };

        $events = [];
        $caught = null;
        try {
            foreach ((new PooledProvider($inner, $pool, 'anthropic'))->streamMessages([], [], []) as $event) {
                $events[] = $event;
            }
        } catch (ApiErrorException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ApiErrorException::class, $caught);
        $this->assertCount(1, $events);
        $this->assertSame(1, $state->calls);
        $this->assertSame(['key-a'], $state->usedKeys);
    }

    public function test_pooled_provider_success_resets_consecutive_errors(): void
    {
        $pool = new CredentialPool;
        $credential = new Credential(apiKey: 'key-a');
        $pool->add('anthropic', $credential);
        $pool->markError($credential);
        $pool->markError($credential);

        $inner = new class implements LlmProvider
        {
            private string $apiKey = '';

            public function streamMessages(array $sp, array $msgs, array $tools, ?callable $onRaw = null, ?callable $abort = null): \Generator
            {
                yield new StreamEvent('ping', []);
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };

        iterator_to_array((new PooledProvider($inner, $pool, 'anthropic'))->streamMessages([], [], []));
        $pool->markError($credential);
        $pool->markError($credential);

        $this->assertSame($credential->id, $pool->pickNext('anthropic')->id);
    }

    public function test_pooled_provider_selects_credentials_from_the_current_provider_type(): void
    {
        $pool = new CredentialPool;
        $pool->add('anthropic', new Credential(apiKey: 'anthropic-pool-key'));
        $pool->add('openai', new Credential(apiKey: 'openai-pool-key'));
        $settings = new SettingsManager;
        $settings->set('provider_type', 'anthropic');
        $settings->set('model', 'claude-sonnet-4-6');
        $state = (object) ['keys' => []];
        $inner = new class($state) implements ApiKeyAwareProvider {
            public function __construct(
                private readonly object $state,
                private string $apiKey = '',
            ) {}

            public function withApiKey(string $apiKey): LlmProvider
            {
                $provider = clone $this;
                $provider->apiKey = $apiKey;

                return $provider;
            }

            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                $this->state->keys[] = $this->apiKey;
                yield new StreamEvent('ping', []);
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };
        $provider = new PooledProvider(
            $inner,
            $pool,
            'anthropic',
            settingsManager: $settings,
        );

        iterator_to_array($provider->streamMessages([], [], []));
        $settings->set('provider_type', 'openai');
        $settings->set('model', 'gpt-5.2');
        iterator_to_array($provider->streamMessages([], [], []));

        $this->assertSame(['anthropic-pool-key', 'openai-pool-key'], $state->keys);
    }

    public function test_hao_code_config_credential_pool_defaults_to_null(): void
    {
        $config = new HaoCodeConfig(apiKey: 'test-key');
        $this->assertNull($config->credentialPool);
    }

    public function test_hao_code_config_accepts_credential_pool(): void
    {
        $pool = new CredentialPool;
        $config = new HaoCodeConfig(apiKey: 'test-key', credentialPool: $pool);
        $this->assertSame($pool, $config->credentialPool);
    }

    public function test_hao_code_config_make_preserves_null_pool(): void
    {
        $config = HaoCodeConfig::make('my-key');
        $this->assertNull($config->credentialPool);
    }
}
