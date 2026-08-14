<?php

namespace HaoCode\Tools\WebFetch;

use HaoCode\Support\Net\SsrfGuard;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait WebFetchToolConstructConcern
{

    /**
     * @param  list<string>  $ssrfAllowList  CIDR allowlist of explicit SSRF exceptions
     */
    public function __construct(
        bool $allowPrivateNetworks = false,
        array $ssrfAllowList = SsrfGuard::DEFAULT_ALLOWLIST,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        // HttpClient is lazy-initialized so the container can construct this
        // tool without resolving HttpClientInterface (which is only bound
        // explicitly in tests).
        $this->allowPrivateNetworks = $allowPrivateNetworks;
        // Keep the allowlist as-is. allowPrivateNetworks is honored by
        // SsrfGuard directly (the previous "empty list = allow private"
        // encoding was backwards: an empty allowlist is the *strictest*
        // configuration, not the most permissive).
        $this->ssrfAllowList = array_values($ssrfAllowList);
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Override the HTTP client (primarily for tests).
     */
    public function setClient(HttpClientInterface $client): void
    {
        $this->client = $client;
    }

    private function client(): HttpClientInterface
    {
        return $this->client ??= HttpClient::create(['timeout' => 30, 'max_duration' => 60]);
    }

    public function name(): string
    {
        return 'WebFetch';
    }

    public function description(): string
    {
        return <<<DESC
Fetch content from a URL. Returns the page content as text/markdown.
Use this tool to read web pages, API documentation, or other online resources.
Supports an optional `prompt` parameter to state a requested focus; the full page content is still returned (this is not extraction).
Results are cached for 15 minutes.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to fetch',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Optional requested focus; the full page content is returned and nothing is extracted',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'markdown'],
                    'description' => 'Output format (default: text)',
                ],
            ],
            'required' => ['url'],
        ], [
            'url' => 'required|url',
            'prompt' => 'nullable|string',
            'format' => 'nullable|string|in:text,markdown',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        if ($context->isAborted()) {
            return ToolResult::aborted();
        }

        $url = $input['url'];
        $prompt = $input['prompt'] ?? null;
        $format = ($input['format'] ?? 'text') === 'markdown' ? 'markdown' : 'text';

        $cacheKey = $this->cacheKey($url, $format);
        $this->purgeExpiredCache();

        if (isset(self::$cache[$cacheKey])) {
            $content = self::$cache[$cacheKey]['content'];
            $finalUrl = self::$cache[$cacheKey]['final_url'];
            $header = "[Cached result]\n";
        } else {
            try {
                [$content, $contentType, $finalUrl] = $this->fetchWithSsrfGuard(
                    $url,
                    static fn (): bool => $context->isAborted(),
                );
            } catch (WebFetchAbortedException) {
                return ToolResult::aborted();
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
            }

            if ($context->isAborted()) {
                return ToolResult::aborted();
            }

            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            $content = $this->normalizeUtf8Text($content);
            if (in_array($mediaType, ['text/html', 'application/xhtml+xml'], true)) {
                $content = $format === 'markdown'
                    ? $this->htmlToMarkdown($content)
                    : $this->htmlToText($content);
            }

            [$content] = $this->truncateForOutput($content);
            if ($context->isAborted()) {
                return ToolResult::aborted();
            }
            $header = '';
            $this->storeCache($cacheKey, $content, $finalUrl);
        }

        if ($context->isAborted()) {
            return ToolResult::aborted();
        }

        if ($finalUrl !== null && $finalUrl !== $url) {
            $header .= "[Redirected to: {$finalUrl}]\n";
        }

        $result = $header.$content;

        if ($prompt !== null) {
            $result = "[Requested focus: {$prompt}]\n\n".$result;
        }

        return ToolResult::success($result);
    }

    /**
     * Per-instance cache key. Includes the URL, the requested format, and the
     * full security policy (private-network toggle + sorted allowlist +
     * byte cap) so responses fetched under one policy are never served to a
     * caller with a stricter or looser policy.
     */
    private function cacheKey(string $url, string $format): string
    {
        $allowList = $this->ssrfAllowList;
        sort($allowList);

        return md5(implode('|', [
            $url,
            $format,
            $this->allowPrivateNetworks ? '1' : '0',
            implode(',', $allowList),
            (string) $this->maxBytes,
        ]));
    }

    private function storeCache(string $key, string $content, ?string $finalUrl): void
    {
        $this->purgeExpiredCache();
        $entryBytes = strlen($content);
        if ($entryBytes > self::CACHE_MAX_BYTES) {
            return;
        }

        unset(self::$cache[$key]);
        while (self::$cache !== [] && (
            count(self::$cache) >= self::CACHE_MAX_ENTRIES
            || $this->cacheBytes() + $entryBytes > self::CACHE_MAX_BYTES
        )) {
            uasort(self::$cache, static fn ($a, $b) => $a['time'] <=> $b['time']);
            $oldKey = array_key_first(self::$cache);
            unset(self::$cache[$oldKey]);
        }

        self::$cache[$key] = [
            'content' => $content,
            'time' => time(),
            'final_url' => $finalUrl,
        ];
    }

    private function purgeExpiredCache(): void
    {
        $cutoff = time() - self::CACHE_TTL;
        foreach (self::$cache as $key => $entry) {
            if (($entry['time'] ?? 0) <= $cutoff) {
                unset(self::$cache[$key]);
            }
        }
    }

    private function cacheBytes(): int
    {
        return array_sum(array_map(
            static fn (array $entry): int => strlen($entry['content']),
            self::$cache,
        ));
    }

    /**
     * Fetch the URL with SSRF protection on every hop, DNS-pinning to the
     * checked IP, and a streaming byte cap to prevent OOM on large responses.
     *
     * @return array{0: string, 1: string, 2: ?string}  [body, content-type, final URL]
     */
    private function fetchWithSsrfGuard(string $url, ?callable $shouldAbort = null): array
    {
        $finalUrl = $url;
        $redirects = 0;

        while (true) {
            $this->throwIfAborted($shouldAbort);

            // Resolve + validate every hop. resolveUrl throws on rejection so
            // we never issue a request against a blocked destination.
            $resolved = SsrfGuard::resolveUrl($finalUrl, $this->ssrfAllowList, $this->allowPrivateNetworks);
            $this->throwIfAborted($shouldAbort);

            // Pin each connection to an already-checked IP. Transport failures
            // may try the next validated address, while an HTTP response is
            // authoritative and never triggers address failover.
            $result = $this->requestValidatedUrl($finalUrl, $resolved, $shouldAbort);
            $this->throwIfAborted($shouldAbort);
            $statusCode = $result['status'];

            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $result['location'];
                if ($location === null || $redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException("Too many redirects or missing Location header for {$url}");
                }
                $redirects++;
                $finalUrl = $this->resolveRedirect($finalUrl, $location);
                continue;
            }

            return [$result['body'], $result['content_type'], $finalUrl];
        }
    }

    /**
     * @param array{host: string, ips: list<string>} $resolved
     * @return array{status: int, location: ?string, body: string, content_type: string}
     */
    private function requestValidatedUrl(
        string $url,
        array $resolved,
        ?callable $shouldAbort = null,
    ): array
    {
        $lastTransportError = null;
        foreach ($resolved['ips'] as $ip) {
            $this->throwIfAborted($shouldAbort);

            if (! is_string($ip) || $ip === '') {
                continue;
            }

            $response = null;
            try {
                $response = $this->client()->request('GET', $url, $this->requestOptions($resolved, $ip));
                $this->throwIfAborted($shouldAbort);
                $statusCode = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $this->throwIfAborted($shouldAbort);

                if ($statusCode >= 300 && $statusCode < 400) {
                    return [
                        'status' => $statusCode,
                        'location' => $headers['location'][0] ?? null,
                        'body' => '',
                        'content_type' => '',
                    ];
                }

                if ($statusCode >= 400) {
                    throw new \RuntimeException("HTTP {$statusCode} for URL: {$url}");
                }

                $contentType = $headers['content-type'][0] ?? '';
                if (! $this->isAllowedTextContentType($contentType)) {
                    $length = $headers['content-length'][0] ?? null;
                    $size = is_string($length) && preg_match('/^\d+$/', $length) === 1
                        ? " ({$length} bytes)"
                        : '';
                    $type = trim($contentType) !== '' ? $contentType : 'missing Content-Type';
                    throw new \RuntimeException(
                        "Unsupported response Content-Type for WebFetch: {$type}{$size}. "
                        .'Only text, JSON, XML, and JavaScript responses are returned.',
                    );
                }

                return [
                    'status' => $statusCode,
                    'location' => null,
                    'body' => $this->streamWithByteCap(
                        $response,
                        $this->maxBytes,
                        $shouldAbort,
                    ),
                    'content_type' => $contentType,
                ];
            } catch (TransportExceptionInterface $e) {
                $lastTransportError = $e;
            } finally {
                $response?->cancel();
            }
        }

        if ($lastTransportError !== null) {
            throw new \RuntimeException(
                "Transport failed for URL after trying all validated IPs: {$lastTransportError->getMessage()}",
                previous: $lastTransportError,
            );
        }

        throw new \RuntimeException('No validated IP available for request.');
    }

    /**
     * Build request options from the already-validated DNS result. Symfony's
     * `resolve` option is a hostname-to-IP map, not a list of numeric IPs.
     * Keeping the original URL means the client preserves Host, port and TLS
     * SNI semantics without a hand-written Host header.
     *
     * @param array{host: string, ips: list<string>} $resolved
     */
    private function requestOptions(array $resolved, ?string $validatedIp = null): array
    {
        $host = trim($resolved['host'], '[]');
        $ip = $validatedIp ?? ($resolved['ips'][0] ?? null);
        if ($host === '' || ! is_string($ip) || $ip === '') {
            throw new \RuntimeException('No validated IP available for request.');
        }

        return [
            'headers' => [
                'User-Agent' => 'HaoCode/1.0 (PHP SDK)',
                'Accept' => 'text/html,text/plain,text/*,application/json,application/*+json,application/xml,application/*+xml,application/javascript',
            ],
            'max_redirects' => 0,
            // Symfony otherwise inherits HTTP(S)_PROXY from the environment.
            // A proxy resolves the target itself and can bypass the checked
            // hostname-to-IP mapping, so pinned WebFetch requests go direct.
            'no_proxy' => '*',
            'resolve' => [$host => $ip],
        ];
    }

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
