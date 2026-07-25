<?php

namespace HaoCode\Services\Settings;

/** @internal */
final class ModelCatalog
{
    public const SONNET = 'claude-sonnet-4-6';

    public const OPUS = 'claude-opus-4-8';

    public const HAIKU = 'claude-haiku-4-5-20251001';

    public const SONNET_5 = 'claude-sonnet-5';

    public const OPUS_5 = 'claude-opus-5';

    public const FABLE_5 = 'claude-fable-5';

    /**
     * Standard Claude API pricing per million tokens.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     *
     * @var array<string, array{input: float, output: float, cache_write: float, cache_read: float}>
     */
    private const PRICING = [
        self::FABLE_5 => [
            'input' => 10.0,
            'output' => 50.0,
            'cache_write' => 12.50,
            'cache_read' => 1.0,
        ],
        self::OPUS_5 => [
            'input' => 5.0,
            'output' => 25.0,
            'cache_write' => 6.25,
            'cache_read' => 0.50,
        ],
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
            self::FABLE_5,
            self::OPUS_5,
            self::SONNET_5,
            self::SONNET,
            self::OPUS,
            self::HAIKU,
        ];
    }

    public static function requiresAdaptiveThinking(string $model): bool
    {
        $normalized = strtolower(trim($model));

        foreach ([
            self::FABLE_5,
            self::OPUS_5,
            self::SONNET_5,
            self::OPUS,
            'claude-opus-4-7',
        ] as $modelId) {
            if ($normalized === $modelId || str_starts_with($normalized, $modelId.'-')) {
                return true;
            }
        }

        return false;
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
        if ($normalized === self::SONNET_5 || str_starts_with($normalized, self::SONNET_5.'-')) {
            if (time() < strtotime('2026-09-01T00:00:00Z')) {
                return [
                    'input' => 2.0,
                    'output' => 10.0,
                    'cache_write' => 2.50,
                    'cache_read' => 0.20,
                ];
            }

            return [
                'input' => 3.0,
                'output' => 15.0,
                'cache_write' => 3.75,
                'cache_read' => 0.30,
            ];
        }
        foreach (self::PRICING as $modelId => $pricing) {
            if ($normalized === $modelId || str_starts_with($normalized, $modelId.'-')) {
                return $pricing;
            }
        }

        return null;
    }
}
