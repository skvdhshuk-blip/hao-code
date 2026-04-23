<?php

namespace HaoCode\Sdk;

/**
 * Hook interface for a persistent rate-limit storage backend.
 *
 * The memory-only {@see RateLimitTracker} checks this field but no
 * implementation ships in this release. A file-backed or Redis-backed
 * implementation can be injected in a future release without changing
 * the public API.
 *
 * @api
 */
interface RateLimitStorageInterface
{
    /**
     * Increment the request counter for a credential within the current window.
     *
     * @return int New count after increment
     */
    public function incrementRequests(string $credentialId, int $windowSeconds = 60): int;

    /**
     * Increment the token counter for a credential within the current window.
     *
     * @return int New total after increment
     */
    public function incrementTokens(string $credentialId, int $tokens, int $windowSeconds = 60): int;

    /**
     * Read current window counters.
     *
     * @return array{rpm: int, tpm: int}
     */
    public function getWindowStats(string $credentialId, int $windowSeconds = 60): array;
}
