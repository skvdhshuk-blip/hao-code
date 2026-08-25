<?php

namespace HaoCode\Sdk;

use HaoCode\Contracts\RunTerminationReason;

/**
 * SDK message — typed envelope for streaming events.
 *
 * Modeled after Claude Agent SDK's SDKMessage types:
 * - text: streaming text delta
 * - tool_start: tool execution began
 * - tool_result: tool execution completed
 * - turn: a new agent turn started
 * - result: final result with usage/cost
 * - error: an error occurred
 * - interrupt: generation paused for a durable human decision
 * - auto_decision: an action was decided automatically by the smart HITL policy
 *
 * @api
 */
class Message
{
    /** @internal */
    private function __construct(
        /** @api */
        public readonly string $type,
        /** @api */
        public readonly ?string $text = null,
        /** @api */
        public readonly ?string $toolName = null,
        /** @api */
        public readonly ?array $toolInput = null,
        /** @api */
        public readonly ?string $toolOutput = null,
        /** @api */
        public readonly ?bool $toolIsError = null,
        /** @api */
        public readonly ?int $turnNumber = null,
        /** @api */
        public readonly ?string $sessionId = null,
        /** @api */
        public readonly ?array $usage = null,
        /** @api */
        public readonly ?float $cost = null,
        /** @api */
        public readonly ?string $error = null,
        /** @api */
        public readonly ?HumanInterrupt $interrupt = null,
        /** @api */
        public readonly ?string $interruptId = null,
        /** @api */
        public readonly ?string $actionId = null,
        /** @api */
        public readonly ?string $decision = null,
        /** @api */
        public readonly ?string $source = null,
        /** @api */
        public readonly ?string $riskLevel = null,
        /** @api */
        public readonly ?string $reason = null,
        /** @api */
        public readonly ?RunTerminationReason $terminationReason = null,
    ) {}

    /** @api */
    public static function text(string $delta): self
    {
        return new self(type: 'text', text: $delta);
    }

    /** @api */
    public static function toolStart(string $toolName, array $input): self
    {
        return new self(type: 'tool_start', toolName: $toolName, toolInput: $input);
    }

    /** @api */
    public static function toolResult(string $toolName, string $output, bool $isError = false): self
    {
        return new self(type: 'tool_result', toolName: $toolName, toolOutput: $output, toolIsError: $isError);
    }

    /** @api */
    public static function turn(int $number): self
    {
        return new self(type: 'turn', turnNumber: $number);
    }

    /** @api */
    public static function result(
        string $text,
        array $usage,
        float $cost,
        ?string $sessionId = null,
        RunTerminationReason $terminationReason = RunTerminationReason::Normal,
    ): self
    {
        return new self(
            type: 'result',
            text: $text,
            usage: $usage,
            cost: $cost,
            sessionId: $sessionId,
            terminationReason: $terminationReason,
        );
    }

    /** @api */
    public static function error(string $message): self
    {
        return new self(type: 'error', error: $message);
    }

    /** @api */
    public static function interrupt(HumanInterrupt $interrupt): self
    {
        return new self(type: 'interrupt', sessionId: $interrupt->sessionId, interrupt: $interrupt);
    }

    /**
     * Automatic decision emitted by the smart HITL policy for one action.
     *
     * - $decision: 'approve', 'reject', or 'escalate' (unknown values normalize
     *   to 'escalate', the safest fallback).
     * - $source: 'rule', 'review', 'sandbox', or 'batch' (unknown values
     *   normalize to 'rule').
     * - $riskLevel: 'low', 'medium', 'high', or 'critical' (unknown values
     *   normalize to 'medium').
     * - $reason: human-readable rationale; escalations carry a 'rule:',
     *   'review:', 'sandbox:', or 'batch:' prefix family matching the source.
     *
     * @api
     */
    public static function autoDecision(
        string $sessionId,
        string $interruptId,
        string $actionId,
        string $toolName,
        array $toolInput,
        string $decision,
        string $source,
        string $riskLevel,
        string $reason,
    ): self {
        return new self(
            type: 'auto_decision',
            toolName: $toolName,
            toolInput: $toolInput,
            sessionId: $sessionId,
            interruptId: $interruptId,
            actionId: $actionId,
            decision: in_array($decision, ['approve', 'reject', 'escalate'], true) ? $decision : 'escalate',
            source: in_array($source, ['rule', 'review', 'sandbox', 'batch'], true) ? $source : 'rule',
            riskLevel: in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true) ? $riskLevel : 'medium',
            reason: $reason,
        );
    }

    /** @api */
    public function isResult(): bool
    {
        return $this->type === 'result';
    }

    /** @api */
    public function isError(): bool
    {
        return $this->type === 'error';
    }

    /** @api */
    public function isInterrupt(): bool
    {
        return $this->type === 'interrupt';
    }

    /** @api */
    public function isAutoDecision(): bool
    {
        return $this->type === 'auto_decision';
    }
}
