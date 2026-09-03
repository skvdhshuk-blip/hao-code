<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use DOMElement;
use DOMXPath;

/** @internal */
final class YahooEngine extends AbstractHtmlEngine
{
    public function id(): string
    {
        return 'yahoo';
    }

    public function qualityPriority(): int
    {
        return 100;
    }

    public function warmupUrl(): ?string
    {
        return 'https://search.yahoo.com/';
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            'https://search.yahoo.com/search?'.http_build_query(
                ['p' => $query, 'b' => 1],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
            ['Accept' => 'text/html,application/xhtml+xml'],
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
        $items = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " algo-sr ")]');
        $results = [];
        if ($items !== false) {
            foreach ($items as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }
                $link = $this->first(
                    $xpath,
                    './/div[contains(concat(" ", normalize-space(@class), " "), " compTitle ")]'
                    .'//h3//a[@href] | .//div[contains(concat(" ", normalize-space(@class), " "), " compTitle ")]//a[@href]',
                    $item,
                );
                if ($link === null) {
                    continue;
                }

                $title = $link->getAttribute('aria-label') ?: $this->text($link);
                $snippet = $this->first(
                    $xpath,
                    './/div[contains(concat(" ", normalize-space(@class), " "), " compText ")]',
                    $item,
                );
                $result = $this->result(
                    $title,
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
        if (preg_match('/(?:we did not find results|no results for|try different keywords)/i', $response->body) === 1) {
            return EngineParseResult::empty();
        }

        return EngineParseResult::error();
    }

    private function decodeUrl(string $url): string
    {
        $position = strpos($url, '/RU=');
        if ($position === false) {
            return $url;
        }

        $encoded = substr($url, $position + 4);
        $end = strlen($encoded);
        foreach (['/RS', '/RK'] as $marker) {
            $candidate = strpos($encoded, $marker);
            if ($candidate !== false) {
                $end = min($end, $candidate);
            }
        }

        return rawurldecode(substr($encoded, 0, $end));
    }
}
