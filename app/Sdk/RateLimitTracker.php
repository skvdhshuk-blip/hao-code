<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * Memory-only RPM/TPM rate-limit tracker per credential.
 *
 * Tracks requests-per-minute (RPM) and tokens-per-minute (TPM) in a sliding
 * one-minute window. If a credential exceeds its configured limits,
 * {@see checkBlocked()} returns true and emits a span.
 *
 * Storage is in-process only. A StorageInterface hook is reserved for a
 * future file-backed or Redis-backed implementation.
 *
 * @api
 */
class RateLimitTracker
{
    /**
     * Hook for an optional persistent storage backend.
     * Not implemented in this release — reserved for future use.
     *
     * @api
     */
    public ?RateLimitStorageInterface $storage = null;

    /** @var array<string, list<float>> request timestamps per credential id */
    private array $requestLog = [];

    /** @var array<string, list<array{ts: float, tokens: int}>> token log per credential id */
    private array $tokenLog = [];

    /** @var array<string, array{rpm: int, tpm: int}> per-credential limits */
    private array $limits = [];

    private ?PhoenixTracer $tracer;

    /** @var callable(): float */
    private $clock;

    public function __construct(?PhoenixTracer $tracer = null, ?callable $clock = null)
    {
        $this->tracer = $tracer;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Set RPM/TPM limits for a credential.
     *
     * @api
     */
    public function setLimit(Credential $credential, int $rpm, int $tpm = 0): void
    {
        $this->limits[$credential->id] = ['rpm' => $rpm, 'tpm' => $tpm];
    }

    /**
     * Record that a request was made with the given credential.
     *
     * @api
     */
    public function record(Credential $credential, int $tokens = 0): void
    {
        $now = ($this->clock)();
        $id = $credential->id;

        $this->requestLog[$id][] = $now;
        if ($tokens > 0) {
            $this->tokenLog[$id][] = ['ts' => $now, 'tokens' => $tokens];
        }

        $this->prune($id, $now);
    }

    /**
     * Check whether the credential is currently rate-limited.
     *
     * Returns true (blocked) when RPM or TPM exceeds the configured limit.
     * Emits a credential.rate_limit.block span when blocked.
     *
     * @api
     */
    public function checkBlocked(Credential $credential): bool
    {
        if (! isset($this->limits[$credential->id])) {
            return false;
        }

        $now = ($this->clock)();
        $id = $credential->id;
        $this->prune($id, $now);

        $rpm = count($this->requestLog[$id] ?? []);
        $tpm = array_sum(array_column($this->tokenLog[$id] ?? [], 'tokens'));
        $limits = $this->limits[$id];

        $rpmBlocked = $limits['rpm'] > 0 && $rpm >= $limits['rpm'];
        $tpmBlocked = $limits['tpm'] > 0 && $tpm >= $limits['tpm'];

        if ($rpmBlocked || $tpmBlocked) {
            $waitMs = $this->estimateWaitMs($id, $now);
            $span = $this->tracer?->startSpan('credential.rate_limit.block', PhoenixTracer::KIND_CHAIN, [
                'credential_id_hash' => $credential->idHash(),
                'rpm' => $rpm,
                'tpm' => (int) $tpm,
                'wait_ms' => $waitMs,
            ]);
            $span?->end();

            return true;
        }

        return false;
    }

    /**
     * Return current window counts for a credential.
     *
     * @api
     *
     * @return array{rpm: int, tpm: int}
     */
    public function windowStats(Credential $credential): array
    {
        $now = ($this->clock)();
        $id = $credential->id;
        $this->prune($id, $now);

        return [
            'rpm' => count($this->requestLog[$id] ?? []),
            'tpm' => (int) array_sum(array_column($this->tokenLog[$id] ?? [], 'tokens')),
        ];
    }

    private function prune(string $id, float $now): void
    {
        $cutoff = $now - 60.0;

        if (isset($this->requestLog[$id])) {
            $this->requestLog[$id] = array_values(
                array_filter($this->requestLog[$id], fn (float $ts): bool => $ts > $cutoff)
            );
        }

        if (isset($this->tokenLog[$id])) {
            $this->tokenLog[$id] = array_values(
                array_filter($this->tokenLog[$id], fn (array $entry): bool => $entry['ts'] > $cutoff)
            );
        }
    }

    private function estimateWaitMs(string $id, float $now): int
    {
        $requests = $this->requestLog[$id] ?? [];
        if ($requests === []) {
            return 0;
        }
        $oldest = min($requests);
        $resetAt = $oldest + 60.0;
        $remainingSec = max(0, $resetAt - $now);

        return (int) ($remainingSec * 1000);
    }
}
