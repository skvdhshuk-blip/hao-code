<?php

namespace HaoCode\Tools\WebFetch;

use HaoCode\Support\Net\SsrfGuard;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait WebFetchToolNormalizePathConcern
{

    private function normalizePath(string $path): string
    {
        // RFC 3986 section 5.2.4 remove_dot_segments algorithm. Keeping the
        // input/output form (rather than treating paths as filesystem names)
        // preserves repeated slashes, path parameters, and trailing slashes.
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
                continue;
            }
            if (str_starts_with($input, './')) {
                $input = substr($input, 2);
                continue;
            }
            if ($input === '..' || $input === '.') {
                $input = '';
                continue;
            }
            if (str_starts_with($input, '/../')) {
                $input = '/'.substr($input, 4);
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if ($input === '/..') {
                $input = '/';
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if (str_starts_with($input, '/./')) {
                $input = '/'.substr($input, 3);
                continue;
            }
            if ($input === '/.') {
                $input = '/';
                continue;
            }

            // Move the first path segment (with its leading slash, when
            // present) from input to output.
            $slash = strpos($input, '/', str_starts_with($input, '/') ? 1 : 0);
            if ($slash === false) {
                $output .= $input;
                $input = '';
            } else {
                $output .= substr($input, 0, $slash);
                $input = substr($input, $slash);
            }
        }

        return $output === '' ? '/' : $output;
    }

    private function removeLastPathSegment(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    private function streamWithByteCap(
        $response,
        int $maxBytes,
        ?callable $shouldAbort = null,
    ): string
    {
        $this->throwIfAborted($shouldAbort);
        $chunks = [];
        $total = 0;
        foreach ($this->client()->stream($response) as $chunk) {
            $this->throwIfAborted($shouldAbort);
            if ($chunk->isTimeout()) {
                continue;
            }
            if (! $chunk->isLast()) {
                $data = $chunk->getContent();
                $total += strlen($data);
                if ($total > $maxBytes) {
                    $response->cancel();
                    throw new \RuntimeException(
                        "Response exceeded {$maxBytes} byte cap and was aborted.",
                    );
                }
                $chunks[] = $data;
            }
        }

        return implode('', $chunks);
    }

    private function isAllowedTextContentType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType === '') {
            return false;
        }

        return str_starts_with($mediaType, 'text/')
            || in_array($mediaType, [
                'application/json',
                'application/xml',
                'application/xhtml+xml',
                'application/javascript',
                'application/x-javascript',
            ], true)
            || str_ends_with($mediaType, '+json')
            || str_ends_with($mediaType, '+xml');
    }

    private function normalizeUtf8Text(string $content): string
    {
        if (preg_match('//u', $content) === 1) {
            return $content;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return preg_replace('/[\x80-\xFF]/', '', $content) ?? '';
    }

    private function throwIfAborted(?callable $shouldAbort): void
    {
        if ($shouldAbort === null) {
            return;
        }

        try {
            $aborted = (bool) $shouldAbort();
        } catch (\Throwable $e) {
            throw new WebFetchAbortedException('WebFetch abort probe failed.', 0, $e);
        }

        if ($aborted) {
            throw new WebFetchAbortedException('WebFetch was aborted.');
        }
    }

    /**
     * Truncate the rendered output to MAX_CONTENT_SIZE units while retaining
     * the useful prefix. The marker is part of the returned content so callers
     * cannot accidentally replace the page with a marker-only response.
     *
     * @return array{0: string, 1: string} [content, unit label]
     */
    private function truncateForOutput(string $content): array
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($content, 'UTF-8') <= self::MAX_CONTENT_SIZE) {
                return [$content, 'characters'];
            }

            $prefix = mb_substr($content, 0, self::MAX_CONTENT_SIZE, 'UTF-8');
            return [$prefix."\n\n[Content truncated at ".self::MAX_CONTENT_SIZE.' characters]', 'characters'];
        }

        if (strlen($content) <= self::MAX_CONTENT_SIZE) {
            return [$content, 'bytes'];
        }

        $prefix = $this->truncateUtf8ByBytes($content, self::MAX_CONTENT_SIZE);
        return [$prefix."\n\n[Content truncated at ".self::MAX_CONTENT_SIZE.' bytes]', 'bytes'];
    }

    private function truncateUtf8ByBytes(string $content, int $limit): string
    {
        $length = min(strlen($content), $limit);
        if ($length === 0) {
            return '';
        }

        // Remove a partial UTF-8 sequence at the cut boundary. This keeps
        // output valid without requiring ext-mbstring; invalid bytes already
        // present in the source are left untouched.
        $lead = $length - 1;
        while ($lead >= 0 && (ord($content[$lead]) & 0xC0) === 0x80) {
            $lead--;
        }
        if ($lead >= 0) {
            $first = ord($content[$lead]);
            $expected = $first < 0x80 ? 1 : ($first < 0xE0 ? 2 : ($first < 0xF0 ? 3 : ($first < 0xF8 ? 4 : 1)));
            if ($expected > $length - $lead) {
                $length = $lead;
            }
        }

        return substr($content, 0, $length);
    }

    /**
     * Plain-text rendering: strips scripts/styles/nav, collapses block tags
     * to whitespace, and removes all remaining markup. No markdown markers.
     */
    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);

        // Convert block-level elements to whitespace before stripping tags so
        // adjacent text segments do not collapse together.
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|br)\s*>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', '- ', $html);

        // Strip link URLs but keep the link text.
        $html = preg_replace('/<a[^>]*>(.*?)<\/a>/si', '$1', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Markdown rendering: preserves headings, links, emphasis, and code blocks.
     */
    private function htmlToMarkdown(string $html): string
    {
        // Remove scripts, styles, and HTML comments
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);

        // Convert headings to markdown-style
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/si', "\n# $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/si', "\n## $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/si', "\n### $1\n", $html);
        $html = preg_replace('/<h[4-6][^>]*>(.*?)<\/h[4-6]>/si', "\n#### $1\n", $html);

        // Convert links to markdown
        $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html);

        // Convert common elements to text
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "- ", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);

        // Convert code blocks
        $html = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html);
        $html = preg_replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html);

        // Bold and italic
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/si', '**$2**', $html);
        $html = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/si', '*$2*', $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
