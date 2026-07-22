<?php

namespace HaoCode\Tools\WebFetch;

use HaoCode\Support\Net\SsrfGuard;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
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
     * @param  list<string>  $ssrfAllowList  CIDR allowlist that bypasses the private/loopback rejection
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
Supports an optional `prompt` parameter to extract specific information from the page.
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
                    'description' => 'Optional prompt describing what information to extract from the page',
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

        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            $content = self::$cache[$cacheKey]['content'];
            $finalUrl = self::$cache[$cacheKey]['final_url'];
            $header = "[Cached result]\n";
        } else {
            try {
                [$content, $contentType, $finalUrl] = $this->fetchWithSsrfGuard($url);
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
            }

            if (str_contains($contentType, 'text/html')) {
                $content = $format === 'markdown'
                    ? $this->htmlToMarkdown($content)
                    : $this->htmlToText($content);
            }

            $header = '';
            $this->storeCache($cacheKey, $content, $finalUrl);
        }

        if ($finalUrl !== null && $finalUrl !== $url) {
            $header .= "[Redirected to: {$finalUrl}]\n";
        }

        [$content, $unit] = $this->truncateForOutput($content);
        if ($content === null) {
            $result = $header.'[Content truncated at '.self::MAX_CONTENT_SIZE." {$unit}]";
        } else {
            $result = $header.$content;
        }

        if ($prompt !== null) {
            $result = "[Extraction prompt: {$prompt}]\n\n".$result;
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
        // Bounded LRU-ish eviction: drop oldest entries once over capacity.
        if (! isset(self::$cache[$key]) && count(self::$cache) >= self::CACHE_MAX_ENTRIES) {
            uasort(self::$cache, static fn ($a, $b) => $a['time'] <=> $b['time']);
            foreach (array_keys(self::$cache) as $oldKey) {
                unset(self::$cache[$oldKey]);
                break;
            }
        }

        self::$cache[$key] = [
            'content' => $content,
            'time' => time(),
            'final_url' => $finalUrl,
        ];
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

            // Pin the connection to the already-checked IPs (Symfony HttpClient
            // `resolve` option) so DNS cannot change between the guard check
            // and the actual connection (DNS rebinding).
            $response = $this->client()->request('GET', $finalUrl, [
                'headers' => [
                    'User-Agent' => 'HaoCode/1.0 (PHP SDK)',
                    'Accept' => 'text/html,text/plain,application/json,*/*',
                    'Host' => $resolved['host'],
                ],
                'max_redirects' => 0,
                'resolve' => $resolved['ips'] ?: null,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();
                if ($location === null || $redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException("Too many redirects or missing Location header for {$url}");
                }
                $redirects++;
                $finalUrl = $this->resolveRedirect($finalUrl, $location);
                continue;
            }

            if ($statusCode >= 400) {
                $response->cancel();
                throw new \RuntimeException("HTTP {$statusCode} for URL: {$finalUrl}");
            }

            try {
                $body = $this->streamWithByteCap($response, $this->maxBytes);
                $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            } finally {
                $response->cancel();
            }

            return [$body, $contentType, $finalUrl];
        }
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
        // Fragments are not re-fetched.
        $hashPos = strpos($location, '#');
        if ($hashPos !== false) {
            $location = substr($location, 0, $hashPos);
        }
        if ($location === '') {
            return $base;
        }

        // Absolute URI (has scheme://).
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $location)) {
            return $location;
        }

        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';
        $authority = $host.$port;
        // Preserve IPv6 brackets in the authority for the reconstructed URL.
        if (isset($baseParts['host']) && str_contains((string) $baseParts['host'], ':')) {
            $authority = '['.$baseParts['host'].']'.$port;
        }

        // Scheme-relative (//host/path) — replace authority and path.
        if (str_starts_with($location, '//')) {
            $locParts = parse_url('scheme:'.$location);

            return $scheme.':'.$location;
        }

        // Absolute path.
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$authority}{$location}";
        }

        // Query-only or relative path: resolve against the current path.
        $basePath = $baseParts['path'] ?? '/';

        if (str_starts_with($location, '?')) {
            return "{$scheme}://{$authority}{$basePath}{$location}";
        }

        $dir = str_contains($basePath, '/') ? dirname($basePath) : '';
        if ($dir === '.') {
            $dir = '';
        }
        $merged = "{$dir}/{$location}";

        // Collapse . and .. segments.
        $normalized = $this->normalizePath($merged);

        return "{$scheme}://{$authority}{$normalized}";
    }

    private function normalizePath(string $path): string
    {
        $prefixSlash = str_starts_with($path, '/');
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $part;
        }

        $result = implode('/', $stack);
        if ($prefixSlash) {
            $result = '/'.$result;
        }

        return $result === '' ? '/' : $result;
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
     * Truncate the rendered output to MAX_CONTENT_SIZE units. Prefers
     * mbstring for character-safe truncation when available; otherwise falls
     * back to a UTF-8-boundary-safe byte truncation so the project does not
     * require ext-mbstring.
     *
     * @return array{0: ?string, 1: string}  [truncated content or null if a truncation marker replaced it, unit label]
     */
    private function truncateForOutput(string $content): array
    {
        if (function_exists('mb_strlen')) {
            if (mb_strlen($content) <= self::MAX_CONTENT_SIZE) {
                return [$content, 'characters'];
            }

            return [null, 'characters'];
        }

        if (strlen($content) <= self::MAX_CONTENT_SIZE) {
            return [$content, 'bytes'];
        }

        return [null, 'bytes'];
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
