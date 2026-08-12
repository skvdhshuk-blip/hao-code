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
    // --- Credential DTO ---

    public function test_credential_make_generates_stable_id(): void
    {
        $c1 = Credential::make('key-abc');
        $c2 = Credential::make('key-abc');
        $this->assertSame($c1->id, $c2->id);
    }

    public function test_credential_id_hash_is_non_reversible_and_fixed_length(): void
    {
        $c = Credential::make('secret-api-key');
        $hash = $c->idHash();
        $this->assertSame(12, strlen($hash));
        $this->assertStringNotContainsString('secret', $hash);
    }

    public function test_credential_explicit_id(): void
    {
        $c = new Credential(apiKey: 'key', id: 'my-id', priority: 5);
        $this->assertSame('my-id', $c->id);
        $this->assertSame(5, $c->priority);
    }

    public function test_rejects_negative_exhaustion_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CredentialPool(exhaustedTtlSeconds: -1.0);
    }

    public function test_rejects_non_finite_exhaustion_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CredentialPool(exhaustedTtlSeconds: NAN);
    }

    public function test_rejects_negative_retry_after(): void
    {
        $pool = new CredentialPool;

        $this->expectException(\InvalidArgumentException::class);
        $pool->markExhausted(Credential::make('key'), -1.0);
    }

    // --- CredentialPool round-robin ---

    public function test_single_credential_always_returns_same(): void
    {
        $pool = new CredentialPool;
        $cred = Credential::make('key-1');
        $pool->add('anthropic', $cred);

        $picked1 = $pool->pickNext('anthropic');
        $picked2 = $pool->pickNext('anthropic');
        $this->assertSame($cred->id, $picked1->id);
        $this->assertSame($cred->id, $picked2->id);
    }

    public function test_round_robin_cycles_through_equal_priority(): void
    {
        $pool = new CredentialPool;
        $a = Credential::make('key-a');
        $b = Credential::make('key-b');
        $c = Credential::make('key-c');
        $pool->addMany('anthropic', [$a, $b, $c]);

        $picks = [];
        for ($i = 0; $i < 6; $i++) {
            $picks[] = $pool->pickNext('anthropic')->id;
        }

        // Should cycle a→b→c→a→b→c
        $this->assertSame($picks[0], $picks[3]);
        $this->assertSame($picks[1], $picks[4]);
        $this->assertSame($picks[2], $picks[5]);
        $this->assertCount(3, array_unique(array_slice($picks, 0, 3)));
    }

    public function test_priority_credential_always_picked_first(): void
    {
        $pool = new CredentialPool;
        $low = Credential::make('key-low', priority: 0);
        $high = new Credential(apiKey: 'key-high', id: 'high-id', priority: 10);
        $pool->addMany('openai', [$low, $high]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('high-id', $pool->pickNext('openai')->id);
        }
    }

    public function test_no_credentials_returns_null(): void
    {
        $pool = new CredentialPool;
        $this->assertNull($pool->pickNext('anthropic'));
    }

    public function test_unknown_provider_returns_null(): void
    {
        $pool = new CredentialPool;
        $pool->add('anthropic', Credential::make('key'));
        $this->assertNull($pool->pickNext('openai'));
    }

    public function test_credentials_without_explicit_ids_are_tracked_independently(): void
    {
        $pool = new CredentialPool;
        $first = new Credential(apiKey: 'first-key');
        $second = new Credential(apiKey: 'second-key');
        $pool->addMany('anthropic', [$first, $second]);

        $pool->markExhausted($first);

        $this->assertSame($second->idHash(), $pool->pickNext('anthropic')->idHash());
    }

    // --- markExhausted / restore ---

    public function test_exhausted_credential_is_skipped(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $a = Credential::make('key-a');
        $b = Credential::make('key-b');
        $pool->addMany('anthropic', [$a, $b]);
        $pool->markExhausted($a);

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($b->id, $pool->pickNext('anthropic')->id);
        }
    }

    public function test_all_exhausted_throws(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $a = Credential::make('key-a');
        $pool->add('anthropic', $a);
        $pool->markExhausted($a);

        $this->expectException(NoAvailableCredentialException::class);
        $pool->pickNext('anthropic');
    }

    public function test_exhausted_credential_restores_after_ttl(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now): float {
            return $now;
        };

        $pool = new CredentialPool(exhaustedTtlSeconds: 30.0, clock: $clock);
        $a = Credential::make('key-a');
        $pool->add('anthropic', $a);
        $pool->markExhausted($a);

        // Still exhausted
        $this->expectException(NoAvailableCredentialException::class);
        $pool->pickNext('anthropic');
    }

    public function test_exhausted_credential_restores_after_ttl_elapsed(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now): float {
            return $now;
        };

        $pool = new CredentialPool(exhaustedTtlSeconds: 30.0, clock: $clock);
        $a = Credential::make('key-a');
        $pool->add('anthropic', $a);
        $pool->markExhausted($a);

        // Advance time past TTL
        $now += 31.0;
        $picked = $pool->pickNext('anthropic');
        $this->assertSame($a->id, $picked->id);
    }

    public function test_restore_brings_credential_back(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 9999.0);
        $a = Credential::make('key-a');
        $pool->add('anthropic', $a);
        $pool->markExhausted($a);
        $pool->restore($a);

        $this->assertSame($a->id, $pool->pickNext('anthropic')->id);
    }

    public function test_mark_error_three_times_exhausts(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $a = Credential::make('key-a');
        $b = Credential::make('key-b');
        $pool->addMany('anthropic', [$a, $b]);

        $pool->markError($a);
        $pool->markError($a);
        $pool->markError($a); // 3rd error → exhausted

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($b->id, $pool->pickNext('anthropic')->id);
        }
    }

    public function test_mark_success_resets_only_the_consecutive_error_count(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $credential = Credential::make('key-a');
        $pool->add('anthropic', $credential);

        $pool->markError($credential);
        $pool->markError($credential);
        $pool->markSuccess($credential);
        $pool->markError($credential);
        $pool->markError($credential);

        $this->assertSame($credential->id, $pool->pickNext('anthropic')->id);
    }

    public function test_mark_success_does_not_clear_an_active_rate_limit_ttl(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $credential = Credential::make('key-a');
        $pool->add('anthropic', $credential);
        $pool->markExhausted($credential);

        $pool->markSuccess($credential);

        $this->expectException(NoAvailableCredentialException::class);
        $pool->pickNext('anthropic');
    }

    // --- getStats ---

    public function test_get_stats_returns_correct_counts(): void
    {
        $pool = new CredentialPool(exhaustedTtlSeconds: 60.0);
        $a = Credential::make('key-a');
        $b = Credential::make('key-b');
        $pool->addMany('anthropic', [$a, $b]);
        $pool->markExhausted($a);

        $stats = $pool->getStats();
        $this->assertSame(2, $stats['anthropic']['total']);
        $this->assertSame(1, $stats['anthropic']['healthy']);
        $this->assertSame(1, $stats['anthropic']['exhausted']);
    }

    // --- RateLimitTracker ---

    public function test_rate_limit_tracker_not_blocked_by_default(): void
    {
        $tracker = new RateLimitTracker;
        $cred = Credential::make('key');
        $tracker->setLimit($cred, rpm: 10);
        $this->assertFalse($tracker->checkBlocked($cred));
    }

    public function test_rate_limit_tracker_blocks_when_rpm_exceeded(): void
    {
        $tracker = new RateLimitTracker;
        $cred = Credential::make('key');
        $tracker->setLimit($cred, rpm: 3);

        $tracker->record($cred);
        $tracker->record($cred);
        $tracker->record($cred);

        $this->assertTrue($tracker->checkBlocked($cred));
    }

    public function test_rate_limit_tracker_blocks_when_tpm_exceeded(): void
    {
        $tracker = new RateLimitTracker;
        $cred = Credential::make('key');
        $tracker->setLimit($cred, rpm: 100, tpm: 1000);

        $tracker->record($cred, 600);
        $tracker->record($cred, 500); // total 1100 > 1000

        $this->assertTrue($tracker->checkBlocked($cred));
    }

    public function test_rate_limit_tracker_window_stats(): void
    {
        $tracker = new RateLimitTracker;
        $cred = Credential::make('key');
        $tracker->setLimit($cred, rpm: 100);

        $tracker->record($cred, 200);
        $tracker->record($cred, 300);

        $stats = $tracker->windowStats($cred);
        $this->assertSame(2, $stats['rpm']);
        $this->assertSame(500, $stats['tpm']);
    }

    public function test_rate_limit_tracker_prunes_old_entries(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now): float {
            return $now;
        };

        $tracker = new RateLimitTracker(clock: $clock);
        $cred = Credential::make('key');
        $tracker->setLimit($cred, rpm: 2);

        $tracker->record($cred);
        $tracker->record($cred); // window full

        // Advance past 60s window
        $now += 61.0;
        $this->assertFalse($tracker->checkBlocked($cred));
    }

    // --- PooledProvider passthrough when no pool configured ---

    public function test_pooled_provider_passthrough_without_credentials(): void
    {
        $pool = new CredentialPool;
        $inner = new class implements LlmProvider
        {
            public int $calls = 0;

            public function streamMessages(array $sp, array $msgs, array $tools, ?callable $onRaw = null, ?callable $abort = null): \Generator
            {
                $this->calls++;
                yield new StreamEvent('ping', []);
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };

        $provider = new PooledProvider($inner, $pool, 'anthropic');
        $gen = $provider->streamMessages([], [], []);
        iterator_to_array($gen);

        $this->assertSame(1, $inner->calls);
    }

    // --- PooledProvider injects credential and retries on 429 ---

    public function test_pooled_provider_rotates_on_rate_limit_error(): void
    {
        $pool = new CredentialPool;
        $credA = new Credential(apiKey: 'key-a');
        $credB = new Credential(apiKey: 'key-b');
        $pool->addMany('anthropic', [$credA, $credB]);

        $usedKeys = [];
        $callCount = 0;

        $inner = new class($usedKeys, $callCount) implements LlmProvider
        {
            public function __construct(
                private array &$usedKeys,
                private int &$callCount,
                private string $apiKey = '',
            ) {}

            public function streamMessages(array $sp, array $msgs, array $tools, ?callable $onRaw = null, ?callable $abort = null): \Generator
            {
                $this->usedKeys[] = $this->apiKey;
                $this->callCount++;
                if ($this->callCount === 1) {
                    throw new ApiErrorException('Rate limited', 'rate_limit_error', 429);
                }
                yield new StreamEvent('ping', []);
            }

            public function getLastRateLimitHeaders(): array
            {
                return ['retry-after' => '-1'];
            }
        };

        $provider = new PooledProvider($inner, $pool, 'anthropic');
        $gen = $provider->streamMessages([], [], []);
        iterator_to_array($gen);

        $this->assertSame(2, $callCount);
        $this->assertSame('key-a', $usedKeys[0]);
        $this->assertSame('key-b', $usedKeys[1]);
        $property = (new \ReflectionObject($inner))->getProperty('apiKey');
        $property->setAccessible(true);
        $this->assertSame('', $property->getValue($inner), 'pool keys must never mutate the shared provider');
    }

    public function test_pooled_provider_uses_retry_after_header_for_exhaustion_ttl(): void
    {
        $now = 1_000.0;
        $pool = new CredentialPool(
            exhaustedTtlSeconds: 60.0,
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $pool->addMany('openai_chat', [
            new Credential(apiKey: 'key-a'),
            new Credential(apiKey: 'key-b'),
        ]);
        $calls = 0;

        $inner = new class($calls) implements LlmProvider
        {
            public function __construct(
                private int &$calls,
                private string $apiKey = '',
            ) {}

            public function streamMessages(array $sp, array $msgs, array $tools, ?callable $onRaw = null, ?callable $abort = null): \Generator
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new ApiErrorException('Rate limited', 'rate_limit_error', 429);
                }

                yield new StreamEvent('ping', []);
            }

            public function getLastRateLimitHeaders(): array
            {
                return ['retry-after' => '30', 'x-ratelimit-remaining-requests' => '0'];
            }
        };

        iterator_to_array(
            (new PooledProvider($inner, $pool, 'openai_chat'))->streamMessages([], [], []),
        );

        $this->assertSame(['total' => 2, 'healthy' => 1, 'exhausted' => 1], $pool->getStats()['openai_chat']);

        $now += 31.0;
        $this->assertSame(['total' => 2, 'healthy' => 2, 'exhausted' => 0], $pool->getStats()['openai_chat']);
    }

    public function test_pooled_provider_applies_key_to_inherited_private_property_on_clone(): void
    {
        $pool = new CredentialPool;
        $pool->add('legacy', new Credential(apiKey: 'pool-key'));
        $state = (object) ['keys' => []];
        $inner = new LegacyInheritedCredentialProvider($state);

        iterator_to_array(
            (new PooledProvider($inner, $pool, 'legacy'))->streamMessages([], [], []),
        );

        $this->assertSame(['pool-key'], $state->keys);
        $this->assertSame('original-key', $inner->originalApiKey());
    }

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

    // --- HaoCodeConfig BC: credentialPool is optional ---

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
