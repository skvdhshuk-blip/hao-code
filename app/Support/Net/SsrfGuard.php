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
 * build environment). The explicit ranges below supplement PHP's filter
 * flags for IANA special-use ranges which filter_var considers routable.
 *
 * @internal
 */
final class SsrfGuard
{
    /**
     * CIDR allowlist that overrides the private/special-use rejection.
     *
     * It is intentionally empty. Local development endpoints must be opted
     * into explicitly by the caller (for example, with `127.0.0.0/8`).
     */
    public const DEFAULT_ALLOWLIST = [];

    /**
     * Ranges which `allowPrivateNetworks` is explicitly allowed to open.
     * These are private/host-local ranges, not every address that PHP may
     * classify as non-global.
     *
     * @var list<string>
     */
    private const PRIVATE_NETWORK_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '::1/128',
        'fe80::/10',
        'fc00::/7',
    ];

    /**
     * IANA special-use, documentation, multicast, translation and tunnelling
     * ranges. They remain blocked even when private networks are enabled;
     * an explicit valid CIDR allowlist entry is still able to override them.
     *
     * @var list<string>
     */
    private const ALWAYS_BLOCKED_RANGES = [
        // IPv4 unspecified, shared, protocol-assignment, documentation,
        // benchmarking, multicast and reserved space.
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // IPv6 unspecified/compatible/mapped/translation/tunnelling,
        // documentation, benchmarking, multicast and reserved assignments.
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:1::/32',
        '2001:2::/48',
        '2001:3::/32',
        '2001:4::/48',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * IANA currently allocates ordinary IPv6 global-unicast addresses from
     * 2000::/3. Other syntactically valid IPv6 space must fail closed because
     * PHP's NO_RES_RANGE flag accepts unallocated ranges that may be routed
     * only inside a deployment.
     *
     * @var list<string>
     */
    private const IPV6_GLOBAL_UNICAST_RANGES = [
        '2000::/3',
    ];

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
            if (is_string($cidr) && self::ipMatchesCidr($ip, $cidr)) {
                return null;
            }
        }

        // Private/host-local ranges are the only non-global ranges opened by
        // this opt-in. Check these before the broader special-use list so the
        // explicit ::1 private exception is not swallowed by ::/96.
        $isPrivateNetwork = self::matchesAnyCidr($ip, self::PRIVATE_NETWORK_RANGES);
        if ($isPrivateNetwork) {
            if ($allowPrivateNetworks) {
                return null;
            }

            return "IP '{$ip}' is private, loopback or link-local.";
        }

        // CGNAT, documentation, multicast, unspecified, reserved and
        // translation/tunnelling ranges remain blocked with the private flag.
        if (self::matchesAnyCidr($ip, self::ALWAYS_BLOCKED_RANGES)) {
            return "IP '{$ip}' is special-use, non-global or reserved.";
        }

        if (str_contains($ip, ':') && ! self::matchesAnyCidr($ip, self::IPV6_GLOBAL_UNICAST_RANGES)) {
            return "IP '{$ip}' is outside allocated IPv6 global-unicast space.";
        }

        // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE catches any
        // additional ranges maintained by PHP that are not listed above.
        $publicFlags = $flags | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $publicFlags) === false) {
            return "IP '{$ip}' is private, loopback, link-local or reserved.";
        }

        return null;
    }

    /**
     * @param  list<string>  $cidrs
     */
    private static function matchesAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
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
