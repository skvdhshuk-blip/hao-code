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
Set `extract` to true to return only the main article instead of the whole page — far fewer tokens on
navigation-heavy sites. Pass `keywords` alongside it so a short but relevant block (a data table, a price
row) outranks long boilerplate.
Supports an optional `prompt` parameter to state a requested focus; on its own it does not change what is returned.
A `[WebFetch]` note is prepended when the response looks like a bot-challenge page or a client-rendered shell.
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
                    'format' => 'uri',
                    'description' => 'The URL to fetch',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Optional requested focus; on its own it does not change what is returned',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'markdown'],
                    'description' => 'Output format (default: text)',
                ],
                'extract' => [
                    'type' => 'boolean',
                    'description' => 'Return only the main article instead of the whole page (default: false). '
                        .'Falls back to the full page when no article-like block is found',
                ],
                'keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Terms the answer likely contains. Blocks mentioning them are weighted up '
                        .'during extraction, so short relevant content is not lost to long boilerplate. '
                        .'Only meaningful with extract=true',
                ],
            ],
            'required' => ['url'],
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
        $extract = ($input['extract'] ?? false) === true;
        $keywords = $this->normalizeKeywordInput($input['keywords'] ?? []);

        $cacheKey = $this->cacheKey($url, $format, $extract, $keywords);
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
            $content = (new WebFetchOutputComposer)->compose(
                $this->normalizeUtf8Text($content),
                $mediaType,
                $finalUrl ?? $url,
                $format === 'markdown',
                $extract,
                $keywords,
                fn (string $html, bool $markdown): string => $markdown
                    ? $this->htmlToMarkdown($html)
                    : $this->htmlToText($html),
            );

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
     * Normalise the caller's keyword list: strings only, trimmed, de-duplicated,
     * order-independent so it can key the cache.
     *
     * @return list<string>
     */
    private function normalizeKeywordInput(mixed $keywords): array
    {
        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];
        foreach ($keywords as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '') {
                $normalized[] = trim($keyword);
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * Per-instance cache key. Includes the URL, the requested rendering
     * (format plus extraction settings, which change the returned text), and
     * the full security policy (private-network toggle + sorted allowlist +
     * byte cap) so responses fetched under one policy are never served to a
     * caller with a stricter or looser policy.
     *
     * @param  list<string>  $keywords  already sorted and de-duplicated
     */
    private function cacheKey(string $url, string $format, bool $extract, array $keywords): string
    {
        $allowList = $this->ssrfAllowList;
        sort($allowList);

        return md5(implode('|', [
            $url,
            $format,
            $extract ? 'extract' : 'full',
            implode(',', $keywords),
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
                    // The body carries the actual reason ("denied by UA ACL",
                    // a rate-limit notice, an auth hint). Without it the agent
                    // only sees a bare status and retries blindly.
                    throw new \RuntimeException(
                        "HTTP {$statusCode} for URL: {$url}".$this->errorBodyPreview($response, $shouldAbort),
                    );
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
            // A tool-shaped User-Agent draws a 403 from CDNs that blacklist
            // non-browser agents by default (Alibaba Tengine answers
            // "denied by UA ACL = blacklist"), which reads to the agent as a
            // dead link rather than a blocked one. Present as a current
            // desktop browser and send the headers one would send.
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.9,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Upgrade-Insecure-Requests' => '1',
            ],
            'max_redirects' => 0,
            // Symfony otherwise inherits HTTP(S)_PROXY from the environment.
            // A proxy resolves the target itself and can bypass the checked
            // hostname-to-IP mapping, so pinned WebFetch requests go direct.
            'no_proxy' => '*',
            'resolve' => [$host => $ip],
        ];
    }
}
