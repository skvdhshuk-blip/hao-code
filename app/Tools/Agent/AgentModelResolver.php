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
        $explicitCallModel = $callModel !== null && trim($callModel) !== '';
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

        if ($providerType !== 'anthropic') {
            if ($explicitCallModel) {
                throw new \InvalidArgumentException(
                    "Agent model alias '{$selected}' is only supported by the Anthropic provider. "
                    .'Use inherit with non-Anthropic providers.',
                );
            }

            // Definition defaults describe relative capability. Non-Anthropic
            // providers inherit the parent's already-valid model.
            return null;
        }

        return $aliases[$selected];
    }
}
