<?php

namespace HaoCode\Services\Settings;

/** @internal */
final class ModelCatalog
{
    public const SONNET = 'claude-sonnet-4-6';

    public const OPUS = 'claude-opus-4-8';

    public const HAIKU = 'claude-haiku-4-5-20251001';

    /**
     * Standard Claude API pricing per million tokens.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     *
     * @var array<string, array{input: float, output: float, cache_write: float, cache_read: float}>
     */
    private const PRICING = [
        self::OPUS => [
            'input' => 5.0,
            'output' => 25.0,
            'cache_write' => 6.25,
            'cache_read' => 0.50,
        ],
        self::SONNET => [
            'input' => 3.0,
            'output' => 15.0,
            'cache_write' => 3.75,
            'cache_read' => 0.30,
        ],
        self::HAIKU => [
            'input' => 1.0,
            'output' => 5.0,
            'cache_write' => 1.25,
            'cache_read' => 0.10,
        ],
    ];

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

    /**
     * @return array{input: float, output: float, cache_write: float, cache_read: float}|null
     */
    public static function pricingFor(string $providerType, string $model): ?array
    {
        if ($providerType !== 'anthropic') {
            return null;
        }

        $normalized = strtolower(trim($model));
        foreach (self::PRICING as $modelId => $pricing) {
            if ($normalized === $modelId || str_starts_with($normalized, $modelId.'-')) {
                return $pricing;
            }
        }

        return null;
    }
}
