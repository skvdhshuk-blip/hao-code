<?php

namespace HaoCode\Tools\WebFetch;

/**
 * Redirect-target resolution for WebFetch.
 *
 * Split out of WebFetchToolConstructConcern so the fetch path and the
 * URI-reference arithmetic stay separately readable.
 *
 * @internal
 */
trait WebFetchToolResolveRedirectConcern
{
    /**
     * Resolve a (possibly relative) redirect Location against the base URL
     * using parse_url so every URI-reference form is handled:
     *   - absolute: https://other.example/path
     *   - scheme-relative: //other.example/path
     *   - absolute-path: /path
     *   - query-only: ?page=2
     *   - fragment: #section (ignored for fetching)
     *   - relative: ../next, ./next, next
     *   - bracketed IPv6 authority: https://[::1]:8080/
     */
    private function resolveRedirect(string $base, string $location): string
    {
        $location = trim($location);
        $locationParts = parse_url($location);
        if ($locationParts === false) {
            throw new \RuntimeException("Invalid redirect Location: {$location}");
        }

        // RFC 3986 section 5.2: an absolute URI replaces the base entirely,
        // but its path still goes through remove_dot_segments.
        if (isset($locationParts['scheme'])) {
            if (! isset($locationParts['host'])) {
                // The SSRF guard will reject non-HTTP opaque URIs; retain the
                // reference here so redirect resolution does not invent an
                // authority for forms such as `g:h`.
                return $this->withoutFragment($location);
            }

            return $this->formatAbsoluteUri((string) $locationParts['scheme'], $locationParts);
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            throw new \RuntimeException("Invalid base URL: {$base}");
        }

        $scheme = (string) $baseParts['scheme'];
        $authority = $this->formatAuthority($baseParts);

        // A network-path reference replaces the authority while retaining the
        // base scheme (including bracketed IPv6 and an optional port).
        if (str_starts_with($location, '//')) {
            return $this->formatAbsoluteUri($scheme, $locationParts);
        }

        $basePath = (string) ($baseParts['path'] ?? '');
        $locationPath = (string) ($locationParts['path'] ?? '');

        if ($locationPath === '') {
            $path = $basePath === '' ? '/' : $basePath;
        } elseif (str_starts_with($locationPath, '/')) {
            $path = $this->normalizePath($locationPath);
        } else {
            // Merge with the base path up to and including its last slash.
            // Unlike dirname(), this preserves a trailing slash: /foo/ + bar
            // resolves to /foo/bar, as required by RFC 3986.
            $slash = strrpos($basePath, '/');
            $prefix = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $path = $this->normalizePath($prefix.$locationPath);
        }

        $query = array_key_exists('query', $locationParts)
            ? '?'.(string) $locationParts['query']
            : ($locationPath === '' && isset($baseParts['query']) ? '?'.(string) $baseParts['query'] : '');

        return "{$scheme}://{$authority}{$path}{$query}";
    }

    /** @param array<string, mixed> $parts */
    private function formatAuthority(array $parts): string
    {
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $authority = '';
        if (isset($parts['user'])) {
            $authority .= (string) $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':'.(string) $parts['pass'];
            }
            $authority .= '@';
        }
        $authority .= str_contains($host, ':') ? '['.$host.']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':'.(int) $parts['port'];
        }

        return $authority;
    }

    /** @param array<string, mixed> $parts */
    private function formatAbsoluteUri(string $scheme, array $parts): string
    {
        $authority = $this->formatAuthority($parts);
        if ($authority === '') {
            throw new \RuntimeException('Redirect URL is missing an authority.');
        }

        $path = isset($parts['path']) && $parts['path'] !== ''
            ? $this->normalizePath((string) $parts['path'])
            : '';
        $query = array_key_exists('query', $parts) ? '?'.(string) $parts['query'] : '';

        return "{$scheme}://{$authority}{$path}{$query}";
    }

    private function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }
}
