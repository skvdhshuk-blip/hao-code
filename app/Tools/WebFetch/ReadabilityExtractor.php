<?php

namespace HaoCode\Tools\WebFetch;

use DOMElement;

/**
 * Main-content extraction for WebFetch: parse, drop chrome, score, serialize.
 *
 * Returning a page's article instead of its entire markup is what keeps a
 * navigation-heavy page from spending tens of thousands of tokens on menus.
 * Callers pass the terms they are looking for so a short but relevant block
 * (a weather table, a price row) is not outweighed by long boilerplate.
 *
 * @internal
 */
final class ReadabilityExtractor
{
    /**
     * Extract the main content, or null when the page has no usable article.
     *
     * @param  list<string>  $keywords  caller-supplied focus terms
     */
    public function extract(string $html, string $baseUrl, array $keywords, bool $markdown): ?ExtractedArticle
    {
        $normalized = $this->normalizeKeywords($keywords);

        $document = ReadabilityDocument::parse($html);
        if ($document === null || $document->body === null) {
            return null;
        }

        $title = $document->documentTitle();
        $document->clean($normalized);

        $nodes = (new ReadabilityScorer($normalized))->selectContent($document);
        if ($nodes === []) {
            return null;
        }

        $content = (new ReadabilitySerializer($markdown, $baseUrl))->render($nodes);
        if (trim($content) === '') {
            return null;
        }

        $title = $this->resolveTitle($title, $nodes);
        $content = $this->stripLeadingTitle($content, $title);

        return new ExtractedArticle(
            $title,
            $content,
            PageQualitySignals::visibleTextLength($content),
        );
    }

    /**
     * Prefer the article's own `<h1>`; fall back to `<title>` with the site
     * suffix trimmed ("Story — Example News" -> "Story").
     *
     * @param  list<DOMElement>  $nodes
     */
    private function resolveTitle(string $documentTitle, array $nodes): string
    {
        foreach ($nodes as $node) {
            foreach ($node->getElementsByTagName('h1') as $heading) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $heading->textContent) ?? '');
                if ($text !== '') {
                    return $text;
                }
            }
        }

        $documentTitle = trim(preg_replace('/\s+/u', ' ', $documentTitle) ?? '');
        if ($documentTitle === '') {
            return '';
        }

        foreach ([' | ', ' - ', ' — ', ' – ', ' _ '] as $separator) {
            $position = strrpos($documentTitle, $separator);
            if ($position !== false && $position > 8) {
                return trim(substr($documentTitle, 0, $position));
            }
        }

        return $documentTitle;
    }

    /** Avoid printing the title twice when the body already opens with it. */
    private function stripLeadingTitle(string $content, string $title): string
    {
        if ($title === '') {
            return $content;
        }

        foreach (["# {$title}", $title] as $candidate) {
            if (str_starts_with($content, $candidate)) {
                return ltrim(substr($content, strlen($candidate)), "\n");
            }
        }

        return $content;
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function normalizeKeywords(array $keywords): array
    {
        $normalized = [];
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword), 'UTF-8');
            if ($keyword !== '' && ! in_array($keyword, $normalized, true)) {
                $normalized[] = $keyword;
            }
        }

        return $normalized;
    }
}
