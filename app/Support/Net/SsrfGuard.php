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
 * This is the hand-rolled equivalent of Symfony's NoPrivateNetworkHttpClient
 * (which the project cannot pull in because composer require fails in the
 * build environment). It uses PHP's built-in filter_var flags plus a small
 * IPv6 private-range check, which covers everything chatgpt's 3rd-review #6
 * called out: 127.0.0.0/8, ::1, RFC1918, 169.254.169.254 (cloud metadata),
 * link-local fe80::/10, unique-local fc00::/7, 0.0.0.0, etc.
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
     * @param list<string> $allowList
     */
    public static function checkUrl(string $url, array $allowList = self::DEFAULT_ALLOWLIST): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host']) || $parsed['host'] === '') {
            return 'Could not parse URL host.';
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "Scheme '{$scheme}' is not allowed (http/https only).";
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
            return "Could not resolve host '{$host}'.";
        }

        foreach ($ips as $ip) {
            $rejection = self::checkIp($ip, $allowList);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        return null;
    }

    /**
     * Reject loopback / private / reserved / link-local IPs unless they
     * match the allowlist. Returns null on success.
     *
     * @param list<string> $allowList
     */
    public static function checkIp(string $ip, array $allowList = self::DEFAULT_ALLOWLIST): ?string
    {
        // Allowlist short-circuit. IpUtils is in symfony/http-foundation,
        // which we can't depend on, so we implement CIDR matching manually
        // for the common cases (full-IPv4 octets and /128 IPv6).
        foreach ($allowList as $cidr) {
            if (self::ipMatchesCidr($ip, $cidr)) {
                return null;
            }
        }

        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return "Resolved value '{$ip}' is not a valid IP.";
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
     * Minimal CIDR matcher covering the cases we ship in DEFAULT_ALLOWLIST
     * (IPv4 a.b.c.d/n and full IPv6 ::1/128). General-purpose CIDR matching
     * without symfony/http-foundation is verbose; we only need enough to
     * honor the localhost exception.
     */
    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $prefixStr] = explode('/', $cidr, 2);
        $prefix = (int) $prefixStr;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $mask = $prefix === 0 ? 0 : ((1 << 32) - (1 << (32 - $prefix)));

            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            // Match byte-by-byte against /128 prefix (the only case we ship).
            $totalBits = strlen($ipBin) * 8; // 128 for IPv6
            if ($prefix >= $totalBits) {
                return $ipBin === $subnetBin;
            }
            $fullBytes = intdiv($prefix, 8);
            $remainderBits = $prefix % 8;

            if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
                return false;
            }
            if ($remainderBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

            return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
        }

        return false;
    }
}
