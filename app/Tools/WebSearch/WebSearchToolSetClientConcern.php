<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait WebSearchToolSetClientConcern
{

    /**
     * Override the HTTP client (primarily for tests).
     */
    public function setClient(HttpClientInterface $client): void
    {
        $this->client = $client;
    }

    private function client(): HttpClientInterface
    {
        return $this->client ??= HttpClient::create([
            'timeout' => 15,
            'max_duration' => 30,
        ]);
    }

    public function name(): string
    {
        return 'WebSearch';
    }

    public function description(): string
    {
        return <<<DESC
Search the web and return results. Use this to find up-to-date information, documentation, or answers to questions.

Returns search results with titles, URLs, and snippets.

Usage notes:
- Always include a "Sources:" section with markdown links at the end of responses using search results
- Use specific queries for better results
- Today's date is provided in the runtime context of the first user turn. When the user asks for current, latest, or recent information without naming a date or time period, append today's date to the query (for example "php 8.5 release notes 2026-09-02") so the results are not stale. Do not add a date when the user already specified one.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'description' => "The search query. If the request is time-sensitive and the user gave no explicit date or period, append today's date from the runtime context (YYYY-MM-DD).",
                ],
                'allowed_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only include results from these domains',
                ],
                'blocked_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Exclude results from these domains',
                ],
            ],
            'required' => ['query'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $query = $input['query'];
        $allowedDomains = $this->normalizeDomains($input['allowed_domains'] ?? []);
        $blockedDomains = $this->normalizeDomains($input['blocked_domains'] ?? []);

        // DuckDuckGo first; apply domain filtering before deciding to fall
        // back, so a DDG page full of blocked domains still triggers Bing.
        $duckDuckGo = $this->searchDuckDuckGo($query);
        $results = $this->filterResults(
            $duckDuckGo['results'],
            $allowedDomains,
            $blockedDomains,
        );

        if ($results === []) {
            $bing = $this->searchBing($query);
            $results = $this->filterResults(
                $bing['results'],
                $allowedDomains,
                $blockedDomains,
            );
        }

        if ($results === []) {
            if ($this->isSuccessfulStatus($duckDuckGo['status']) && $this->isSuccessfulStatus($bing['status'])) {
                return ToolResult::success("No search results found for: {$query}");
            }

            return ToolResult::error(sprintf(
                'Web search failed with no usable results. Backend statuses: DuckDuckGo=%s, Bing=%s.',
                $duckDuckGo['status'],
                $bing['status'],
            ));
        }

        $output = "Search results for: \"{$query}\"\n\n";
        foreach (array_values($results) as $i => $result) {
            $num = $i + 1;
            $output .= "{$num}. [{$result['title']}]({$result['url']})\n";
            if ($result['snippet'] !== '') {
                $output .= "   {$result['snippet']}\n";
            }
            $output .= "\n";
        }

        return ToolResult::success($output);
    }

    /**
     * Search using DuckDuckGo's HTML endpoint.
     *
     * @return array{status: string, results: list<array{title: string, url: string, snippet: string}>}
     */
    private function searchDuckDuckGo(string $query): array
    {
        $url = 'https://html.duckduckgo.com/html/?q='.urlencode($query);
        $fetch = $this->fetchHtml($url);
        if ($fetch['status'] !== null) {
            return ['status' => $fetch['status'], 'results' => []];
        }

        $html = $fetch['body'];
        if (trim($html) === '') {
            // A blank 200 page contains no evidence of an empty result set;
            // search backends and proxies commonly return it on anti-bot or
            // upstream failures. Only explicit no-results markup is success.
            return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
        }
        if ($this->isChallengePage($html)) {
            return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
        }

        $anchors = [];
        foreach ($this->extractAnchors($html) as $anchor) {
            $class = $this->htmlAttribute($anchor['attributes'], 'class');
            if ($class !== null && $this->hasHtmlClass($class, 'result__a')) {
                $anchors[] = $anchor;
            }
        }

        $results = [];
        foreach ($anchors as $index => $anchor) {
            $href = $this->htmlAttribute($anchor['attributes'], 'href') ?? '';
            $decodedUrl = $this->decodeDdgUrl($href);
            $title = $this->cleanHtmlText($anchor['content']);
            $segmentEnd = $anchors[$index + 1]['offset'] ?? strlen($html);
            $snippet = $this->extractDdgSnippet(substr(
                $html,
                $anchor['offset'] + $anchor['length'],
                $segmentEnd - $anchor['offset'] - $anchor['length'],
            ));

            if ($decodedUrl !== '' && $title !== '') {
                $results[] = [
                    'title' => $title,
                    'url' => $decodedUrl,
                    'snippet' => $snippet,
                ];
            }

            if (count($results) >= 8) {
                break;
            }
        }

        if ($results !== []) {
            return ['status' => self::STATUS_SUCCESS_WITH_RESULTS, 'results' => $results];
        }

        if ($anchors === [] && $this->isExplicitEmptyPage($html, 'duckduckgo')) {
            return ['status' => self::STATUS_SUCCESS_EMPTY, 'results' => []];
        }

        return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
    }

    /**
     * Decode DuckDuckGo redirect URLs of the form //duckduckgo.com/l/?uddg=<encoded>.
     */
    private function decodeDdgUrl(string $url): string
    {
        $candidate = str_starts_with($url, '//') ? 'https:'.$url : $url;
        $queryString = parse_url($candidate, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $params);
            if (isset($params['uddg']) && is_string($params['uddg']) && $params['uddg'] !== '') {
                return $params['uddg'];
            }
        }

        return trim($url);
    }

    /**
     * Fallback search using Bing's HTML endpoint.
     *
     * @return array{status: string, results: list<array{title: string, url: string, snippet: string}>}
     */
    private function searchBing(string $query): array
    {
        $url = 'https://www.bing.com/search?'.http_build_query([
            'q' => $query,
            'mkt' => 'en-US',
        ], '', '&', PHP_QUERY_RFC3986);
        $fetch = $this->fetchHtml($url);
        if ($fetch['status'] !== null) {
            return ['status' => $fetch['status'], 'results' => []];
        }

        $html = $fetch['body'];
        if (trim($html) === '') {
            return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
        }
        if ($this->isChallengePage($html)) {
            return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
        }

        $results = BingSearchResultParser::parse($html, 8);

        if ($results !== []) {
            return ['status' => self::STATUS_SUCCESS_WITH_RESULTS, 'results' => $results];
        }

        if ($this->isExplicitEmptyPage($html, 'bing')) {
            return ['status' => self::STATUS_SUCCESS_EMPTY, 'results' => []];
        }

        return ['status' => self::STATUS_PARSE_ERROR, 'results' => []];
    }

    /**
     * Fetch a URL via Symfony HttpClient with default TLS verification and a
     * hard response-size cap.
     *
     * @return array{status: null|string, body: string}
     */
    private function fetchHtml(string $url): array
    {
        try {
            $client = $this->client();
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
                'sec-ch-ua' => '"Google Chrome";v="147", "Not.A/Brand";v="8", "Chromium";v="147"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'none',
                'sec-fetch-user' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ];
            $options = [
                'headers' => $headers,
                'max_redirects' => 3,
            ];
            $curlDecodesContent = false;

            if ($client instanceof CurlHttpClient && defined('CURLOPT_ENCODING')) {
                $encoding = $this->curlSupportsBrotli() ? 'gzip, br' : 'gzip';
                $options['headers']['Accept-Encoding'] = $encoding;
                $options['extra']['curl'][(int) constant('CURLOPT_ENCODING')] = $encoding;
                $curlDecodesContent = true;
            } elseif (function_exists('gzdecode')) {
                $options['headers']['Accept-Encoding'] = 'gzip';
            }

            $response = $client->request('GET', $url, $options);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                return ['status' => self::STATUS_HTTP_ERROR, 'body' => ''];
            }

            $chunks = [];
            $total = 0;
            foreach ($client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();

                    return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
                }
                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $data = $chunk->getContent();
                $total += strlen($data);
                if ($total > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();

                    return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
                }
                $chunks[] = $data;
            }

            $body = implode('', $chunks);
            if (! $curlDecodesContent) {
                $encoding = strtolower($response->getHeaders(false)['content-encoding'][0] ?? '');
                if (str_contains($encoding, 'gzip')) {
                    if (! function_exists('gzdecode')) {
                        return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
                    }
                    $body = @gzdecode($body);
                    if (! is_string($body)) {
                        return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
                    }
                }
            }

            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
            }

            return ['status' => null, 'body' => $body];
        } catch (\Throwable) {
            return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
        }
    }

    private function curlSupportsBrotli(): bool
    {
        if (! function_exists('curl_version') || ! defined('CURL_VERSION_BROTLI')) {
            return false;
        }

        $version = curl_version();

        return isset($version['features'])
            && (($version['features'] & (int) constant('CURL_VERSION_BROTLI')) !== 0);
    }

    /**
     * @return list<array{attributes: string, content: string, offset: int, length: int}>
     */
    private function extractAnchors(string $html): array
    {
        if (! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $anchors = [];
        foreach ($matches as $match) {
            $anchors[] = [
                'attributes' => $match[1][0],
                'content' => $match[2][0],
                'offset' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $anchors;
    }

    private function htmlAttribute(string $attributes, string $name): ?string
    {
        $pattern = '/(?:^|\s)'.preg_quote($name, '/').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (! preg_match($pattern, $attributes, $match)) {
            return null;
        }

        foreach (array_slice($match, 1) as $value) {
            if ($value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return '';
    }

    private function hasHtmlClass(string $classes, string $expected): bool
    {
        return in_array($expected, preg_split('/\s+/', trim($classes)) ?: [], true);
    }

    private function extractDdgSnippet(string $html): string
    {
        if (preg_match_all('/<(a|div)\b([^>]*)>(.*?)<\/\1>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $class = $this->htmlAttribute($match[2], 'class');
                if ($class !== null && $this->hasHtmlClass($class, 'result__snippet')) {
                    return $this->cleanHtmlText($match[3]);
                }
            }
        }

        return '';
    }

    private function isChallengePage(string $html): bool
    {
        if (preg_match('/(?:class|id|name|action|src)\s*=\s*["\'][^"\']*(?:captcha|recaptcha|challenge|anomaly-modal|\/sorry\/)[^"\']*["\']/i', $html) === 1) {
            return true;
        }

        return stripos($html, 'unusual traffic from your computer network') !== false
            || stripos($html, 'bots use duckduckgo') !== false;
    }

    private function isExplicitEmptyPage(string $html, string $backend): bool
    {
        $text = $this->cleanHtmlText($html);
        if ($backend === 'duckduckgo') {
            return preg_match('/class\s*=\s*["\'][^"\']*no-results[^"\']*["\']/i', $html) === 1
                || preg_match('/\bno (?:more )?results(?: found)?\b/i', $text) === 1;
        }

        return preg_match('/class\s*=\s*["\'][^"\']*(?:no-results|b_no)[^"\']*["\']/i', $html) === 1
            || preg_match('/\b(?:there are )?no results(?: found)?\b/i', $text) === 1
            || preg_match('/\b0 results\b/i', $text) === 1;
    }

    private function isSuccessfulStatus(string $status): bool
    {
        return $status === self::STATUS_SUCCESS_WITH_RESULTS || $status === self::STATUS_SUCCESS_EMPTY;
    }
}
