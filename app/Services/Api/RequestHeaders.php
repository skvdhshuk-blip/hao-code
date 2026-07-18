<?php

namespace HaoCode\Services\Api;

/**
 * Helpers for caller-supplied custom HTTP request headers.
 *
 * SDK consumers may attach extra headers (e.g. GitHub Copilot's
 * `Editor-Version` / `Copilot-Integration-Id`) that are merged into every
 * provider request. Custom values win over the provider's hardcoded
 * defaults for the same header name (user sovereignty), except
 * authentication headers (`Authorization`, `x-api-key`), which always stay
 * under the provider's auth logic so a custom header can never silently
 * replace or bypass credentials.
 *
 * @internal
 */
final class RequestHeaders
{
    /** Header names (lowercased) that custom headers can never override. */
    private const PROTECTED = ['authorization' => true, 'x-api-key' => true];

    /**
     * Filter a raw user-supplied map down to valid string => string header
     * entries. Entries with non-string keys/values, empty names, or CR/LF
     * (header-injection vectors for the native stream transport) are dropped.
     *
     * @param array<mixed> $headers
     *
     * @return array<string, string>
     */
    public static function sanitize(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            $name = trim($name);
            if (preg_match("/^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$/", $name) !== 1) {
                continue;
            }
            if (strpbrk($value, "\r\n") !== false) {
                continue;
            }
            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * Merge custom headers into the provider's hardcoded base map.
     *
     * Matching is case-insensitive: a custom header replaces the base entry
     * with the same name, keeping the custom entry's casing. Protected
     * authentication headers in the custom map are ignored.
     *
     * @param array<string, string> $base
     * @param array<string, string> $custom sanitized custom headers
     *
     * @return array<string, string>
     */
    public static function mergeCustom(array $base, array $custom): array
    {
        $result = $base;
        $baseKeyByLower = [];
        foreach ($base as $name => $_) {
            $baseKeyByLower[strtolower((string) $name)] = $name;
        }

        foreach ($custom as $name => $value) {
            $lower = strtolower($name);
            if (isset(self::PROTECTED[$lower])) {
                continue;
            }
            if (isset($baseKeyByLower[$lower])) {
                unset($result[$baseKeyByLower[$lower]]);
            }
            $result[$name] = $value;
            $baseKeyByLower[$lower] = $name;
        }

        return $result;
    }
}
