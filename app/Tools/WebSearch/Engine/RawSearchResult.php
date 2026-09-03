<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
final class RawSearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly string $template = 'default',
        public readonly ?string $imgSrc = null,
    ) {}

    public static function from(
        string $title,
        string $url,
        string $snippet = '',
        string $template = 'default',
        ?string $imgSrc = null,
    ): ?self {
        $title = self::cleanText($title);
        $snippet = self::cleanText($snippet);
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if ($title === '' || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host) || $host === '') {
            return null;
        }

        return new self($title, $url, $snippet, $template, $imgSrc);
    }

    private static function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
