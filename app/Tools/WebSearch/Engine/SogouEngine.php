<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use DOMElement;
use DOMXPath;

/** @internal */
final class SogouEngine extends AbstractHtmlEngine
{
    public function id(): string
    {
        return 'sogou';
    }

    public function qualityPriority(): int
    {
        return 300;
    }

    public function warmupUrl(): ?string
    {
        return 'https://www.sogou.com/';
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            'https://www.sogou.com/web?'.http_build_query(
                ['query' => $query, 'page' => 1],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
        );
    }

    public function parse(EngineHttpResponse $response): EngineParseResult
    {
        if (str_contains(strtolower($response->effectiveUrl), 'antispider')
            || $this->isChallenge($response->body)) {
            return EngineParseResult::error('challenge_page');
        }

        $document = $this->document($response->body);
        if ($document === null) {
            return EngineParseResult::error();
        }

        $xpath = new DOMXPath($document);
        $items = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " rb ") '
            .'or contains(concat(" ", normalize-space(@class), " "), " vrwrap ")]',
        );
        $results = [];
        if ($items !== false) {
            foreach ($items as $item) {
                if (! $item instanceof DOMElement
                    || $this->first($xpath, './/div[contains(concat(" ", normalize-space(@class), " "), " special-wrap ")]', $item) !== null) {
                    continue;
                }
                $link = $this->first(
                    $xpath,
                    './/h3[contains(concat(" ", normalize-space(@class), " "), " pt ") '
                    .'or contains(concat(" ", normalize-space(@class), " "), " vr-title ")]//a[@href]',
                    $item,
                );
                if ($link === null) {
                    continue;
                }

                $url = $link->getAttribute('href');
                if (str_starts_with($url, '/link?url=')) {
                    $dataUrl = $this->first($xpath, './/*[@data-url]', $item)?->getAttribute('data-url') ?? '';
                    $url = $dataUrl !== '' ? $dataUrl : 'https://www.sogou.com'.$url;
                } elseif (str_starts_with($url, '/')) {
                    $url = 'https://www.sogou.com'.$url;
                }
                $snippet = $this->first(
                    $xpath,
                    './/div[contains(concat(" ", normalize-space(@class), " "), " ft ") '
                    .'or contains(concat(" ", normalize-space(@class), " "), " attribute-centent ") '
                    .'or contains(concat(" ", normalize-space(@class), " "), " fz-mid ")]',
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
        if (preg_match('/(?:抱歉[^<]{0,40}没有找到|未找到相关结果|没有找到相关结果)/u', $response->body) === 1) {
            return EngineParseResult::empty();
        }

        return EngineParseResult::error();
    }
}
