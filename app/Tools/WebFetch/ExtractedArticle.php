<?php

namespace HaoCode\Tools\WebFetch;

/**
 * Result of a successful main-content extraction.
 *
 * @internal
 */
final class ExtractedArticle
{
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly int $visibleLength,
    ) {
    }

    /** Title plus body, ready to hand to the model. */
    public function render(): string
    {
        if ($this->title === '') {
            return $this->content;
        }

        return "# {$this->title}\n\n{$this->content}";
    }
}
