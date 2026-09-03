<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** @internal */
abstract class AbstractHtmlEngine implements EngineInterface
{
    final public function weight(): float
    {
        return 1.0;
    }

    final public function timeoutMs(): int
    {
        return 5000;
    }

    protected function document(string $html): ?DOMDocument
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

    protected function first(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    protected function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    protected function isChallenge(string $html): bool
    {
        return preg_match(
            '/(?:class|id|name|action|src)\s*=\s*["\'][^"\']*(?:captcha|recaptcha|challenge|antispider|anomaly-modal|\/sorry\/)[^"\']*["\']/i',
            $html,
        ) === 1
            || stripos($html, 'unusual traffic from your computer network') !== false
            || stripos($html, 'bots use duckduckgo') !== false;
    }

    protected function result(string $title, string $url, string $snippet = ''): ?RawSearchResult
    {
        return RawSearchResult::from($title, $url, $snippet);
    }
}
