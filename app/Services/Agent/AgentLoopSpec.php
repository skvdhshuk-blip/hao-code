<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Tools\ToolRegistry;

/** Normalized request to assemble an AgentLoop. @internal */
final class AgentLoopSpec
{
    public readonly ?\Closure $toolFilter;

    public readonly ?\Closure $additionalToolFilter;

    public function __construct(
        ?callable $toolFilter = null,
        public readonly ?string $workingDirectory = null,
        /** @var array<int, \HaoCode\Contracts\ToolInterface> */
        public readonly array $additionalTools = [],
        public readonly ?LlmProvider $provider = null,
        public readonly ?AgentRunContext $runContext = null,
        public readonly bool $ephemeral = false,
        public readonly bool $afterFork = false,
        public readonly bool $readOnly = false,
        ?callable $additionalToolFilter = null,
        public readonly ?ToolRegistry $parentToolRegistry = null,
        public readonly ?AgentRunContext $parentRunContext = null,
        public readonly ?string $model = null,
        public readonly ?string $appendSystemPrompt = null,
        public readonly bool $omitProjectInstructions = false,
        public readonly ?string $agentType = null,
        /** @var array<int, \HaoCode\Contracts\ToolInterface> */
        public readonly array $replacementTools = [],
        public readonly ?RunLimits $limits = null,
    ) {
        $this->toolFilter = $toolFilter === null ? null : \Closure::fromCallable($toolFilter);
        $this->additionalToolFilter = $additionalToolFilter === null
            ? null
            : \Closure::fromCallable($additionalToolFilter);

        if ($this->parentToolRegistry === null) {
            if ($this->parentRunContext !== null) {
                throw new \LogicException('A parent run context requires a parent tool registry.');
            }

            return;
        }
        if ($this->provider === null) {
            throw new \LogicException('A child invocation must inherit its parent provider.');
        }
        if ($this->parentRunContext !== null
            && ($this->runContext === null || ! $this->runContext->isChildOf($this->parentRunContext))) {
            throw new \LogicException(
                'A child invocation must derive cancellation, resources, and policy from its parent run context.',
            );
        }
    }
}
