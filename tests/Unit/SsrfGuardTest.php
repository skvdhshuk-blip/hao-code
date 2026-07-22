<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Support\Net\SsrfGuard;
use PHPUnit\Framework\TestCase;

class SsrfGuardTest extends TestCase
{
    /**
     * @dataProvider rejectedIpProvider
     */
    public function test_check_ip_rejects_private_loopback_reserved(string $ip): void
    {
        $rejection = SsrfGuard::checkIp($ip, []); // empty allowlist = strict
        $this->assertNotNull($rejection, "expected rejection for {$ip}");
    }

    public static function rejectedIpProvider(): array
    {
        return [
            'loopback v4'         => ['127.0.0.1'],
            'loopback v4 high'    => ['127.255.255.254'],
            'private 10/8'        => ['10.0.0.1'],
            'private 172.16/12'   => ['172.16.0.1'],
            'private 192.168/16'  => ['192.168.1.1'],
            'link-local v4'       => ['169.254.169.254'], // cloud metadata endpoint
            'link-local v4 alt'   => ['169.254.1.1'],
            'zero'                => ['0.0.0.0'],
            'reserved 240/4'      => ['240.0.0.1'],
            'loopback v6'         => ['::1'],
            'link-local v6'       => ['fe80::1'],
            'unique-local v6'     => ['fc00::1'],
        ];
    }

    /**
     * @dataProvider publicIpProvider
     */
    public function test_check_ip_allows_public_addresses(string $ip): void
    {
        $rejection = SsrfGuard::checkIp($ip, []);
        $this->assertNull($rejection, "expected allow for public IP {$ip}");
    }

    public static function publicIpProvider(): array
    {
        return [
            'public v4'      => ['8.8.8.8'],
            'public v4 alt'  => ['1.1.1.1'],
            'public v6'      => ['2606:4700:4700::1111'],
        ];
    }

    /**
     * These addresses are not ordinary public WebFetch destinations even
     * though some PHP filter versions classify them as globally routable.
     *
     * @dataProvider specialUseIpProvider
     */
    public function test_check_ip_rejects_special_use_ranges_even_when_private_networks_enabled(string $ip): void
    {
        $this->assertNotNull(SsrfGuard::checkIp($ip, [], true), "expected special-use rejection for {$ip}");
    }

    public static function specialUseIpProvider(): array
    {
        return [
            'shared cgnat' => ['100.64.0.1'],
            'ipv4 documentation 1' => ['192.0.2.1'],
            'benchmark' => ['198.18.0.1'],
            'ipv4 documentation 2' => ['198.51.100.1'],
            'ipv4 documentation 3' => ['203.0.113.1'],
            'ipv4 multicast' => ['224.0.0.1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv6 benchmark' => ['2001:2::1'],
            'ipv6 deprecated site-local' => ['fec0::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv6 srv6 sid space' => ['5f00::1'],
            'ipv6 unallocated 4000 range' => ['4000::1'],
            'ipv6 unallocated 8000 range' => ['8000::1'],
        ];
    }

    public function test_default_allowlist_is_empty_and_rejects_loopback_v4(): void
    {
        $this->assertSame([], SsrfGuard::DEFAULT_ALLOWLIST);
        $this->assertNotNull(SsrfGuard::checkIp('127.0.0.1', SsrfGuard::DEFAULT_ALLOWLIST));
        $this->assertNotNull(SsrfGuard::checkIp('127.255.0.1', SsrfGuard::DEFAULT_ALLOWLIST));
    }

    public function test_default_allowlist_rejects_loopback_v6(): void
    {
        $this->assertNotNull(SsrfGuard::checkIp('::1', SsrfGuard::DEFAULT_ALLOWLIST));
    }

    public function test_default_allowlist_does_not_permit_rfc1918(): void
    {
        // Even with the localhost allowlist, RFC1918 stays blocked.
        $this->assertNotNull(SsrfGuard::checkIp('192.168.1.1', SsrfGuard::DEFAULT_ALLOWLIST));
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1', SsrfGuard::DEFAULT_ALLOWLIST));
    }

    public function test_default_allowlist_does_not_permit_cloud_metadata(): void
    {
        $this->assertNotNull(SsrfGuard::checkIp('169.254.169.254', SsrfGuard::DEFAULT_ALLOWLIST));
    }

    public function test_custom_allowlist_overrides_rfc1918(): void
    {
        // User can opt into specific private ranges without unlocking all of them.
        $this->assertNull(SsrfGuard::checkIp('192.168.1.1', ['192.168.0.0/16']));
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1', ['192.168.0.0/16']), '10/8 must still be blocked');
    }

    public function test_check_url_rejects_non_http_schemes(): void
    {
        $this->assertNotNull(SsrfGuard::checkUrl('file:///etc/passwd'));
        $this->assertNotNull(SsrfGuard::checkUrl('gopher://localhost/'));
        $this->assertNotNull(SsrfGuard::checkUrl('ftp://example.com/'));
    }

    public function test_check_url_rejects_literal_loopback_v4_with_strict_allowlist(): void
    {
        // Without the localhost allowlist, 127.0.0.1 is blocked.
        $rejection = SsrfGuard::checkUrl('http://127.0.0.1/', []);
        $this->assertNotNull($rejection);
    }

    public function test_check_url_requires_explicit_localhost_allowlist(): void
    {
        $rejection = SsrfGuard::checkUrl('http://127.0.0.1:8080/', SsrfGuard::DEFAULT_ALLOWLIST);
        $this->assertNotNull($rejection);

        $rejection = SsrfGuard::checkUrl('http://127.0.0.1:8080/', ['127.0.0.1/32']);
        $this->assertNull($rejection);
    }

    public function test_check_url_rejects_cloud_metadata_endpoint(): void
    {
        // The classic AWS/Azure/GCP metadata IP — must always be blocked
        // unless explicitly in the allowlist.
        $rejection = SsrfGuard::checkUrl('http://169.254.169.254/latest/meta-data/', SsrfGuard::DEFAULT_ALLOWLIST);
        $this->assertNotNull($rejection);
        $this->assertStringContainsString('169.254.169.254', $rejection);
    }

    public function test_check_url_rejects_malformed_url(): void
    {
        $this->assertNotNull(SsrfGuard::checkUrl('not a url at all'));
        $this->assertNotNull(SsrfGuard::checkUrl('https://'));
    }

    /**
     * allowPrivateNetworks=true must actually permit RFC1918/loopback/etc.
     * The previous implementation set an empty allowlist, which SsrfGuard
     * treats as the strictest configuration, so the flag was a no-op.
     */
    public function test_check_ip_allow_private_networks_flag_permits_rfc1918(): void
    {
        $this->assertNull(SsrfGuard::checkIp('10.0.0.1', [], true));
        $this->assertNull(SsrfGuard::checkIp('192.168.1.1', [], true));
        $this->assertNull(SsrfGuard::checkIp('127.0.0.1', [], true));
        $this->assertNull(SsrfGuard::checkIp('169.254.169.254', [], true));

        // Default (flag off) still rejects.
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1'));
    }

    public function test_check_ip_allow_private_networks_still_rejects_invalid(): void
    {
        // Garbage is rejected regardless of the flag — it must be a valid IP first.
        $this->assertNotNull(SsrfGuard::checkIp('not-an-ip', [], true));
    }

    public function test_explicit_allowlist_can_override_special_use_rejection(): void
    {
        $this->assertNull(SsrfGuard::checkIp('100.64.0.1', ['100.64.0.0/10']));
        $this->assertNull(SsrfGuard::checkIp('ff02::1', ['ff00::/8']));
    }

    public function test_check_url_allow_private_networks_flag_applies_to_resolved_hosts(): void
    {
        // Literal private IP via checkUrl: rejected by default, allowed with flag.
        $this->assertNotNull(SsrfGuard::checkUrl('http://10.0.0.1/', [], false));
        $this->assertNull(SsrfGuard::checkUrl('http://10.0.0.1/', [], true));
    }

    /**
     * resolveUrl exposes the addresses that passed validation so the HTTP
     * client can pin the connection, closing the DNS-rebinding window.
     */
    public function test_resolve_url_returns_host_and_ips_for_literal_ip(): void
    {
        $resolved = SsrfGuard::resolveUrl('http://127.0.0.1/', ['127.0.0.1/32']);
        $this->assertSame('127.0.0.1', $resolved['host']);
        $this->assertSame(['127.0.0.1'], $resolved['ips']);
    }

    public function test_resolve_url_throws_on_rejected_url(): void
    {
        $this->expectException(\RuntimeException::class);
        SsrfGuard::resolveUrl('http://169.254.169.254/latest/meta-data/');
    }

    /**
     * CIDR matching must support arbitrary legal prefixes — not just /32 and
     * /128 — and fail closed on malformed input. The v1.13.1 IPv4 path used
     * `(1 << 32)` which overflows on 32-bit PHP.
     */
    public function test_custom_allowlist_matches_arbitrary_v4_prefix(): void
    {
        // 10.0.0.0/24 covers 10.0.0.1 .. 10.0.0.254, not 10.0.1.1.
        $this->assertNull(SsrfGuard::checkIp('10.0.0.5', ['10.0.0.0/24']));
        $this->assertNotNull(SsrfGuard::checkIp('10.0.1.5', ['10.0.0.0/24']));
    }

    public function test_custom_allowlist_matches_arbitrary_v6_prefix(): void
    {
        // fc00::/7 covers all unique-local addresses.
        $this->assertNull(SsrfGuard::checkIp('fd00::1', ['fc00::/7']));
        // Documentation space is blocked unless explicitly allowlisted.
        $this->assertNotNull(SsrfGuard::checkIp('2001:db8::1', ['fc00::/7']));
        $this->assertNull(SsrfGuard::checkIp('2001:db8::1', ['2001:db8::/32']));
    }

    public function test_custom_allowlist_matches_zero_prefix(): void
    {
        // 0.0.0.0/0 matches every IPv4 (including private) — explicit opt-in.
        $this->assertNull(SsrfGuard::checkIp('10.1.2.3', ['0.0.0.0/0']));
        $this->assertNull(SsrfGuard::checkIp('8.8.8.8', ['0.0.0.0/0']));
    }

    public function test_custom_allowlist_rejects_malformed_cidr(): void
    {
        // Malformed CIDR fails closed (no match) → private IP still rejected.
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1', ['10.0.0.0/99']));
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1', ['not-a-cidr/abc']));
        // Family mismatch.
        $this->assertNotNull(SsrfGuard::checkIp('10.0.0.1', ['::1/128']));
    }
}
