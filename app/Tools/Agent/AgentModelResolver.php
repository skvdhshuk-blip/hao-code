<?php

namespace HaoCode\Tools\Agent;

use HaoCode\Services\Settings\ModelCatalog;

/** @internal */
final class AgentModelResolver
{
    public static function resolve(
        ?string $callModel,
        ?string $definitionModel,
        string $providerType = 'anthropic',
    ): ?string
    {
        $selected = $callModel ?? $definitionModel;
        if ($selected === null || trim($selected) === '' || trim($selected) === 'inherit') {
            return null;
        }

        $selected = trim($selected);
        $aliases = ModelCatalog::agentAliases();
        if (! array_key_exists($selected, $aliases)) {
            throw new \InvalidArgumentException(
                "Unsupported agent model '{$selected}'. Expected sonnet, opus, haiku, or inherit.",
            );
        }

        // Tier aliases describe relative capability, not portable model IDs.
        // Non-Anthropic providers inherit the parent's already-valid model.
        return $providerType === 'anthropic' ? $aliases[$selected] : null;
    }
}
