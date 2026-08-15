<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Provider-independent execution input shared by root and child agents.
 *
 * Resource assembly lives in AgentLoopSpec. This per-call object owns the
 * input/callback contract and captures one uniform result before SDK/Tool
 * adapters translate it to their public result types.
 *
 * @internal
 */
final class AgentInvocation
{
    public readonly ?\Closure $onTextDelta;
    public readonly ?\Closure $onToolStart;
    public readonly ?\Closure $onToolComplete;
    public readonly ?\Closure $onTurnStart;
    public readonly ?\Closure $onThinkingDelta;

    /** @param string|array<int, mixed>|null $input */
    public function __construct(
        public readonly string|array|null $input,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ) {
        $this->onTextDelta = self::closure($onTextDelta);
        $this->onToolStart = self::closure($onToolStart);
        $this->onToolComplete = self::closure($onToolComplete);
        $this->onTurnStart = self::closure($onTurnStart);
        $this->onThinkingDelta = self::closure($onThinkingDelta);
    }

    public function invoke(AgentLoop $loop): AgentInvocationResult
    {
        $text = $loop->run(
            userInput: $this->input,
            onTextDelta: $this->onTextDelta,
            onToolStart: $this->onToolStart,
            onToolComplete: $this->onToolComplete,
            onTurnStart: $this->onTurnStart,
            onThinkingDelta: $this->onThinkingDelta,
        );

        return AgentInvocationResult::capture($text, $loop);
    }

    private static function closure(?callable $callback): ?\Closure
    {
        return $callback === null ? null : \Closure::fromCallable($callback);
    }
}
