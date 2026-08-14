<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait WebSearchToolFilterResultsConcern
{

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
