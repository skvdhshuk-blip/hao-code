<?php

namespace HaoCode\Services\Settings;

/** @internal */
final class ModelCatalog
{
    public const SONNET = 'claude-sonnet-4-6';

    public const OPUS = 'claude-opus-4-8';

    public const HAIKU = 'claude-haiku-4-5-20251001';

    /**
     * @return array<string, string>
     */
    public static function agentAliases(): array
    {
        return [
            'sonnet' => self::SONNET,
            'opus' => self::OPUS,
            'haiku' => self::HAIKU,
        ];
    }

    /**
     * @return list<string>
     */
    public static function availableModels(): array
    {
        return [
            'kimi-for-coding',
            self::SONNET,
            self::OPUS,
            self::HAIKU,
        ];
    }
}
