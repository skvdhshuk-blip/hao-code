<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use HaoCode\Tools\WebSearch\BingSearchResultParser;

/** @internal */
final class BingEngine extends AbstractHtmlEngine
{
    public function id(): string
    {
        return 'bing';
    }

    public function qualityPriority(): int
    {
        return 400;
    }

    public function warmupUrl(): ?string
    {
        return 'https://www.bing.com/';
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            'https://www.bing.com/search?'.http_build_query(
                ['q' => $query, 'mkt' => 'en-US'],
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

        $results = [];
        foreach (BingSearchResultParser::parse($response->body, 10) as $item) {
            $result = $this->result($item['title'], $item['url'], $item['snippet']);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        if ($results !== []) {
            return EngineParseResult::success($results);
        }

        $text = strip_tags($response->body);
        if (preg_match('/class\s*=\s*["\'][^"\']*(?:no-results|b_no)[^"\']*["\']/i', $response->body) === 1
            || preg_match('/\b(?:there are )?no results(?: found)?\b/i', $text) === 1
            || preg_match('/\b0 results\b/i', $text) === 1) {
            return EngineParseResult::empty();
        }

        return EngineParseResult::error();
    }
}
