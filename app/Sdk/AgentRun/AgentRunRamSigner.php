<?php

namespace HaoCode\Sdk\AgentRun;

/** @internal */
final class AgentRunRamSigner
{
    private const ALGORITHM = 'AGENTRUN4-HMAC-SHA256';
    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
    private const SCOPE_SUFFIX = 'aliyun_v4_request';
    private const KEY_PREFIX = 'aliyun_v4';

    /**
     * @return array<string, string>
     */
    public function sign(string $url, string $method, AgentRunSandboxConfig $config, ?string $contentType = null, ?\DateTimeImmutable $time = null): array
    {
        if (! $config->hasRamCredentials()) {
            return [];
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = $this->parseQuery((string) ($parts['query'] ?? ''));

        $now = $time ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d\TH:i:s\Z');
        $date = $now->format('Ymd');

        $headers = [
            'host' => $host,
            'x-acs-date' => $timestamp,
            'x-acs-content-sha256' => self::UNSIGNED_PAYLOAD,
        ];
        if ($config->securityToken !== null && $config->securityToken !== '') {
            $headers['x-acs-security-token'] = $config->securityToken;
        }
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        $canonicalRequest = $this->canonicalRequest($method, $path, $query, $headers);
        $stringToSign = self::ALGORITHM."\n".hash('sha256', $canonicalRequest);
        $scope = $date.'/'.$config->region.'/agentrun/'.self::SCOPE_SUFFIX;
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($config->accessKeySecret ?? '', $date, $config->region));
        $signedHeaders = implode(';', $this->signedHeaderNames($headers));

        $headers['Agentrun-Authorization'] = self::ALGORITHM
            .' Credential='.$config->accessKeyId.'/'.$scope
            .',SignedHeaders='.$signedHeaders
            .',Signature='.$signature;

        return $headers;
    }

    /** @return array<string, string> */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($key)] = urldecode($value);
        }

        return $params;
    }

    /** @param array<string, string> $query @param array<string, string> $headers */
    private function canonicalRequest(string $method, string $path, array $query, array $headers): string
    {
        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($headers);

        return strtoupper($method)."\n"
            .($path !== '' ? $path : '/')."\n"
            .$this->canonicalQuery($query)."\n"
            .$canonicalHeaders."\n"
            .$signedHeaders."\n"
            .self::UNSIGNED_PAYLOAD;
    }

    /** @param array<string, string> $query */
    private function canonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }

        ksort($query, SORT_STRING);
        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = $this->percentEncode((string) $key).'='.$this->percentEncode((string) $value);
        }

        return implode('&', $parts);
    }

    /** @param array<string, string> $headers @return array{0: string, 1: string} */
    private function canonicalHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower(trim($key))] = trim($value);
        }

        $signed = $this->signedHeaderNames($normalized);
        $canonical = '';
        foreach ($signed as $name) {
            $canonical .= $name.':'.$normalized[$name]."\n";
        }

        return [$canonical, implode(';', $signed)];
    }

    /** @param array<string, string> $headers @return string[] */
    private function signedHeaderNames(array $headers): array
    {
        $names = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower(trim($key));
            if ($value !== '' && ($lower === 'host' || $lower === 'content-type' || str_starts_with($lower, 'x-acs-'))) {
                $names[] = $lower;
            }
        }
        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }

    private function signingKey(string $secret, string $date, string $region): string
    {
        $kDate = hash_hmac('sha256', $date, self::KEY_PREFIX.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kProduct = hash_hmac('sha256', 'agentrun', $kRegion, true);

        return hash_hmac('sha256', self::SCOPE_SUFFIX, $kProduct, true);
    }

    private function percentEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
