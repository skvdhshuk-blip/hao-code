<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
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
- The current date can be used to find recent information
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
                    'description' => 'The search query',
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
        // back, so a DDG page full of blocked domains still triggers Google.
        $duckDuckGo = $this->searchDuckDuckGo($query);
        $results = $this->filterResults(
            $duckDuckGo['results'],
            $allowedDomains,
            $blockedDomains,
        );

        if ($results === []) {
            $google = $this->searchGoogle($query);
            $results = $this->filterResults(
                $google['results'],
                $allowedDomains,
                $blockedDomains,
            );
        }

        if ($results === []) {
            if ($this->isSuccessfulStatus($duckDuckGo['status']) && $this->isSuccessfulStatus($google['status'])) {
                return ToolResult::success("No search results found for: {$query}");
            }

            return ToolResult::error(sprintf(
                'Web search failed with no usable results. Backend statuses: DuckDuckGo=%s, Google=%s.',
                $duckDuckGo['status'],
                $google['status'],
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
        $fetch = $this->fetchHtml($url, 'Mozilla/5.0 (compatible; HaoCode/1.0)');
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
     * Fallback search using Google's HTML endpoint.
     *
     * @return array{status: string, results: list<array{title: string, url: string, snippet: string}>}
     */
    private function searchGoogle(string $query): array
    {
        $url = 'https://www.google.com/search?q='.urlencode($query).'&num=8';
        $fetch = $this->fetchHtml($url, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
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

        $anchors = [];
        foreach ($this->extractAnchors($html) as $anchor) {
            $href = $this->htmlAttribute($anchor['attributes'], 'href') ?? '';
            $decodedUrl = $this->decodeGoogleUrl($href);
            $normalizedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $isRedirect = str_starts_with($normalizedHref, '/url?') || parse_url($normalizedHref, PHP_URL_PATH) === '/url';
            if ($decodedUrl !== '' && ($isRedirect || stripos($anchor['content'], '<h3') !== false)) {
                $anchor['url'] = $decodedUrl;
                $anchors[] = $anchor;
            }
        }

        $results = [];
        foreach ($anchors as $index => $anchor) {
            $title = $this->cleanHtmlText($anchor['content']);
            $segmentEnd = $anchors[$index + 1]['offset'] ?? strlen($html);
            $snippet = $this->extractGoogleSnippet(substr(
                $html,
                $anchor['offset'] + $anchor['length'],
                $segmentEnd - $anchor['offset'] - $anchor['length'],
            ));

            $host = parse_url($anchor['url'], PHP_URL_HOST);
            if (is_string($host) && ($host === 'google.com' || str_ends_with($host, '.google.com'))) {
                continue;
            }

            if ($title !== '') {
                $results[] = [
                    'title' => $title,
                    'url' => $anchor['url'],
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

        if ($anchors === [] && $this->isExplicitEmptyPage($html, 'google')) {
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
    private function fetchHtml(string $url, string $userAgent): array
    {
        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
                'max_redirects' => 3,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                return ['status' => self::STATUS_HTTP_ERROR, 'body' => ''];
            }

            $chunks = [];
            $total = 0;
            foreach ($this->client()->stream($response) as $chunk) {
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

            return ['status' => null, 'body' => implode('', $chunks)];
        } catch (\Throwable) {
            return ['status' => self::STATUS_TRANSPORT_ERROR, 'body' => ''];
        }
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

    private function extractGoogleSnippet(string $html): string
    {
        if (preg_match('/<span\b[^>]*>(.*?)<\/span>/si', $html, $match)) {
            return $this->cleanHtmlText($match[1]);
        }

        return '';
    }

    private function decodeGoogleUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = parse_url($url, PHP_URL_PATH);
        if (str_starts_with($url, '/url?') || $path === '/url') {
            $queryString = parse_url($url, PHP_URL_QUERY);
            if (! is_string($queryString)) {
                return '';
            }

            parse_str($queryString, $params);
            foreach (['q', 'url'] as $key) {
                if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                    return $params[$key];
                }
            }

            return '';
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return preg_match('~^https?://~i', $url) ? $url : '';
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

        return preg_match('/class\s*=\s*["\'][^"\']*no-results[^"\']*["\']/i', $html) === 1
            || stripos($text, 'did not match any documents') !== false
            || preg_match('/\bno results(?: found)?\b/i', $text) === 1
            || preg_match('/\b0 results\b/i', $text) === 1;
    }

    private function isSuccessfulStatus(string $status): bool
    {
        return $status === self::STATUS_SUCCESS_WITH_RESULTS || $status === self::STATUS_SUCCESS_EMPTY;
    }
}
