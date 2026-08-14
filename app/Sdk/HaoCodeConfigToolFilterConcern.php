<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Permissions\PermissionMode;

trait HaoCodeConfigToolFilterConcern
{

    /**
     * Build a tool filter callable from allowedTools/disallowedTools.
     *
     * @api
     */
    public function toolFilter(): ?callable
    {
        if ($this->allowedTools === ['*'] && $this->disallowedTools === [] && $this->sandbox === null) {
            return null;
        }

        return function (string $toolName): bool {
            if ($this->sandbox !== null) {
                if (\HaoCode\Sdk\Sandbox\SandboxToolPolicy::isHostOnly($toolName)) {
                    return false;
                }
                if ($toolName === 'Bash' && ! $this->sandbox->enablesBash()) {
                    return false;
                }
            }

            if (in_array($toolName, $this->disallowedTools, true)) {
                return false;
            }

            if (in_array('*', $this->allowedTools, true)) {
                return true;
            }

            return in_array($toolName, $this->allowedTools, true);
        };
    }

    /**
     * Filter for additional tools (SDK custom tools, sandbox replacements,
     * MCP tools).
     *
     * This remains a separate internal compatibility method, but it must use
     * the same final capability contract as the built-in tool registry:
     * allowedTools, disallowedTools, and sandbox restrictions all apply.
     *
     * @internal
     */
    public function additionalToolFilter(): ?callable
    {
        return $this->toolFilter();
    }

    /**
     * Working directory exposed to tools.
     *
     * @internal
     */
    public function effectiveWorkingDirectory(): ?string
    {
        return $this->sandbox?->remoteCwd ?? $this->cwd;
    }

    /** @internal */
    public function withResponseSchema(array $schema): self
    {
        $values = get_object_vars($this);
        $values['responseSchema'] = $schema;

        return new self(...$values);
    }
    /** @internal */
    public function withOverrides(
        ?callable $onText = null,
        ?callable $onThinking = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        array $images = [],
        ?bool $ephemeral = null,
        ?array $responseSchema = null,
        ?AbortController $abortController = null,
        ?string $cwd = null,
        ?float $maxBudgetUsd = null,
    ): self {
        $values = get_object_vars($this);
        $values['onText'] = $onText;
        $values['onThinking'] = $onThinking;
        $values['onToolStart'] = $onToolStart;
        $values['onToolComplete'] = $onToolComplete;
        $values['onTurnStart'] = $onTurnStart;
        $values['images'] = $images;
        if ($ephemeral !== null) {
            $values['ephemeral'] = $ephemeral;
        }
        $values['responseSchema'] = $responseSchema;
        $values['abortController'] = $abortController;
        $values['cwd'] = $cwd;
        $values['maxBudgetUsd'] = $maxBudgetUsd;

        return new self(...$values);
    }
}
