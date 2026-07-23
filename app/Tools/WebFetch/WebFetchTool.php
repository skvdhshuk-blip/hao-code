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

class WebFetchTool extends BaseTool
{
    /**
     * Shared per-process cache. The key includes the security policy so a
     * response fetched under a permissive policy is never served to a strict
     * caller (and vice versa).
     *
     * @var array<string, array{content: string, time: int, final_url: ?string}>
     */
    private static array $cache = [];

    /** Maximum entries retained; oldest evicted first. */
    private const CACHE_TTL = 900; // 15 minutes

    private const CACHE_MAX_ENTRIES = 128;

    private const CACHE_MAX_BYTES = 33_554_432; // 32 MiB

    private const MAX_CONTENT_SIZE = 100000;

    /**
     * Hard cap on decompressed bytes pulled into memory per request. Five MiB
     * matches the default HaoCodeConfig::$webfetchMaxBytes.
     */
    private const DEFAULT_MAX_BYTES = 5_242_880;

    private const MAX_REDIRECTS = 3;

    private ?HttpClientInterface $client = null;

    /** @var list<string> */
    private array $ssrfAllowList;

    private int $maxBytes;

    private bool $allowPrivateNetworks;

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
                [$content, $contentType, $finalUrl] = $this->fetchWithSsrfGuard($url);
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
            }

            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            if (in_array($mediaType, ['text/html', 'application/xhtml+xml'], true)) {
                $content = $format === 'markdown'
                    ? $this->htmlToMarkdown($content)
                    : $this->htmlToText($content);
            }

            [$content] = $this->truncateForOutput($content);
            $header = '';
            $this->storeCache($cacheKey, $content, $finalUrl);
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
    private function fetchWithSsrfGuard(string $url): array
    {
        $finalUrl = $url;
        $redirects = 0;

        while (true) {
            // Resolve + validate every hop. resolveUrl throws on rejection so
            // we never issue a request against a blocked destination.
            $resolved = SsrfGuard::resolveUrl($finalUrl, $this->ssrfAllowList, $this->allowPrivateNetworks);

            // Pin each connection to an already-checked IP. Transport failures
            // may try the next validated address, while an HTTP response is
            // authoritative and never triggers address failover.
            $result = $this->requestValidatedUrl($finalUrl, $resolved);
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
    private function requestValidatedUrl(string $url, array $resolved): array
    {
        $lastTransportError = null;
        foreach ($resolved['ips'] as $ip) {
            if (! is_string($ip) || $ip === '') {
                continue;
            }

            $response = null;
            try {
                $response = $this->client()->request('GET', $url, $this->requestOptions($resolved, $ip));
                $statusCode = $response->getStatusCode();
                $headers = $response->getHeaders(false);

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

                return [
                    'status' => $statusCode,
                    'location' => null,
                    'body' => $this->streamWithByteCap($response, $this->maxBytes),
                    'content_type' => $headers['content-type'][0] ?? '',
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
                'Accept' => 'text/html,text/plain,application/json,*/*',
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

    private function normalizePath(string $path): string
    {
        // RFC 3986 section 5.2.4 remove_dot_segments algorithm. Keeping the
        // input/output form (rather than treating paths as filesystem names)
        // preserves repeated slashes, path parameters, and trailing slashes.
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
                continue;
            }
            if (str_starts_with($input, './')) {
                $input = substr($input, 2);
                continue;
            }
            if ($input === '..' || $input === '.') {
                $input = '';
                continue;
            }
            if (str_starts_with($input, '/../')) {
                $input = '/'.substr($input, 4);
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if ($input === '/..') {
                $input = '/';
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if (str_starts_with($input, '/./')) {
                $input = '/'.substr($input, 3);
                continue;
            }
            if ($input === '/.') {
                $input = '/';
                continue;
            }

            // Move the first path segment (with its leading slash, when
            // present) from input to output.
            $slash = strpos($input, '/', str_starts_with($input, '/') ? 1 : 0);
            if ($slash === false) {
                $output .= $input;
                $input = '';
            } else {
                $output .= substr($input, 0, $slash);
                $input = substr($input, $slash);
            }
        }

        return $output === '' ? '/' : $output;
    }

    private function removeLastPathSegment(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    private function streamWithByteCap($response, int $maxBytes): string
    {
        $chunks = [];
        $total = 0;
        foreach ($this->client()->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            if (! $chunk->isLast()) {
                $data = $chunk->getContent();
                $total += strlen($data);
                if ($total > $maxBytes) {
                    $response->cancel();
                    throw new \RuntimeException(
                        "Response exceeded {$maxBytes} byte cap and was aborted.",
                    );
                }
                $chunks[] = $data;
            }
        }

        return implode('', $chunks);
    }

    /**
     * Truncate the rendered output to MAX_CONTENT_SIZE units while retaining
     * the useful prefix. The marker is part of the returned content so callers
     * cannot accidentally replace the page with a marker-only response.
     *
     * @return array{0: string, 1: string} [content, unit label]
     */
    private function truncateForOutput(string $content): array
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($content, 'UTF-8') <= self::MAX_CONTENT_SIZE) {
                return [$content, 'characters'];
            }

            $prefix = mb_substr($content, 0, self::MAX_CONTENT_SIZE, 'UTF-8');
            return [$prefix."\n\n[Content truncated at ".self::MAX_CONTENT_SIZE.' characters]', 'characters'];
        }

        if (strlen($content) <= self::MAX_CONTENT_SIZE) {
            return [$content, 'bytes'];
        }

        $prefix = $this->truncateUtf8ByBytes($content, self::MAX_CONTENT_SIZE);
        return [$prefix."\n\n[Content truncated at ".self::MAX_CONTENT_SIZE.' bytes]', 'bytes'];
    }

    private function truncateUtf8ByBytes(string $content, int $limit): string
    {
        $length = min(strlen($content), $limit);
        if ($length === 0) {
            return '';
        }

        // Remove a partial UTF-8 sequence at the cut boundary. This keeps
        // output valid without requiring ext-mbstring; invalid bytes already
        // present in the source are left untouched.
        $lead = $length - 1;
        while ($lead >= 0 && (ord($content[$lead]) & 0xC0) === 0x80) {
            $lead--;
        }
        if ($lead >= 0) {
            $first = ord($content[$lead]);
            $expected = $first < 0x80 ? 1 : ($first < 0xE0 ? 2 : ($first < 0xF0 ? 3 : ($first < 0xF8 ? 4 : 1)));
            if ($expected > $length - $lead) {
                $length = $lead;
            }
        }

        return substr($content, 0, $length);
    }

    /**
     * Plain-text rendering: strips scripts/styles/nav, collapses block tags
     * to whitespace, and removes all remaining markup. No markdown markers.
     */
    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);

        // Convert block-level elements to whitespace before stripping tags so
        // adjacent text segments do not collapse together.
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|br)\s*>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', '- ', $html);

        // Strip link URLs but keep the link text.
        $html = preg_replace('/<a[^>]*>(.*?)<\/a>/si', '$1', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Markdown rendering: preserves headings, links, emphasis, and code blocks.
     */
    private function htmlToMarkdown(string $html): string
    {
        // Remove scripts, styles, and HTML comments
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);

        // Convert headings to markdown-style
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/si', "\n# $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/si', "\n## $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/si', "\n### $1\n", $html);
        $html = preg_replace('/<h[4-6][^>]*>(.*?)<\/h[4-6]>/si', "\n#### $1\n", $html);

        // Convert links to markdown
        $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html);

        // Convert common elements to text
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "- ", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);

        // Convert code blocks
        $html = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html);
        $html = preg_replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html);

        // Bold and italic
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/si', '**$2**', $html);
        $html = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/si', '*$2*', $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
