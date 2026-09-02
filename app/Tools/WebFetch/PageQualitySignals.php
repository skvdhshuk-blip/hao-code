<?php

namespace HaoCode\Tools\WebFetch;

/**
 * Cheap heuristics that separate a useless HTML response from real content.
 *
 * A 403 UA-blacklist page, a Cloudflare interstitial and an unrendered SPA
 * shell all come back as HTTP 200-shaped HTML. Without these checks WebFetch
 * hands the agent a nav bar and a spinner and the agent believes it read the
 * page. Ported from tokimo-package-web-fetch (`src/cloudflare.rs`).
 *
 * @internal
 */
final class PageQualitySignals
{
    /** Below this many visible characters a page is treated as a shell. */
    public const SPA_BLANK_MIN_CHARS = 120;

    /** Extraction yielding less than this is not worth returning over the raw page. */
    public const EXTRACTION_MIN_CHARS = 200;

    /**
     * Large pages routinely mention "人机验证" inside legitimate article text.
     * Only a short page plus a marker is an actual wall.
     */
    private const ANTI_BOT_MAX_BYTES = 32768;

    /** Cloudflare / DDoS-Guard style interstitials. */
    private const CHALLENGE_MARKERS = [
        'just a moment',
        '请稍候',
        'cf-challenge-running',
        'cf-please-wait',
        'challenge-spinner',
        'trk_jschal_js',
        'ddos-guard',
    ];

    /**
     * Anti-bot / UA-blacklist / captcha walls that are not standard Cloudflare
     * challenges. `denied by ua acl` is Alibaba Tengine's default blacklist
     * response and is what a non-browser User-Agent gets from many CN CDNs.
     */
    private const ANTI_BOT_MARKERS = [
        'denied by ua acl',
        '百度安全验证',
        '网络不给力',
        'access denied',
        '人机验证',
        '滑动验证',
        '访问验证',
    ];

    public static function isUnderChallenge(string $html): bool
    {
        $lower = strtolower($html);
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function hasAntiBotWall(string $html): bool
    {
        if (strlen($html) > self::ANTI_BOT_MAX_BYTES) {
            return false;
        }

        $lower = strtolower($html);
        foreach (self::ANTI_BOT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return str_contains($lower, '403 forbidden') && strlen($html) < 2048;
    }

    /**
     * True when the HTML carries almost no text once scripts and tags are
     * dropped — the signature of a client-rendered page fetched statically.
     */
    public static function looksLikeSpaOrBlank(string $html, int $minChars = self::SPA_BLANK_MIN_CHARS): bool
    {
        return self::visibleTextLength(self::stripScriptsAndTags($html)) < $minChars;
    }

    /**
     * Human-readable reason the response looks unusable, or null when it looks
     * like a normal page. The wording tells the agent what to do next, since
     * this SDK deliberately ships no headless-browser escalation channel.
     */
    public static function describe(string $html): ?string
    {
        if (self::isUnderChallenge($html)) {
            return 'the response is a bot-challenge interstitial, not the page itself';
        }

        if (self::hasAntiBotWall($html)) {
            return 'the response is an access-denied / verification wall, not the page itself';
        }

        $visible = self::visibleTextLength(self::stripScriptsAndTags($html));
        if ($visible < self::SPA_BLANK_MIN_CHARS) {
            return "the response carries only {$visible} visible characters of static text — "
                .'either the page renders client-side or this is a stub, and a plain HTTP fetch cannot see more';
        }

        return null;
    }

    /**
     * Count visible characters, ignoring the URL half of Markdown links and
     * images. `[text](https://very/long/url)` would otherwise inflate a
     * nav-only page far past any content threshold.
     */
    public static function visibleTextLength(string $text): int
    {
        $length = strlen($text);
        $count = 0;
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            // Markdown link or image: [text](url) / ![alt](url)
            $bracketStart = null;
            if ($char === '!' && $i + 1 < $length && $text[$i + 1] === '[') {
                $bracketStart = $i + 2;
            } elseif ($char === '[') {
                $bracketStart = $i + 1;
            }

            if ($bracketStart === null) {
                $count += self::countsAsVisible($text, $i) ? 1 : 0;
                $i++;
                continue;
            }

            $close = self::matchingBracket($text, $bracketStart, $length);
            if ($close === null || $close + 1 >= $length || $text[$close + 1] !== '(') {
                $count += self::countsAsVisible($text, $i) ? 1 : 0;
                $i++;
                continue;
            }

            $paren = strpos($text, ')', $close + 2);
            if ($paren === false) {
                $count += self::countsAsVisible($text, $i) ? 1 : 0;
                $i++;
                continue;
            }

            for ($j = $bracketStart; $j < $close; $j++) {
                $count += self::countsAsVisible($text, $j) ? 1 : 0;
            }
            $i = $paren + 1;
        }

        return $count;
    }

    /** Drop script/style/noscript bodies and every tag. Heuristic, not a parser. */
    public static function stripScriptsAndTags(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1\s*>/si', ' ', $html) ?? $html;
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*$/si', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]*>/s', ' ', $html) ?? $html;

        return $html;
    }

    /**
     * A byte counts as one visible character when it starts a UTF-8 sequence
     * (continuation bytes are 10xxxxxx) and is not whitespace. Counting bytes
     * this way avoids splitting a 100k-character page into an array.
     *
     * The multi-byte cases are the spaces that actually show up in CJK pages:
     * NBSP (U+00A0), ideographic space (U+3000) and zero-width space (U+200B).
     */
    private static function countsAsVisible(string $text, int $offset): bool
    {
        $byte = ord($text[$offset]);
        if (($byte & 0xC0) === 0x80) {
            return false;
        }

        if ($byte <= 0x20) {
            return false;
        }

        if ($byte === 0xC2) {
            return ($text[$offset + 1] ?? '') !== "\xA0";
        }

        if ($byte === 0xE3 || $byte === 0xE2) {
            $sequence = substr($text, $offset, 3);

            return $sequence !== "\xE3\x80\x80" && $sequence !== "\xE2\x80\x8B";
        }

        return true;
    }

    /** Index of the `]` matching an opening bracket, honouring nesting. */
    private static function matchingBracket(string $text, int $from, int $length): ?int
    {
        $depth = 1;
        for ($j = $from; $j < $length; $j++) {
            if ($text[$j] === '[') {
                $depth++;
            } elseif ($text[$j] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }
}
