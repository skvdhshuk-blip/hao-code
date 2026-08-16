<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface ToolExecutionStoreInterface
{
    /**
     * Claim and the pre-effect checkpoint are one durable transaction.
     *
     * @param array<string, mixed> $stateDelta
     */
    public function claimToolExecution(
        ToolExecutionRequest $request,
        string $ownerId,
        int $leaseDurationMs,
        int $nowMs,
        RunEvent $claimedEvent,
        array $stateDelta,
    ): ToolExecutionClaim;

    public function markToolExecutionStarted(
        string $idempotencyKey,
        string $ownerId,
        int $fencingToken,
        int $nowMs,
    ): ToolExecutionRecord;

    /**
     * Terminal result, event and post-result checkpoint are one transaction.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $stateDelta
     */
    public function commitToolExecution(
        string $idempotencyKey,
        string $ownerId,
        int $fencingToken,
        ToolExecutionState $state,
        array $result,
        int $nowMs,
        RunEvent $terminalEvent,
        RunStatus $checkpointStatus,
        array $stateDelta,
    ): RunEvent;

    public function getToolExecution(string $idempotencyKey): ?ToolExecutionRecord;

    /** @return list<ToolExecutionRecord> */
    public function recoverExpiredToolExecutions(string $runId, int $nowMs): array;
}
