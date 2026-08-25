<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Cost\UsageAccumulator;
use HaoCode\Tools\ToolRegistry;

/** @internal */
final class RunBootstrap
{
    /** @param array<string, mixed>|null $resumeSnapshot */
    public function __construct(
        public readonly RunSpec $spec,
        public readonly AgentLoopFactory $factory,
        public readonly RuntimeDefaults $runtimeDefaults,
        public readonly ?array $resumeSnapshot = null,
        public readonly ?LlmProvider $provider = null,
        public readonly ?BudgetLedger $budgetLedger = null,
        public readonly ?ToolRegistry $parentToolRegistry = null,
        public readonly ?UsageAccumulator $usageAccumulator = null,
        public readonly ?AgentRunContext $parentRunContext = null,
    ) {}
}
