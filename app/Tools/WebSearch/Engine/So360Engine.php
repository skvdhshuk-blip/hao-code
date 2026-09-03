<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use DOMElement;
use DOMXPath;

/** @internal */
final class So360Engine extends AbstractHtmlEngine
{
    public function id(): string
    {
        return '360';
    }

    public function qualityPriority(): int
    {
        return 200;
    }

    public function warmupUrl(): ?string
    {
        return 'https://www.so.com/';
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            'https://www.so.com/s?'.http_build_query(
                ['pn' => 1, 'q' => $query],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
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
        $items = $xpath->query('//li[contains(concat(" ", normalize-space(@class), " "), " res-list ")]');
        $results = [];
        if ($items !== false) {
            foreach ($items as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }
                $link = $this->first(
                    $xpath,
                    './/h3[contains(concat(" ", normalize-space(@class), " "), " res-title ")]//a[@href or @data-mdurl]',
                    $item,
                );
                if ($link === null) {
                    continue;
                }
                $url = $link->getAttribute('data-mdurl') ?: $link->getAttribute('href');
                if (str_starts_with($url, '/')) {
                    $url = 'https://www.so.com'.$url;
                }
                $snippet = $this->first(
                    $xpath,
                    './/*[contains(concat(" ", normalize-space(@class), " "), " res-desc ") '
                    .'or contains(concat(" ", normalize-space(@class), " "), " res-list-summary ")]',
                    $item,
                );
                $result = $this->result($this->text($link), $url, $this->text($snippet));
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
        if (preg_match('/(?:未找到相关结果|没有找到相关结果|找不到相关结果)/u', $response->body) === 1) {
            return EngineParseResult::empty();
        }

        return EngineParseResult::error();
    }
}
