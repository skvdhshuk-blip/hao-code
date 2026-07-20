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
    /** @var array<string, array{content: string, time: int}> */
    private static array $cache = [];

    private const CACHE_TTL = 900; // 15 minutes
    private const MAX_CONTENT_SIZE = 100000;

    /**
     * Hard cap on decompressed bytes pulled into memory per request. chatgpt
     * 3rd-review #6 called out the previous unbounded getContent(false)
     * buffer. Five MiB matches the default HaoCodeConfig::$webfetchMaxBytes.
     */
    private const DEFAULT_MAX_BYTES = 5_242_880;

    private const MAX_REDIRECTS = 3;

    private ?HttpClientInterface $client = null;

    /** @var list<string> */
    private array $ssrfAllowList;

    private int $maxBytes;

    private bool $allowPrivateNetworks;

    /**
     * @param list<string> $ssrfAllowList  CIDR allowlist that bypasses the private/loopback rejection
     */
    public function __construct(
        bool $allowPrivateNetworks = false,
        array $ssrfAllowList = SsrfGuard::DEFAULT_ALLOWLIST,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        // The defaults (SSRF on + localhost allowlist + 5 MiB cap) are the
        // behavior the bundled tool needs out of the box. The corresponding
        // HaoCodeConfig fields ($webfetchAllowPrivateNetworks /
        // $webfetchPrivateAllowList / $webfetchMaxBytes) wire into manual
        // constructions of WebFetchTool; the SDK auto-registration path uses
        // these safe defaults and will gain per-config wiring in a follow-up.
        //
        // HttpClient is lazy-initialized so the container can construct this
        // tool without resolving HttpClientInterface (which is only bound
        // explicitly in tests).
        $this->allowPrivateNetworks = $allowPrivateNetworks;
        $this->ssrfAllowList = $allowPrivateNetworks
            ? [] // empty list ⇒ SsrfGuard rejects nothing (caller opted into private networks)
            : $ssrfAllowList;
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

        // Check cache
        $cacheKey = md5($url);
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            $content = self::$cache[$cacheKey]['content'];
            $header = "[Cached result]\n";
        } else {
            try {
                [$content, $contentType, $finalUrl] = $this->fetchWithSsrfGuard($url);
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
            }

            $header = '';
            if ($finalUrl !== $url) {
                $header .= "[Redirected to: {$finalUrl}]\n";
            }

            // Strip HTML tags for basic text extraction
            if (str_contains($contentType, 'text/html')) {
                $content = $this->htmlToText($content);
            }

            // Cache the result
            self::$cache[$cacheKey] = ['content' => $content, 'time' => time()];
        }

        // Truncate very large responses (post-decompression cap so model
        // never sees more than MAX_CONTENT_SIZE chars regardless of body size).
        if (mb_strlen($content) > self::MAX_CONTENT_SIZE) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_SIZE) . "\n\n[Content truncated at " . self::MAX_CONTENT_SIZE . " characters]";
        }

        $result = $header . $content;

        if ($prompt !== null) {
            $result = "[Extraction prompt: {$prompt}]\n\n" . $result;
        }

        return ToolResult::success($result);
    }

    /**
     * Fetch the URL with SSRF protection on every hop and a streaming
     * byte cap to prevent OOM on large responses.
     *
     * @return array{0: string, 1: string, 2: string}  [body, content-type, final URL]
     */
    private function fetchWithSsrfGuard(string $url): array
    {
        $finalUrl = $url;
        $redirects = 0;

        while (true) {
            // Validate every hop (initial request + each redirect target).
            // chatgpt #6: the previous code let Symfony auto-follow 5
            // redirects without re-checking, so a redirect to
            // 169.254.169.254 would silently succeed.
            $rejection = SsrfGuard::checkUrl($finalUrl, $this->ssrfAllowList);
            if ($rejection !== null) {
                throw new \RuntimeException("URL rejected by SSRF guard: {$rejection} (URL: {$finalUrl})");
            }

            // Disable auto-redirect so we control each hop.
            $response = $this->client()->request('GET', $finalUrl, [
                'headers' => [
                    'User-Agent' => 'HaoCode/1.0 (PHP SDK)',
                    'Accept' => 'text/html,text/plain,application/json,*/*',
                ],
                'max_redirects' => 0,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                if ($location === null || $redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException("Too many redirects or missing Location header for {$url}");
                }
                $redirects++;
                $finalUrl = $this->resolveRedirect($finalUrl, $location);
                continue;
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("HTTP {$statusCode} for URL: {$finalUrl}");
            }

            // Stream the body with a hard byte cap. On overflow we cancel
            // the response instead of OOM-ing the process (chatgpt #6:
            // getContent(false) used to buffer the entire decompressed body).
            $body = $this->streamWithByteCap($response, $this->maxBytes);
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

            return [$body, $contentType, $finalUrl];
        }
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_HOST) !== null) {
            return $location; // absolute
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $path = $parts['path'] ?? '/';
        $dir = str_contains($path, '/') ? dirname($path) : '';

        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
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

    private function htmlToText(string $html): string
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
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim($text);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
