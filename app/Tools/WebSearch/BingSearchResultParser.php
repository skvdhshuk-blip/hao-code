<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** @internal */
final class BingSearchResultParser
{
    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public static function parse(string $html, int $limit): array
    {
        $document = self::document($html);
        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $items = $xpath->query(
            '//ol[@id="b_results"]/li[contains(concat(" ", normalize-space(@class), " "), " b_algo ")]',
        );
        if ($items === false) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            $links = $xpath->query('.//h2/a[@href]', $item);
            $link = $links === false ? null : $links->item(0);
            if (! $link instanceof DOMElement) {
                continue;
            }

            $title = self::cleanText($link->textContent);
            $url = self::decodeUrl($link->getAttribute('href'));
            if ($title === '' || $url === '') {
                continue;
            }

            $paragraphs = $xpath->query('.//p', $item);
            $paragraph = $paragraphs === false ? null : $paragraphs->item(0);
            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $paragraph === null ? '' : self::cleanText($paragraph->textContent),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private static function document(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadHTML(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded === false ? null : $document;
    }

    private static function decodeUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = parse_url($url, PHP_URL_PATH);
        if (($host === 'www.bing.com' || $host === 'bing.com') && $path === '/ck/a') {
            $query = parse_url($url, PHP_URL_QUERY);
            if (! is_string($query)) {
                return '';
            }

            parse_str($query, $params);
            $encoded = $params['u'] ?? null;
            if (! is_string($encoded) || ! str_starts_with($encoded, 'a1')) {
                return '';
            }

            $payload = strtr(substr($encoded, 2), '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $decoded = base64_decode($payload, true);
            if (! is_string($decoded)) {
                return '';
            }

            $url = $decoded;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== ''
            ? $url
            : '';
    }

    private static function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
