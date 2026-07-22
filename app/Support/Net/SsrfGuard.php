<?php

declare(strict_types=1);

namespace HaoCode\Support\Net;

/**
 * SSRF guard for outbound HTTP requests.
 *
 * Resolves the host of a URL and rejects loopback / private / reserved /
 * link-local / cloud-metadata destinations before any connection is opened.
 * Redirect targets are re-checked the same way (each hop must pass).
 *
 * Resolved addresses are exposed via {@see resolveUrl()} so callers can pin
 * the HTTP client to an already-checked IP, closing the DNS-rebinding window
 * between the guard check and the actual connection.
 *
 * This is the hand-rolled equivalent of Symfony's NoPrivateNetworkHttpClient
 * (which the project cannot pull in because composer require fails in the
 * build environment). It uses PHP's built-in filter_var flags plus a small
 * IPv6 private-range check, which covers 127.0.0.0/8, ::1, RFC1918,
 * 169.254.169.254 (cloud metadata), link-local fe80::/10, unique-local
 * fc00::/7, 0.0.0.0, etc.
 *
 * @internal
 */
final class SsrfGuard
{
    /**
     * CIDR allowlist that overrides the private/loopback rejection.
     *
     * `127.0.0.1/8` and `::1/128` cover the typical local-dev case (the
     * user's own machine running a dev server / LLM / MCP server) without
     * opening up the broader private ranges where internal services live.
     */
    public const DEFAULT_ALLOWLIST = ['127.0.0.1/8', '::1/128'];

    /**
     * Resolve and validate a URL. Returns null on success, or a
     * human-readable rejection reason.
     *
     * @param  list<string>  $allowList
     */
    public static function checkUrl(
        string $url,
        array $allowList = self::DEFAULT_ALLOWLIST,
        bool $allowPrivateNetworks = false,
    ): ?string {
        return self::inspectUrl($url, $allowList, $allowPrivateNetworks)['rejection'];
    }

    /**
     * Resolve and validate a URL, returning the exact addresses that passed.
     *
     * Callers should pin the HTTP request to one of these IPs (e.g. via
     * Symfony HttpClient's `resolve` option) so DNS cannot change between
     * the guard check and the network connection.
     *
     * @param  list<string>  $allowList
     * @return array{host: string, ips: list<string>}
     *
     * @throws \RuntimeException when the URL is rejected
     */
    public static function resolveUrl(
        string $url,
        array $allowList = self::DEFAULT_ALLOWLIST,
        bool $allowPrivateNetworks = false,
    ): array {
        $result = self::inspectUrl($url, $allowList, $allowPrivateNetworks);
        if ($result['rejection'] !== null) {
            throw new \RuntimeException($result['rejection']);
        }

        return [
            'host' => $result['host'],
            'ips' => $result['ips'],
        ];
    }

    /**
     * Reject loopback / private / reserved / link-local IPs unless they
     * match the allowlist. Returns null on success.
     *
     * @param  list<string>  $allowList
     */
    public static function checkIp(
        string $ip,
        array $allowList = self::DEFAULT_ALLOWLIST,
        bool $allowPrivateNetworks = false,
    ): ?string {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return "Resolved value '{$ip}' is not a valid IP.";
        }

        // Allowlist short-circuit. A matching CIDR is always accepted,
        // even when the caller opted out of private networks entirely.
        foreach ($allowList as $cidr) {
            if (self::ipMatchesCidr($ip, $cidr)) {
                return null;
            }
        }

        if ($allowPrivateNetworks) {
            // Caller explicitly accepted private/loopback ranges. We still
            // require the value to be a syntactically valid IP (checked above)
            // but no longer reject RFC1918 / loopback / link-local / reserved.
            return null;
        }

        // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE rejects:
        //   - 10/8, 172.16/12, 192.168/16 (RFC1918 private)
        //   - 169.254/16 (link-local, incl. cloud metadata 169.254.169.254)
        //   - 127/8 (loopback) and ::1
        //   - 0.0.0.0/8, 240/4 (reserved), fe80::/10 (IPv6 link-local),
        //     fc00::/7 (IPv6 unique-local)
        $publicFlags = $flags | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $publicFlags) === false) {
            return "IP '{$ip}' is private, loopback, link-local or reserved.";
        }

        return null;
    }

    /**
     * @param  list<string>  $allowList
     * @return array{host: string, ips: list<string>, rejection: ?string}
     */
    private static function inspectUrl(string $url, array $allowList, bool $allowPrivateNetworks): array
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host']) || $parsed['host'] === '') {
            return ['host' => '', 'ips' => [], 'rejection' => 'Could not parse URL host.'];
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            $host = $parsed['host'];

            return ['host' => $host, 'ips' => [], 'rejection' => "Scheme '{$scheme}' is not allowed (http/https only)."];
        }

        $host = $parsed['host'];
        // Bracketed IPv6 (e.g. [::1]) — strip brackets for IP checks.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // If the host is already a literal IP, check directly. Otherwise
        // resolve via DNS and check every returned address (IPv4 + IPv6).
        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            return ['host' => $host, 'ips' => [], 'rejection' => "Could not resolve host '{$host}'."];
        }

        foreach ($ips as $ip) {
            $rejection = self::checkIp($ip, $allowList, $allowPrivateNetworks);
            if ($rejection !== null) {
                return ['host' => $host, 'ips' => $ips, 'rejection' => $rejection];
            }
        }

        return ['host' => $host, 'ips' => $ips, 'rejection' => null];
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        // Literal IPs skip DNS.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
            return [$host];
        }

        $ips = [];

        // IPv4 records via gethostbynamel (returns array of IPv4 addrs).
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        // IPv6 records via dns_get_record (AAAA).
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Binary CIDR matching for IPv4 and IPv6 using inet_pton().
     *
     * Handles arbitrary legal prefixes (not just /32 and /128), exact-IP
     * entries (no slash), /0, and non-byte-aligned prefixes. Malformed CIDRs
     * and prefix/family mismatches fail closed (return false) rather than
     * performing unsafe shifts that overflow on 32-bit PHP.
     */
    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        // Exact address entry (no slash).
        if (! str_contains($cidr, '/')) {
            $candidateBin = @inet_pton($cidr);

            return $candidateBin !== false && $ipBin === $candidateBin;
        }

        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || ! preg_match('/^[0-9]+$/', $parts[1])) {
            return false;
        }
        [$subnet, $prefixStr] = $parts;
        $prefix = (int) $prefixStr;

        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false) {
            return false;
        }

        // Family mismatch (IPv4 address vs IPv6 CIDR or vice versa).
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8; // 32 for IPv4, 128 for IPv6
        if ($prefix < 0 || $prefix > $totalBits) {
            return false;
        }
        if ($prefix === 0) {
            return true;
        }
        if ($prefix === $totalBits) {
            return $ipBin === $subnetBin;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
