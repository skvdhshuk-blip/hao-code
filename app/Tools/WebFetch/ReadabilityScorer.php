<?php

namespace HaoCode\Tools\WebFetch;

use DOMElement;
use DOMNode;

/**
 * Readability-style scoring: rank block elements by how much prose they hold,
 * then return the winning subtree plus the siblings that belong with it.
 *
 * Ported from tokimo-package-web-fetch, which reaches the same result through
 * `dom_smoothie` plus the `keyword_boost` pre-pass that injects positive CSS
 * classes into keyword-bearing blocks. Owning the scorer here means the
 * keyword weight is applied directly instead of smuggled in through class
 * names and zero-width padding.
 *
 * @internal
 */
final class ReadabilityScorer
{
    /**
     * Weight added to the block containing a caller keyword. Matches the four
     * positive classes (25 points each) the Rust pre-pass injects.
     */
    private const KEYWORD_BLOCK_BOOST = 100.0;

    /** Extra weight on the keyword-bearing paragraph itself. */
    private const KEYWORD_NODE_BONUS = 3.0;

    /** Prose shorter than this is ignored unless it carries a keyword. */
    private const MIN_PARAGRAPH_CHARS = 25;

    /** How far a paragraph's score propagates upward. */
    private const MAX_ANCESTOR_DEPTH = 5;

    /** Preferred targets for the keyword boost. */
    private const CONTAINER_TAGS = [
        'div', 'section', 'article', 'main', 'aside', 'header', 'footer',
        'nav', 'figure', 'table', 'form',
    ];

    /** Fallback boost targets when no container ancestor is in reach. */
    private const LEAF_BLOCK_TAGS = [
        'p', 'pre', 'blockquote', 'figcaption', 'ul', 'ol', 'dl', 'fieldset',
    ];

    private const POSITIVE_PATTERN = '/article|body|content|entry|hentry|h-entry|main|page|pagination|post|text|blog|story|正文|内容/i';

    private const NEGATIVE_PATTERN = '/hidden|banner|combx|comment|com-|contact|foot|footer|footnote|gdpr|masthead|media|meta|outbrain|promo|related|scroll|share|shoutbox|sidebar|skyscraper|sponsor|shopping|tags|widget|广告/i';

    /** @var array<int, float> spl_object_id => score */
    private array $scores = [];

    /** @var array<int, DOMElement> spl_object_id => element */
    private array $scored = [];

    /** @var array<int, true> boost targets already credited */
    private array $boosted = [];

    /** @param list<string> $keywords lower-cased */
    public function __construct(private readonly array $keywords)
    {
    }

    /**
     * Winning subtree first, then sibling blocks that clear the inclusion bar.
     *
     * @return list<DOMElement>
     */
    public function selectContent(ReadabilityDocument $document): array
    {
        $body = $document->body;
        if ($body === null) {
            return [];
        }

        foreach (iterator_to_array($body->getElementsByTagName('*')) as $element) {
            if ($element instanceof DOMElement && $this->isParagraphLike($element)) {
                $this->scoreParagraph($element);
            }
        }

        if ($this->scored === []) {
            return [];
        }

        $top = $this->topCandidate($body);
        if ($top === null) {
            return [];
        }

        return array_merge([$top], $this->siblingsOf($top));
    }

    /** Score one paragraph and propagate the result to its ancestors. */
    private function scoreParagraph(DOMElement $element): void
    {
        $text = trim((string) $element->textContent);
        $length = mb_strlen($text, 'UTF-8');
        $hasKeyword = ReadabilityDocument::mentionsKeyword($text, $this->keywords);

        if ($length < self::MIN_PARAGRAPH_CHARS && ! $hasKeyword) {
            return;
        }

        // A keyword hit earns the full length bonus outright. Weather figures
        // and price tables are short by nature; the Rust port buys the same
        // effect by padding the element with zero-width filler characters.
        $lengthBonus = $hasKeyword ? 3 : min(intdiv($length, 100), 3);
        $score = 1.0 + $this->separatorCount($text) + $lengthBonus;
        if ($hasKeyword) {
            $score += self::KEYWORD_NODE_BONUS;
        }

        $depth = 0;
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement && $depth < self::MAX_ANCESTOR_DEPTH) {
            $divisor = match (true) {
                $depth === 0 => 1.0,
                $depth === 1 => 2.0,
                default => $depth * 3.0,
            };
            $this->addScore($ancestor, $score / $divisor);
            $ancestor = $ancestor->parentNode;
            $depth++;
        }

        if ($hasKeyword) {
            $this->applyKeywordBoost($element);
        }
    }

    /**
     * Credit the nearest sensible container ancestor once per element, so a
     * block repeating a keyword ten times does not outrank the real article.
     */
    private function applyKeywordBoost(DOMElement $element): void
    {
        $target = $this->boostTarget($element);
        if ($target === null) {
            return;
        }

        $id = spl_object_id($target);
        if (isset($this->boosted[$id])) {
            return;
        }

        $this->boosted[$id] = true;
        $this->addScore($target, self::KEYWORD_BLOCK_BOOST);
    }

    /**
     * Walk up at most five levels looking for a container with an id or class
     * (the block a page author actually named), falling back to any container
     * and finally to a leaf block. Mirrors `find_boost_target` in the Rust
     * crate; inline wrappers such as `<span>` are never chosen.
     */
    private function boostTarget(DOMElement $element): ?DOMElement
    {
        $containerFallback = null;
        $leafFallback = null;
        $depth = 0;
        $node = $element->parentNode;

        while ($node instanceof DOMElement && $depth < self::MAX_ANCESTOR_DEPTH) {
            $tag = strtolower($node->tagName);
            if ($tag === 'body' || $tag === 'html') {
                break;
            }

            $isContainer = in_array($tag, self::CONTAINER_TAGS, true);
            $isLeaf = in_array($tag, self::LEAF_BLOCK_TAGS, true);
            $named = $node->getAttribute('id') !== '' || $node->getAttribute('class') !== '';

            if ($isContainer && $named) {
                return $node;
            }
            if ($isContainer && $containerFallback === null) {
                $containerFallback = $node;
            }
            if ($isLeaf && $named && $leafFallback === null) {
                $leafFallback = $node;
            }

            $node = $node->parentNode;
            $depth++;
        }

        return $containerFallback ?? $leafFallback;
    }

    /**
     * Highest final score, then promote to any ancestor that scores at least
     * as well — a wrapper holding the article plus its heading beats the
     * article body alone.
     */
    private function topCandidate(DOMElement $body): ?DOMElement
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($this->scored as $id => $element) {
            $score = $this->finalScore($element, $this->scores[$id]);
            if ($best === null || $score > $bestScore) {
                $best = $element;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        $parent = $best->parentNode;
        while ($parent instanceof DOMElement && $parent !== $body) {
            $parentId = spl_object_id($parent);
            if (! isset($this->scores[$parentId])) {
                break;
            }
            $parentScore = $this->finalScore($parent, $this->scores[$parentId]);
            if ($parentScore < $bestScore) {
                break;
            }
            $best = $parent;
            $bestScore = $parentScore;
            $parent = $parent->parentNode;
        }

        return $best;
    }

    /**
     * Sibling blocks worth keeping: a multi-column article body, or the lead
     * paragraph sitting outside the scored wrapper.
     *
     * @return list<DOMElement>
     */
    private function siblingsOf(DOMElement $top): array
    {
        $parent = $top->parentNode;
        if (! $parent instanceof DOMElement) {
            return [];
        }

        $topId = spl_object_id($top);
        $threshold = max(10.0, $this->finalScore($top, $this->scores[$topId] ?? 0.0) * 0.2);
        $siblings = [];

        foreach ($parent->childNodes as $sibling) {
            if (! $sibling instanceof DOMElement || $sibling === $top) {
                continue;
            }

            $id = spl_object_id($sibling);
            if (isset($this->scores[$id]) && $this->finalScore($sibling, $this->scores[$id]) >= $threshold) {
                $siblings[] = $sibling;
                continue;
            }

            $text = trim((string) $sibling->textContent);
            if (ReadabilityDocument::mentionsKeyword($text, $this->keywords)) {
                $siblings[] = $sibling;
                continue;
            }

            if (strtolower($sibling->tagName) === 'p'
                && mb_strlen($text, 'UTF-8') > 80
                && $this->linkDensity($sibling) < 0.25) {
                $siblings[] = $sibling;
            }
        }

        return $siblings;
    }

    /** Accumulated score adjusted for markup quality and link density. */
    private function finalScore(DOMElement $element, float $raw): float
    {
        return ($raw + $this->tagBaseScore($element) + $this->classWeight($element))
            * (1.0 - $this->linkDensity($element));
    }

    private function addScore(DOMElement $element, float $delta): void
    {
        $id = spl_object_id($element);
        $this->scored[$id] = $element;
        $this->scores[$id] = ($this->scores[$id] ?? 0.0) + $delta;
    }

    /**
     * A block whose text is mostly anchor text is a link list, not an article.
     */
    private function linkDensity(DOMElement $element): float
    {
        $total = mb_strlen(trim((string) $element->textContent), 'UTF-8');
        if ($total === 0) {
            return 0.0;
        }

        $linked = 0;
        foreach ($element->getElementsByTagName('a') as $anchor) {
            $linked += mb_strlen(trim((string) $anchor->textContent), 'UTF-8');
        }

        return min(1.0, $linked / $total);
    }

    private function tagBaseScore(DOMElement $element): float
    {
        return match (strtolower($element->tagName)) {
            'article', 'main', 'section', 'div' => 5.0,
            'pre', 'td', 'blockquote' => 3.0,
            'address', 'ol', 'ul', 'dl', 'dd', 'dt', 'li', 'form' => -3.0,
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'th' => -5.0,
            default => 0.0,
        };
    }

    private function classWeight(DOMElement $element): float
    {
        $signature = $element->getAttribute('class').' '.$element->getAttribute('id');
        $weight = 0.0;
        if (preg_match(self::POSITIVE_PATTERN, $signature) === 1) {
            $weight += 25.0;
        }
        if (preg_match(self::NEGATIVE_PATTERN, $signature) === 1) {
            $weight -= 25.0;
        }

        return $weight;
    }

    /** Sentence separators, counted for both Latin and CJK punctuation. */
    private function separatorCount(string $text): int
    {
        return substr_count($text, ',')
            + substr_count($text, '，')
            + substr_count($text, '、')
            + substr_count($text, '。');
    }

    private function isParagraphLike(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, ['p', 'pre', 'td', 'blockquote'], true)) {
            return true;
        }

        if (! in_array($tag, ['div', 'section', 'article'], true)) {
            return false;
        }

        // A div counts as a paragraph only when it holds text directly rather
        // than wrapping further blocks, matching Readability's div-to-p pass.
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlockElement($child)) {
                return false;
            }
        }

        return true;
    }

    private function isBlockElement(DOMNode $node): bool
    {
        if (! $node instanceof DOMElement) {
            return false;
        }

        return in_array(strtolower($node->tagName), [
            'div', 'section', 'article', 'p', 'pre', 'table', 'ul', 'ol', 'dl',
            'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'aside', 'nav',
            'footer', 'header', 'main', 'figure',
        ], true);
    }
}
