<?php

namespace HaoCode\Tools\WebFetch;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns the winning DOM subtree into the text the model reads.
 *
 * Unlike the regex conversion used for whole pages, this walks real nodes, so
 * block boundaries, list items and code fences survive tags the regex pass
 * would flatten.
 *
 * @internal
 */
final class ReadabilitySerializer
{
    private const BLOCK_TAGS = [
        'p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside',
        'figure', 'figcaption', 'blockquote', 'ul', 'ol', 'dl', 'dd', 'dt',
        'table', 'thead', 'tbody', 'tr', 'form', 'address',
    ];

    /** @var list<string> */
    private array $blocks = [];

    private string $buffer = '';

    public function __construct(
        private readonly bool $markdown,
        private readonly string $baseUrl,
    ) {
    }

    /** @param list<DOMElement> $nodes */
    public function render(array $nodes): string
    {
        $this->blocks = [];
        $this->buffer = '';

        foreach ($nodes as $node) {
            $this->walk($node);
            $this->flush();
        }
        $this->flush();

        $text = implode("\n\n", $this->blocks);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function walk(DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            $this->appendInline((string) $node->nodeValue);

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        match (strtolower($node->tagName)) {
            'br' => $this->flush(),
            'hr' => $this->pushBlock($this->markdown ? '---' : ''),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->emitHeading($node),
            'pre' => $this->emitPreformatted($node),
            'li' => $this->emitListItem($node),
            'a' => $this->emitAnchor($node),
            'img' => $this->emitImage($node),
            'strong', 'b' => $this->emitEmphasis($node, '**'),
            'em', 'i' => $this->emitEmphasis($node, '*'),
            'code' => $this->emitEmphasis($node, '`'),
            'td', 'th' => $this->emitCell($node),
            default => $this->emitDefault($node),
        };
    }

    private function emitDefault(DOMElement $node): void
    {
        $isBlock = in_array(strtolower($node->tagName), self::BLOCK_TAGS, true);
        if ($isBlock) {
            $this->flush();
        }

        foreach ($node->childNodes as $child) {
            $this->walk($child);
        }

        if ($isBlock) {
            $this->flush();
        }
    }

    private function emitHeading(DOMElement $node): void
    {
        $level = (int) substr(strtolower($node->tagName), 1);
        $text = $this->captureInline($node);
        if ($text === '') {
            return;
        }

        $this->pushBlock($this->markdown ? str_repeat('#', $level).' '.$text : $text);
    }

    private function emitPreformatted(DOMElement $node): void
    {
        $code = rtrim((string) $node->textContent);
        if (trim($code) === '') {
            return;
        }

        $this->pushBlock($this->markdown ? "```\n{$code}\n```" : $code);
    }

    private function emitListItem(DOMElement $node): void
    {
        $text = $this->captureInline($node);
        if ($text !== '') {
            $this->pushBlock('- '.$text);
        }
    }

    private function emitAnchor(DOMElement $node): void
    {
        $text = $this->captureInline($node);
        if ($text === '') {
            return;
        }

        $href = $this->markdown ? $this->absoluteUrl(trim($node->getAttribute('href'))) : null;
        $this->appendInline($href === null ? $text : "[{$text}]({$href})");
    }

    private function emitImage(DOMElement $node): void
    {
        $alt = trim($node->getAttribute('alt'));
        if ($alt === '') {
            return;
        }

        $src = $this->markdown ? $this->absoluteUrl(trim($node->getAttribute('src'))) : null;
        $this->appendInline($src === null ? $alt : "![{$alt}]({$src})");
    }

    private function emitEmphasis(DOMElement $node, string $marker): void
    {
        $text = $this->captureInline($node);
        if ($text === '') {
            return;
        }

        $this->appendInline($this->markdown ? $marker.$text.$marker : $text);
    }

    private function emitCell(DOMElement $node): void
    {
        $text = $this->captureInline($node);
        if ($text !== '') {
            $this->appendInline($text.' | ');
        }
    }

    /** Render a subtree as one inline string without disturbing the outer buffer. */
    private function captureInline(DOMNode $node): string
    {
        $savedBlocks = $this->blocks;
        $savedBuffer = $this->buffer;
        $this->blocks = [];
        $this->buffer = '';

        foreach ($node->childNodes as $child) {
            $this->walk($child);
        }
        $this->flush();
        $captured = trim(implode(' ', $this->blocks));

        $this->blocks = $savedBlocks;
        $this->buffer = $savedBuffer;

        return preg_replace('/\s+/u', ' ', $captured) ?? $captured;
    }

    private function appendInline(string $text): void
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        if (trim($text) === '') {
            // Keep a single separator so adjacent inline elements do not fuse.
            if ($this->buffer !== '' && ! str_ends_with($this->buffer, ' ')) {
                $this->buffer .= ' ';
            }

            return;
        }

        $this->buffer .= $text;
    }

    private function flush(): void
    {
        $line = trim($this->buffer);
        $this->buffer = '';
        if ($line !== '') {
            $this->blocks[] = $line;
        }
    }

    private function pushBlock(string $block): void
    {
        $this->flush();
        if (trim($block) !== '') {
            $this->blocks[] = $block;
        }
    }

    /**
     * Resolve `$href` against the page URL. Returns null for empty, fragment,
     * or javascript targets so the caller falls back to plain link text rather
     * than emitting a link the agent cannot follow.
     */
    private function absoluteUrl(string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https', 'mailto'], true) ? $href : null;
        }

        $base = parse_url($this->baseUrl);
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($href, '//')) {
            return $base['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        if (str_starts_with($href, '?')) {
            return $origin.($base['path'] ?? '/').$href;
        }

        $directory = substr((string) ($base['path'] ?? '/'), 0, (int) strrpos((string) ($base['path'] ?? '/'), '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$href;
    }
}
