<?php

declare(strict_types=1);

namespace HaoCode\Services\Api;

/**
 * Formats configured provider endpoints for user-visible diagnostics without
 * exposing credentials, paths, query parameters, or fragments.
 *
 * @internal
 */
final class EndpointRedactor
{
    public static function origin(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (! is_array($parts)
            || ! is_string($scheme)
            || ! is_string($host)
            || preg_match('/^[a-z][a-z0-9+.-]*$/i', $scheme) !== 1
            || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            return '[redacted endpoint]';
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return strtolower($scheme).'://'.strtolower($host).$port;
    }
}
