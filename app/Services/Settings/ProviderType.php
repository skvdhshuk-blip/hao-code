<?php

namespace HaoCode\Services\Settings;

/**
 * Canonical provider wire-format identifiers and fail-closed normalization.
 *
 * @internal
 */
final class ProviderType
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI = 'openai';

    public const OPENAI_CHAT = 'openai_chat';

    /**
     * Normalize an explicit provider type.
     *
     * null / empty means "unset" and returns null so callers can fall back to
     * settings defaults. Any non-empty unknown value throws — never silently
     * degrade to Anthropic (which would risk sending the wrong credentials).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeExplicit(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return self::normalizeRequired($normalized, $value);
    }

    /**
     * Normalize a required provider type string. null becomes anthropic only
     * when the caller intentionally wants the legacy default for missing type.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeRequired(?string $value, ?string $displayValue = null): string
    {
        if ($value === null || trim($value) === '') {
            return self::ANTHROPIC;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'anthropic' => self::ANTHROPIC,
            'openai', 'openai_responses', 'responses' => self::OPENAI,
            'openai_chat', 'openai_chat_completions', 'chat_completions' => self::OPENAI_CHAT,
            default => throw new \InvalidArgumentException(
                'Unsupported provider type: '.($displayValue ?? $value).'. '
                .'Expected anthropic, openai, or openai_chat.',
            ),
        };
    }

    /**
     * Best-effort map of a settings provider name to a known type. Returns
     * null when the name is not itself a known type alias (e.g. "openai-main").
     */
    public static function tryFromName(string $name): ?string
    {
        try {
            return self::normalizeExplicit($name);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
