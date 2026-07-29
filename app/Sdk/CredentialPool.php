<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Api\NoAvailableCredentialException;
use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * Manages a pool of API credentials per provider with round-robin and priority selection.
 *
 * Credentials are bucketed by provider name. On each pick, healthy credentials
 * are sorted by descending priority; among equal-priority credentials, the
 * algorithm cycles round-robin.
 *
 * Single-key users are not affected — if no pool is configured the original
 * HaoCodeConfig::$apiKey path continues to work without change.
 *
 * @api
 */
class CredentialPool
{
    /** @var array<string, list<Credential>> */
    private array $credentials = [];

    /** @var array<string, int> round-robin cursor per provider */
    private array $cursor = [];

    /** @var array<string, array{exhausted_at: float, error_count: int}> keyed by credential id */
    private array $exhausted = [];

    /** @var array<string, int> error count per credential id */
    private array $errorCounts = [];

    /** Seconds before an exhausted credential becomes eligible again */
    private float $exhaustedTtlSeconds;

    private ?PhoenixTracer $tracer;

    /** @var callable(): float */
    private $clock;

    public function __construct(float $exhaustedTtlSeconds = 60.0, ?PhoenixTracer $tracer = null, ?callable $clock = null)
    {
        $this->exhaustedTtlSeconds = $exhaustedTtlSeconds;
        $this->tracer = $tracer;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Register a credential for a provider bucket.
     *
     * @api
     */
    public function add(string $provider, Credential $credential): void
    {
        $this->credentials[$provider][] = $credential;
    }

    /**
     * Register multiple credentials at once.
     *
     * @api
     *
     * @param  list<Credential>  $credentials
     */
    public function addMany(string $provider, array $credentials): void
    {
        foreach ($credentials as $c) {
            $this->add($provider, $c);
        }
    }

    /**
     * Pick the next available credential for the given provider.
     *
     * Selects by priority (descending) then round-robin among equal-priority healthy entries.
     * Returns null when no credentials are registered (single-key fallback mode).
     *
     * @api
     *
     * @throws NoAvailableCredentialException when all credentials are exhausted
     */
    public function pickNext(string $provider): ?Credential
    {
        if (! isset($this->credentials[$provider]) || $this->credentials[$provider] === []) {
            return null;
        }

        $healthy = $this->getHealthy($provider);
        $poolSize = count($this->credentials[$provider]);
        $healthyCount = count($healthy);
        $exhaustedCount = $poolSize - $healthyCount;

        if ($healthy === []) {
            $span = $this->tracer?->startSpan('credential.pool.health', PhoenixTracer::KIND_CHAIN, [
                'provider' => $provider,
                'healthy_count' => 0,
                'exhausted_count' => $exhaustedCount,
            ]);
            $span?->end();
            throw new NoAvailableCredentialException(
                "All credentials for provider '{$provider}' are exhausted."
            );
        }

        // Sort by priority descending; stable among equals so round-robin order is preserved
        usort($healthy, fn (Credential $a, Credential $b): int => $b->priority <=> $a->priority);

        // Pick within the top priority group using round-robin
        $topPriority = $healthy[0]->priority;
        $topGroup = array_values(array_filter($healthy, fn (Credential $c): bool => $c->priority === $topPriority));

        $cursor = $this->cursor[$provider] ?? 0;
        $idx = $cursor % count($topGroup);
        $this->cursor[$provider] = ($idx + 1) % count($topGroup);

        $picked = $topGroup[$idx];

        $span = $this->tracer?->startSpan('credential.pool.pick', PhoenixTracer::KIND_CHAIN, [
            'provider' => $provider,
            'credential_id_hash' => $picked->idHash(),
            'algorithm' => 'round_robin_priority',
            'pool_size' => $poolSize,
        ]);
        $span?->end();

        $healthSpan = $this->tracer?->startSpan('credential.pool.health', PhoenixTracer::KIND_CHAIN, [
            'provider' => $provider,
            'healthy_count' => $healthyCount,
            'exhausted_count' => $exhaustedCount,
        ]);
        $healthSpan?->end();

        return $picked;
    }

    /**
     * Mark a credential as rate-limited / exhausted.
     *
     * The credential will be excluded from picks for $exhaustedTtlSeconds.
     *
     * @api
     */
    public function markExhausted(Credential $credential, ?float $retryAfterSeconds = null): void
    {
        $ttl = $retryAfterSeconds ?? $this->exhaustedTtlSeconds;
        $key = $credential->idHash();
        $this->exhausted[$key] = [
            'exhausted_at' => ($this->clock)(),
            'ttl' => $ttl,
            'error_count' => ($this->exhausted[$key]['error_count'] ?? 0),
        ];
    }

    /**
     * Record an API error for a credential. After 3 consecutive errors, the
     * credential is temporarily exhausted.
     *
     * @api
     */
    public function markError(Credential $credential): void
    {
        $key = $credential->idHash();
        $this->errorCounts[$key] = ($this->errorCounts[$key] ?? 0) + 1;

        if ($this->errorCounts[$key] >= 3) {
            $this->markExhausted($credential);
        }
    }

    /**
     * Reset the consecutive non-rate-limit error count after a complete stream.
     *
     * An active rate-limit exhaustion TTL is intentionally retained.
     *
     * @internal
     */
    public function markSuccess(Credential $credential): void
    {
        unset($this->errorCounts[$credential->idHash()]);
    }

    /**
     * Manually restore a credential to healthy status.
     *
     * @api
     */
    public function restore(Credential $credential): void
    {
        $key = $credential->idHash();
        unset($this->exhausted[$key], $this->errorCounts[$key]);
    }

    /**
     * Return pool stats for all registered providers.
     *
     * @api
     *
     * @return array<string, array{total: int, healthy: int, exhausted: int}>
     */
    public function getStats(): array
    {
        $stats = [];
        foreach ($this->credentials as $provider => $all) {
            $healthy = count($this->getHealthy($provider));
            $stats[$provider] = [
                'total' => count($all),
                'healthy' => $healthy,
                'exhausted' => count($all) - $healthy,
            ];
        }

        return $stats;
    }

    /**
     * Check if a provider has any registered credentials.
     *
     * @api
     */
    public function hasProvider(string $provider): bool
    {
        return isset($this->credentials[$provider]) && $this->credentials[$provider] !== [];
    }

    /**
     * @return list<Credential>
     */
    private function getHealthy(string $provider): array
    {
        $now = ($this->clock)();

        return array_values(array_filter(
            $this->credentials[$provider] ?? [],
            function (Credential $c) use ($now): bool {
                $key = $c->idHash();
                if (! isset($this->exhausted[$key])) {
                    return true;
                }
                $ttl = $this->exhausted[$key]['ttl'] ?? $this->exhaustedTtlSeconds;
                if ($now - $this->exhausted[$key]['exhausted_at'] >= $ttl) {
                    // TTL expired — auto-restore
                    unset($this->exhausted[$key], $this->errorCounts[$key]);

                    return true;
                }

                return false;
            }
        ));
    }
}
