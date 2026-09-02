<?php

namespace HaoCode\Tools\WebFetch;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parses fetched HTML into a DOM tree and strips the parts that can never be
 * article content, so the scorer only ever ranks plausible candidates.
 *
 * @internal
 */
final class ReadabilityDocument
{
    /** Elements whose subtree is never content. */
    private const DROPPED_TAGS = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'math',
        'iframe', 'object', 'embed', 'applet', 'form', 'input', 'select',
        'textarea', 'button', 'label', 'link', 'meta', 'base',
    ];

    /**
     * class/id substrings that mark chrome rather than content. Matched as a
     * substring so `post-comments-wrapper` is caught the same as `comments`.
     */
    private const UNLIKELY_CANDIDATES = [
        'banner', 'breadcrumb', 'combx', 'comment', 'community', 'cover-wrap',
        'disqus', 'extra', 'foot', 'gdpr', 'header', 'legends', 'menu',
        'related', 'remark', 'replies', 'rss', 'shoutbox', 'sidebar',
        'skyscraper', 'social', 'sponsor', 'supplemental', 'ad-break',
        'agegate', 'pagination', 'pager', 'popup', 'yom-remote', 'share',
        'copyright', 'nav-', 'navbar', 'sidenav', 'subscribe', 'newsletter',
        '广告', '推荐阅读', '相关推荐',
    ];

    /** Overrides UNLIKELY_CANDIDATES — `main-header` is still content. */
    private const MAYBE_CANDIDATES = [
        'and', 'article', 'body', 'column', 'content', 'main', 'shadow',
        'post', 'entry', '正文', '内容',
    ];

    private function __construct(
        public readonly DOMDocument $dom,
        public readonly ?DOMElement $body,
    ) {
    }

    /** Returns null when the HTML cannot be parsed into a usable body. */
    public static function parse(string $html): ?self
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            // Whitespace text nodes are kept on purpose: dropping them fuses
            // adjacent inline elements ("<b>a</b> <b>b</b>" -> "ab"). The
            // serializer collapses runs of whitespace itself.
            $dom->preserveWhiteSpace = true;
            $loaded = $dom->loadHTML(
                self::withCharsetDeclaration($html),
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            return null;
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        return new self($dom, $body instanceof DOMElement ? $body : null);
    }

    /** Document `<title>`, trimmed. Empty string when absent. */
    public function documentTitle(): string
    {
        $node = $this->dom->getElementsByTagName('title')->item(0);

        return $node === null ? '' : trim((string) $node->textContent);
    }

    /**
     * Remove non-content subtrees in place.
     *
     * `$keywords` protects a branch from the unlikely-candidate sweep: a page
     * whose real payload sits in a `div#sidebar-weather` would otherwise lose
     * exactly the block the caller asked for.
     *
     * @param  list<string>  $keywords  lower-cased
     */
    public function clean(array $keywords): void
    {
        $this->removeNodes($this->dom->getElementsByTagName('*'), static function (DOMNode $node): bool {
            return $node instanceof DOMElement
                && in_array(strtolower($node->tagName), self::DROPPED_TAGS, true);
        });

        $xpath = new DOMXPath($this->dom);
        $comments = $xpath->query('//comment()');
        if ($comments !== false) {
            $this->removeNodes($comments, static fn (): bool => true);
        }

        $this->removeNodes(
            $this->dom->getElementsByTagName('*'),
            fn (DOMNode $node): bool => $node instanceof DOMElement
                && $this->isUnlikelyCandidate($node, $keywords),
        );
    }

    /** True when `$element`'s tag, class or id marks it as page chrome. */
    private function isUnlikelyCandidate(DOMElement $element, array $keywords): bool
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, ['body', 'html', 'main', 'article'], true)) {
            return false;
        }

        $signature = strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));
        $isChromeTag = in_array($tag, ['nav', 'aside', 'footer'], true);

        if (! $isChromeTag && ! self::containsAny($signature, self::UNLIKELY_CANDIDATES)) {
            return false;
        }

        if (self::containsAny($signature, self::MAYBE_CANDIDATES)) {
            return false;
        }

        return ! self::mentionsKeyword((string) $element->textContent, $keywords);
    }

    /** @param list<string> $keywords lower-cased */
    public static function mentionsKeyword(string $text, array $keywords): bool
    {
        if ($keywords === []) {
            return false;
        }

        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detaching during a live NodeList traversal skips siblings, so collect
     * first and remove afterwards.
     *
     * @param  iterable<DOMNode>  $nodes
     * @param  callable(DOMNode): bool  $shouldRemove
     */
    private function removeNodes(iterable $nodes, callable $shouldRemove): void
    {
        $doomed = [];
        foreach ($nodes as $node) {
            if ($shouldRemove($node)) {
                $doomed[] = $node;
            }
        }

        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * libxml guesses Latin-1 without an explicit charset. The content reaching
     * here is already normalised to UTF-8, so state that rather than let a
     * CJK page come back as mojibake.
     */
    private static function withCharsetDeclaration(string $html): string
    {
        if (preg_match('/<meta[^>]+charset\s*=/i', $html) === 1) {
            return $html;
        }

        return '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html;
    }
}
