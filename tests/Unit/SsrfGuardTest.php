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

    public function test_default_allowlist_permits_loopback_v4(): void
    {
        // The DEFAULT_ALLOWLIST lets 127.0.0.1 through so local dev servers
        // (the user's own machine) keep working.
        $this->assertNull(SsrfGuard::checkIp('127.0.0.1', SsrfGuard::DEFAULT_ALLOWLIST));
        $this->assertNull(SsrfGuard::checkIp('127.255.0.1', SsrfGuard::DEFAULT_ALLOWLIST));
    }

    public function test_default_allowlist_permits_loopback_v6(): void
    {
        $this->assertNull(SsrfGuard::checkIp('::1', SsrfGuard::DEFAULT_ALLOWLIST));
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

    public function test_check_url_accepts_localhost_with_default_allowlist(): void
    {
        $rejection = SsrfGuard::checkUrl('http://127.0.0.1:8080/', SsrfGuard::DEFAULT_ALLOWLIST);
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
}
