<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebSearchTool extends BaseTool
{
    /**
     * Cap response body reads so a hostile or broken search backend cannot
     * exhaust memory. 2 MiB comfortably fits a result page.
     */
    private const MAX_RESPONSE_BYTES = 2_097_152;

    private ?HttpClientInterface $client = null;

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
        ], [
            'query' => 'required|string|min:2',
            'allowed_domains' => 'nullable|array',
            'blocked_domains' => 'nullable|array',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $query = $input['query'];
        $allowedDomains = $this->normalizeDomains($input['allowed_domains'] ?? []);
        $blockedDomains = $this->normalizeDomains($input['blocked_domains'] ?? []);

        // DuckDuckGo first; apply domain filtering before deciding to fall
        // back, so a DDG page full of blocked domains still triggers Google.
        $results = $this->filterResults(
            $this->searchDuckDuckGo($query),
            $allowedDomains,
            $blockedDomains,
        );

        if ($results === []) {
            $results = $this->filterResults(
                $this->searchGoogle($query),
                $allowedDomains,
                $blockedDomains,
            );
        }

        if ($results === []) {
            return ToolResult::success("No search results found for: {$query}");
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
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function searchDuckDuckGo(string $query): array
    {
        $url = 'https://html.duckduckgo.com/html/?q='.urlencode($query);
        $html = $this->fetchHtml($url, 'Mozilla/5.0 (compatible; HaoCode/1.0)');
        if ($html === null) {
            return [];
        }

        $results = [];
        if (preg_match_all('/<a rel="nofollow" class="result__a" href="([^"]+)">(.*?)<\/a>.*?<a class="result__snippet"[^>]*>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $decodedUrl = $this->decodeDdgUrl($match[1]);
                $title = $this->cleanHtmlText($match[2]);
                $snippet = $this->cleanHtmlText($match[3] ?? '');

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
        }

        return $results;
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
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function searchGoogle(string $query): array
    {
        $url = 'https://www.google.com/search?q='.urlencode($query).'&num=8';
        $html = $this->fetchHtml($url, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        if ($html === null) {
            return [];
        }

        $results = [];
        if (preg_match_all('/<a href="\/url\?q=([^&"]+).*?>(.*?)<\/a>.*?<span.*?>(.*?)<\/span>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = urldecode($match[1]);
                $title = $this->cleanHtmlText($match[2]);
                $snippet = $this->cleanHtmlText($match[3]);

                // Skip internal Google links.
                if (str_starts_with($url, 'https://www.google.com')) {
                    continue;
                }

                if ($url !== '' && $title !== '') {
                    $results[] = [
                        'title' => $title,
                        'url' => $url,
                        'snippet' => $snippet,
                    ];
                }

                if (count($results) >= 8) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Fetch a URL via Symfony HttpClient with default TLS verification and a
     * hard response-size cap. Returns null on transport error, non-2xx, or
     * size overrun so callers fall back instead of fataling.
     */
    private function fetchHtml(string $url, string $userAgent): ?string
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

                return null;
            }

            $chunks = [];
            $total = 0;
            foreach ($this->client()->stream($response) as $chunk) {
                if ($chunk->isTimeout() || $chunk->isLast()) {
                    continue;
                }

                $data = $chunk->getContent();
                $total += strlen($data);
                if ($total > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();

                    return null;
                }
                $chunks[] = $data;
            }

            return implode('', $chunks);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Apply allowed/blocked domain filtering with proper domain-boundary matching.
     *
     * @param  list<array{title: string, url: string, snippet: string}>  $results
     * @param  list<string>  $allowedDomains
     * @param  list<string>  $blockedDomains
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function filterResults(array $results, array $allowedDomains, array $blockedDomains): array
    {
        return array_values(array_filter(
            $results,
            function (array $result) use ($allowedDomains, $blockedDomains): bool {
                $host = parse_url($result['url'], PHP_URL_HOST);
                if (! is_string($host) || $host === '') {
                    return false;
                }
                $host = strtolower(rtrim($host, '.'));

                if ($allowedDomains !== []) {
                    $allowed = false;
                    foreach ($allowedDomains as $domain) {
                        if ($this->hostMatchesDomain($host, $domain)) {
                            $allowed = true;
                            break;
                        }
                    }
                    if (! $allowed) {
                        return false;
                    }
                }

                foreach ($blockedDomains as $domain) {
                    if ($this->hostMatchesDomain($host, $domain)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Normalize caller-supplied domain filters into bare lowercase hostnames.
     *
     * Accepts "example.com", "*.example.com", "https://example.com/foo", and
     * leading/trailing dots. Non-string and empty entries are dropped.
     *
     * @return list<string>
     */
    private function normalizeDomains(mixed $domains): array
    {
        if (! is_array($domains)) {
            return [];
        }

        $normalized = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            if (str_starts_with($domain, '*.')) {
                $domain = substr($domain, 2);
            }

            $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                continue;
            }

            $host = trim(strtolower($host), '.');
            if ($host !== '') {
                $normalized[] = $host;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * True domain-boundary match: exact equality or a real subdomain (".example.com").
     * Avoids str_ends_with(), which treats "notexample.com" as matching "example.com".
     */
    private function hostMatchesDomain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private function cleanHtmlText(string $html): string
    {
        return trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
