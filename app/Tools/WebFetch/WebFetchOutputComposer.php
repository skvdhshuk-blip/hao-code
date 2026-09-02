<?php

namespace HaoCode\Tools\WebFetch;

/**
 * Decides what a fetched response actually hands back to the model: the
 * extracted article, the whole page, and any warning about the page being a
 * challenge screen or an unrendered shell.
 *
 * Kept out of the tool itself so the fetch path stays about HTTP and SSRF.
 *
 * @internal
 */
final class WebFetchOutputComposer
{
    private const HTML_MEDIA_TYPES = ['text/html', 'application/xhtml+xml'];

    /**
     * Below this share of the page's visible text, a short extraction reads as
     * a scoring failure rather than a short article.
     */
    private const MIN_RETAINED_SHARE = 0.30;

    public function __construct(
        private readonly ReadabilityExtractor $extractor = new ReadabilityExtractor,
    ) {
    }

    /**
     * @param  string  $body  UTF-8 normalised response body
     * @param  list<string>  $keywords  focus terms for extraction
     * @param  callable(string, bool): string  $convertFullPage  html -> text/markdown
     */
    public function compose(
        string $body,
        string $mediaType,
        string $finalUrl,
        bool $markdown,
        bool $extract,
        array $keywords,
        callable $convertFullPage,
    ): string {
        if (! in_array($mediaType, self::HTML_MEDIA_TYPES, true)) {
            return $body;
        }

        $notes = [];
        $signal = PageQualitySignals::describe($body);
        if ($signal !== null) {
            $notes[] = 'Low-confidence result: '.$signal.'. Treat the content below as unreliable '
                .'and prefer another source or a URL that serves static HTML.';
        }

        $content = null;
        if ($extract) {
            $pageLength = PageQualitySignals::visibleTextLength(PageQualitySignals::stripScriptsAndTags($body));
            [$content, $extractionNote] = $this->extractArticle($body, $finalUrl, $markdown, $keywords, $pageLength);
            $notes[] = $extractionNote;
        }

        $content ??= $convertFullPage($body, $markdown);

        return $this->withNotes($content, $notes);
    }

    /**
     * @param  list<string>  $keywords
     * @param  int  $pageLength  visible characters in the whole page, for the share test
     * @return array{0: ?string, 1: string} extracted content (null on fallback) and the note explaining why
     */
    private function extractArticle(
        string $body,
        string $finalUrl,
        bool $markdown,
        array $keywords,
        int $pageLength,
    ): array {
        try {
            $article = $this->extractor->extract($body, $finalUrl, $keywords, $markdown);
        } catch (\Throwable $e) {
            return [null, 'Extraction failed ('.$e->getMessage().'); returning the full page instead.'];
        }

        if ($article !== null && $this->isWorthReturning($article->visibleLength, $pageLength)) {
            return [
                $article->render(),
                'Extracted main content ('.$article->visibleLength.' visible characters). '
                    .'Navigation, sidebars and boilerplate were dropped; re-fetch with extract=false for the full page.',
            ];
        }

        $reason = $article === null
            ? 'no block scored above the surrounding boilerplate'
            : 'the best block held only '.$article->visibleLength.' of the page\'s '.$pageLength.' visible characters';

        return [null, 'Extraction skipped: '.$reason.'; returning the full page instead.'];
    }

    /**
     * Accept an extraction that is substantial on its own, or that kept a real
     * share of the page. The share test matters for genuinely short pages: a
     * 180-character notice is the whole article, not a failed extraction, and
     * an absolute floor alone would reject it.
     */
    private function isWorthReturning(int $extractedLength, int $pageLength): bool
    {
        if ($extractedLength >= PageQualitySignals::EXTRACTION_MIN_CHARS) {
            return true;
        }

        return $pageLength > 0 && $extractedLength >= (int) ceil($pageLength * self::MIN_RETAINED_SHARE);
    }

    /** @param list<string> $notes */
    private function withNotes(string $content, array $notes): string
    {
        if ($notes === []) {
            return $content;
        }

        $header = '';
        foreach ($notes as $note) {
            $header .= '[WebFetch] '.$note."\n";
        }

        return $header."\n".$content;
    }
}
