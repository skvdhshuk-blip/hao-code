<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** @internal */
final class AgentInvocationResult
{
    /**
     * @param array<string, int|bool> $usage
     * @param array<string, int> $localUsage
     */
    public function __construct(
        public readonly string $text,
        public readonly array $usage,
        public readonly array $localUsage,
        public readonly float $cost,
        public readonly float $localCost,
        public readonly ?string $sessionId,
        public readonly int $turnsUsed,
    ) {}

    public static function capture(string $text, AgentLoop $loop): self
    {
        return new self(
            $text,
            [
                'input_tokens' => $loop->getTotalInputTokens(),
                'output_tokens' => $loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $loop->getCacheCreationTokens(),
                'cache_read_tokens' => $loop->getCacheReadTokens(),
                'last_turn_input_tokens' => $loop->getLastTurnInputTokens(),
                'cost_available' => $loop->isCostEstimateAvailable(),
            ],
            [
                'inputTokens' => $loop->getLocalInputTokens(),
                'outputTokens' => $loop->getLocalOutputTokens(),
            ],
            $loop->getEstimatedCost(),
            $loop->getLocalEstimatedCost(),
            $loop->getSessionManager()->getSessionId(),
            $loop->getLastRunTurns(),
        );
    }
}
