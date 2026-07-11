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
}
