<?php

namespace HaoCode\Services\Api;

/**
 * Add bounded jitter to locally calculated exponential retry delays.
 *
 * Server-provided Retry-After values are handled by the providers directly and
 * remain exact. Jitter is only for the SDK's own fallback delay so concurrent
 * clients do not retry in lockstep.
 *
 * @internal
 */
final class RetryDelay
{
    public static function withJitter(float $base, float $cap): float
    {
        if (! is_finite($base) || ! is_finite($cap) || $base <= 0 || $cap <= 0) {
            return 0.0;
        }

        $base = min($base, $cap);

        try {
            $factor = random_int(80, 120) / 100;
        } catch (\Throwable) {
            // A rare system RNG failure must not turn a recoverable provider
            // error into a second exception. Keep the deterministic delay.
            $factor = 1.0;
        }

        return min($base * $factor, $cap);
    }
}
