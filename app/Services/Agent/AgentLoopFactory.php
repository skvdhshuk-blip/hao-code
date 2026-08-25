<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Tools\ToolRegistry;

/** Orchestrates the three explicit loop-assembly authorities. */
class AgentLoopFactory
{
    public function __construct(
        private readonly AgentToolSetBuilder $toolSetBuilder,
        private readonly AgentPersistenceFactory $persistenceFactory,
        private readonly AgentLoopBuilder $loopBuilder,
    ) {}

    public function createIsolated(
        ?callable $toolFilter = null,
        ?string $workingDirectory = null,
        array $additionalTools = [],
        ?LlmProvider $streamingClient = null,
        ?AgentRunContext $runContext = null,
        bool $ephemeral = false,
        bool $afterFork = false,
        bool $readOnly = false,
        ?callable $additionalToolFilter = null,
        ?ToolRegistry $parentToolRegistry = null,
        ?string $model = null,
        ?string $appendSystemPrompt = null,
        bool $omitProjectInstructions = false,
        ?string $agentType = null,
        array $replacementTools = [],
        ?AgentRunContext $parentRunContext = null,
        ?RunLimits $limits = null,
    ): AgentLoop {
        return $this->createInvocation(new AgentLoopSpec(
            toolFilter: $toolFilter,
            workingDirectory: $workingDirectory,
            additionalTools: $additionalTools,
            provider: $streamingClient,
            runContext: $runContext,
            ephemeral: $ephemeral,
            afterFork: $afterFork,
            readOnly: $readOnly,
            additionalToolFilter: $additionalToolFilter,
            parentToolRegistry: $parentToolRegistry,
            parentRunContext: $parentRunContext,
            model: $model,
            appendSystemPrompt: $appendSystemPrompt,
            omitProjectInstructions: $omitProjectInstructions,
            agentType: $agentType,
            replacementTools: $replacementTools,
            limits: $limits,
        ));
    }

    /** Canonical assembly entry for root and nested runs. @internal */
    public function createInvocation(AgentLoopSpec $invocation): AgentLoop
    {
        $runContext = $this->loopBuilder->prepareRunContext($invocation);
        $toolSet = $this->toolSetBuilder->build(
            $invocation,
            $runContext,
            fn (AgentLoopSpec $child): AgentLoop => $this->createInvocation($child),
        );
        $persistence = $this->persistenceFactory->create($invocation->ephemeral);

        return $this->loopBuilder->build($invocation, $runContext, $toolSet, $persistence);
    }
}
