<?php

namespace HaoCode\Services\Agent;

/**
 * 在请求模型前估算可见上下文大小并执行硬上限保护。
 *
 * @internal
 */
final class ContextBudget
{
    public const MAX_ESTIMATED_INPUT_TOKENS = 167_000;

    private const TRUNCATION_MARKER = "\n[... context truncated by Hao Code budget ...]";

    public static function safeInputLimit(int $contextWindow, int $maxOutputTokens): int
    {
        $contextWindow = max(1, $contextWindow);
        $maxOutputTokens = max(0, $maxOutputTokens);
        $safetyMargin = max(4_000, (int) ceil($contextWindow * 0.05));

        return max(1, $contextWindow - $maxOutputTokens - $safetyMargin);
    }

    public static function estimateTokens(array $systemPrompt, array $messages, array $tools): int
    {
        $json = json_encode(
            ['system' => $systemPrompt, 'messages' => $messages, 'tools' => $tools],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($json)) {
            return PHP_INT_MAX;
        }

        return (int) ceil(mb_strlen($json) / 4);
    }

    public static function truncateFragment(string $content, int $maxChars): string
    {
        if (mb_strlen($content) <= $maxChars) {
            return $content;
        }

        return mb_substr($content, 0, $maxChars).self::TRUNCATION_MARKER;
    }
}
