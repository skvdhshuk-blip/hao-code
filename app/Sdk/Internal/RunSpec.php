<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Services\Agent\RunLimits;

/**
 * Canonical internal description of one SDK run.
 *
 * Agent and RunOptions remain the public sources of truth. HaoCodeConfig is
 * adapted here so legacy entry points keep their public shape without making
 * the runtime reconcile three independent configuration objects.
 *
 * @internal
 */
final class RunSpec
{
    private function __construct(
        public readonly Agent $agent,
        public readonly RunOptions $options,
        public readonly RunLimits $limits,
    ) {}

    public static function fromAgent(Agent $agent, ?RunOptions $options = null): self
    {
        $options ??= new RunOptions;
        return new self(
            $agent,
            $options,
            new RunLimits(
                maxTurns: $agent->maxTurns,
                maxTokens: $agent->maxTokens,
                maxBudgetUsd: $options->maxBudgetUsd,
                thinkingEnabled: $agent->thinkingEnabled,
                thinkingBudget: $agent->thinkingBudget,
            ),
        );
    }

    public static function fromConfig(\HaoCode\Sdk\HaoCodeConfig $config): self
    {
        return self::fromAgent(
            Agent::fromConfig($config),
            RunOptions::fromConfig($config),
        );
    }
}
