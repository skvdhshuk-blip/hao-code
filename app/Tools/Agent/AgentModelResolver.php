<?php

namespace HaoCode\Tools\Agent;

/** @internal */
final class AgentModelResolver
{
    private const MODEL_ALIASES = [
        'sonnet' => 'claude-sonnet-4-20250514',
        'opus' => 'claude-opus-4-20250514',
        'haiku' => 'claude-haiku-4-20250514',
    ];

    public static function resolve(?string $callModel, ?string $definitionModel): ?string
    {
        $selected = $callModel ?? $definitionModel;
        if ($selected === null || trim($selected) === '' || trim($selected) === 'inherit') {
            return null;
        }

        $selected = trim($selected);
        if (! array_key_exists($selected, self::MODEL_ALIASES)) {
            throw new \InvalidArgumentException(
                "Unsupported agent model '{$selected}'. Expected sonnet, opus, haiku, or inherit.",
            );
        }

        return self::MODEL_ALIASES[$selected];
    }
}
