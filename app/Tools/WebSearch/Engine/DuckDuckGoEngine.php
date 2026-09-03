<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use DOMElement;
use DOMXPath;

/** @internal */
final class DuckDuckGoEngine extends AbstractHtmlEngine
{
    public function id(): string
    {
        return 'duckduckgo';
    }

    public function qualityPriority(): int
    {
        return 500;
    }

    public function warmupUrl(): ?string
    {
        return 'https://duckduckgo.com/';
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            'https://html.duckduckgo.com/html/?'.http_build_query(
                ['q' => $query, 'kl' => 'us-en'],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
            ['Accept-Language' => 'en-US,en;q=0.3'],
        );
    }

    public function parse(EngineHttpResponse $response): EngineParseResult
    {
        if ($this->isChallenge($response->body)) {
            return EngineParseResult::error('challenge_page');
        }

        $document = $this->document($response->body);
        if ($document === null) {
            return EngineParseResult::error();
        }

        $xpath = new DOMXPath($document);
        $links = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]',
        );
        $results = [];
        if ($links !== false) {
            foreach ($links as $link) {
                if (! $link instanceof DOMElement) {
                    continue;
                }

                $item = $this->first(
                    $xpath,
                    'ancestor::div[contains(concat(" ", normalize-space(@class), " "), " result ")][1]',
                    $link,
                );
                $snippet = $item === null ? null : $this->first(
                    $xpath,
                    './/*[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")]',
                    $item,
                );
                $result = $this->result(
                    $this->text($link),
                    $this->decodeUrl($link->getAttribute('href')),
                    $this->text($snippet),
                );
                if ($result !== null) {
                    $results[] = $result;
                }
                if (count($results) >= 10) {
                    break;
                }
            }
        }

        if ($results !== []) {
            return EngineParseResult::success($results);
        }

        $text = $this->text($document);
        if (preg_match('/class\s*=\s*["\'][^"\']*no-results[^"\']*["\']/i', $response->body) === 1
            || preg_match('/\bno (?:more )?results(?: found)?\b/i', $text) === 1) {
            return EngineParseResult::empty();
        }

        return EngineParseResult::error();
    }

    private function decodeUrl(string $url): string
    {
        $candidate = str_starts_with($url, '//') ? 'https:'.$url : $url;
        $query = parse_url($candidate, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            if (is_string($parameters['uddg'] ?? null) && $parameters['uddg'] !== '') {
                return $parameters['uddg'];
            }
        }

        return $candidate;
    }
}
