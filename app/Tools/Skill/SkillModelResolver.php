<?php

namespace HaoCode\Tools\Skill;

use HaoCode\Services\Settings\ModelCatalog;
use HaoCode\Tools\Agent\AgentModelResolver;

/**
 * Resolve a skill model override against the active provider.
 *
 * Anthropic tier aliases (sonnet/opus/haiku) expand via {@see AgentModelResolver}.
 * Full model identifiers pass through. Non-Anthropic providers reject explicit
 * Anthropic aliases (same contract as AgentTool) and pass full IDs through.
 *
 * @internal
 */
final class SkillModelResolver
{
    public static function resolve(?string $skillModel, string $providerType = 'anthropic'): ?string
    {
        if ($skillModel === null || trim($skillModel) === '') {
            return null;
        }

        $selected = trim($skillModel);
        $aliases = ModelCatalog::agentAliases();

        if (array_key_exists($selected, $aliases)) {
            // Reuse Agent resolver so alias + provider rules stay single-sourced.
            return AgentModelResolver::resolve($selected, null, $providerType);
        }

        if (strtolower($selected) === 'inherit') {
            return null;
        }

        // Full model id — provider-specific validation is left to the wire layer.
        return $selected;
    }
}
