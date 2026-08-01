<?php

namespace HaoCode\Services\Agent;

use HaoCode\Tools\Skill\SkillCapability;
use HaoCode\Tools\Skill\SkillModelResolver;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Owns the active/base Skill capability and model scope for one run.
 *
 * @internal
 */
final class SkillScopeState
{
    /** @var list<string>|null */
    private ?array $activeAllowedTools = null;

    /** @var list<string>|null */
    private ?array $baseAllowedTools = null;

    private ?string $modelOverride = null;

    private string $context = 'inline';

    public function reset(): void
    {
        $this->activeAllowedTools = $this->baseAllowedTools;
        $this->modelOverride = null;
        $this->context = 'inline';
    }

    /** @param list<string>|null $allowedTools */
    public function setBase(?array $allowedTools): void
    {
        $this->baseAllowedTools = $allowedTools === null
            ? null
            : SkillCapability::normalizeSpecs($allowedTools);
        $this->activeAllowedTools = $this->baseAllowedTools;
    }

    /** @param list<string>|null $allowedTools */
    public function restore(?array $allowedTools, ?string $modelOverride, ?string $context): void
    {
        $normalized = $allowedTools === null
            ? null
            : SkillCapability::normalizeSpecs($allowedTools);
        // Resume snapshots restore the active scope on top of any base envelope.
        $this->activeAllowedTools = $normalized === null || $this->baseAllowedTools === null
            ? $normalized
            : SkillCapability::intersect($this->baseAllowedTools, $normalized);
        $this->modelOverride = is_string($modelOverride) && trim($modelOverride) !== ''
            ? trim($modelOverride)
            : null;
        $this->context = in_array($context, ['inline', 'fork'], true)
            ? $context
            : 'inline';
    }

    /** @return list<string>|null */
    public function getAllowed(): ?array
    {
        return $this->activeAllowedTools;
    }

    /** @return list<string>|null */
    public function getAdvertised(?array $resumeAllowedTools): ?array
    {
        $skillTools = $this->activeAllowedTools === null
            ? null
            : SkillCapability::toolNames($this->activeAllowedTools);

        if ($resumeAllowedTools === null) {
            return $skillTools;
        }
        if ($skillTools === null) {
            return $resumeAllowedTools;
        }

        return array_values(array_intersect($resumeAllowedTools, $skillTools));
    }

    public function getModelOverride(): ?string
    {
        return $this->modelOverride;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    /** @param array<string, mixed> $input */
    public function allows(string $toolName, array $input, ?array $resumeAllowedTools): bool
    {
        if ($resumeAllowedTools !== null && ! in_array($toolName, $resumeAllowedTools, true)) {
            return false;
        }
        if ($this->activeAllowedTools === null || $toolName === 'Skill') {
            return true;
        }

        return SkillCapability::allows($this->activeAllowedTools, $toolName, $input);
    }

    public function activate(string $toolName, ToolResult $result, ?ToolUseContext $toolContext = null): void
    {
        if ($toolName !== 'Skill' || $result->isError || $result->metadata === null) {
            return;
        }

        $allowedTools = $result->metadata['allowed_tools'] ?? [];
        $context = $result->metadata['context'] ?? 'inline';
        if ($context !== 'fork' && is_array($allowedTools) && $allowedTools !== []) {
            try {
                $normalized = SkillCapability::normalizeSpecs($allowedTools);
            } catch (\InvalidArgumentException) {
                // Invalid capability specs must not widen permissions.
                $this->activeAllowedTools = $this->activeAllowedTools ?? [];

                return;
            }

            $combined = $this->activeAllowedTools === null
                ? $normalized
                : SkillCapability::intersect($this->activeAllowedTools, $normalized);
            // Never escape a forked skill's base envelope.
            $this->activeAllowedTools = $this->baseAllowedTools === null
                ? $combined
                : SkillCapability::intersect($this->baseAllowedTools, $combined);
        }

        $modelOverride = $result->metadata['model_override'] ?? null;
        if ($context !== 'fork' && is_string($modelOverride) && trim($modelOverride) !== '') {
            $providerType = $toolContext?->runContext?->settings->getProviderType() ?? 'anthropic';
            try {
                $this->modelOverride = SkillModelResolver::resolve(trim($modelOverride), $providerType);
            } catch (\InvalidArgumentException) {
                // Keep prior override rather than applying an invalid alias.
            }
        }

        if (is_string($context) && in_array($context, ['inline', 'fork'], true)) {
            $this->context = $context;
        }
    }
}
