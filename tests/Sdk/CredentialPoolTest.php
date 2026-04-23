<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RateLimitTracker;
use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\NoAvailableCredentialException;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamEvent;
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
        $credA = new Credential(apiKey: 'key-a', id: 'a');
        $credB = new Credential(apiKey: 'key-b', id: 'b');
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
                return [];
            }
        };

        $provider = new PooledProvider($inner, $pool, 'anthropic');
        $gen = $provider->streamMessages([], [], []);
        iterator_to_array($gen);

        $this->assertSame(2, $callCount);
        $this->assertSame('key-a', $usedKeys[0]);
        $this->assertSame('key-b', $usedKeys[1]);
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
