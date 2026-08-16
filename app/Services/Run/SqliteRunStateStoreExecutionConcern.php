<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

trait SqliteRunStateStoreExecutionConcern
{
    public function claimToolExecution(
        ToolExecutionRequest $request,
        string $ownerId,
        int $leaseDurationMs,
        int $nowMs,
        RunEvent $claimedEvent,
        array $stateDelta,
    ): ToolExecutionClaim {
        $this->assertLeaseInput($ownerId, $leaseDurationMs, $nowMs);

        return $this->transaction(function () use (
            $request,
            $ownerId,
            $leaseDurationMs,
            $nowMs,
            $claimedEvent,
            $stateDelta,
        ): ToolExecutionClaim {
            $record = $this->toolExecutionInTransaction($request->idempotencyKey);
            if ($record === null) {
                $record = new ToolExecutionRecord(
                    $request,
                    ToolExecutionState::Claimed,
                    $ownerId,
                    $nowMs + $leaseDurationMs,
                    1,
                    null,
                    $nowMs,
                );
                $this->insertToolExecutionInTransaction($record);
                $event = $this->appendEventInTransaction($claimedEvent);
                $this->insertCheckpointInTransaction($this->checkpointForEvent(
                    $event,
                    RunStatus::Running,
                    $stateDelta,
                ));

                return new ToolExecutionClaim($record, true, $event);
            }

            $this->assertSameToolRequest($record->request, $request);
            if ($record->state->isTerminal()) {
                return new ToolExecutionClaim($record, false);
            }
            if ($record->state === ToolExecutionState::Interrupted && ! $request->resumeInterrupted) {
                return new ToolExecutionClaim($record, false);
            }
            if ($record->leaseIsActive($nowMs)) {
                return new ToolExecutionClaim(
                    $record,
                    $record->belongsTo($ownerId) && $record->state === ToolExecutionState::Claimed,
                );
            }

            if ($record->state === ToolExecutionState::Started && ! $record->request->readOnly) {
                $unknown = $this->transitionExpiredToUnknownInTransaction($record, $nowMs);

                return new ToolExecutionClaim($unknown, false);
            }

            $reclaimed = new ToolExecutionRecord(
                $request,
                ToolExecutionState::Claimed,
                $ownerId,
                $nowMs + $leaseDurationMs,
                $record->fencingToken + 1,
                null,
                $nowMs,
            );
            $this->updateToolExecutionInTransaction($reclaimed);
            $event = $this->appendEventInTransaction($claimedEvent);
            $this->insertCheckpointInTransaction($this->checkpointForEvent(
                $event,
                RunStatus::Running,
                $stateDelta,
            ));

            return new ToolExecutionClaim($reclaimed, true, $event);
        });
    }

    public function markToolExecutionStarted(
        string $idempotencyKey,
        string $ownerId,
        int $fencingToken,
        int $nowMs,
    ): ToolExecutionRecord {
        return $this->transaction(function () use ($idempotencyKey, $ownerId, $fencingToken, $nowMs): ToolExecutionRecord {
            $record = $this->requireOwnedToolExecution(
                $idempotencyKey,
                $ownerId,
                $fencingToken,
                [ToolExecutionState::Claimed],
            );
            $started = new ToolExecutionRecord(
                $record->request,
                ToolExecutionState::Started,
                $ownerId,
                $record->leaseExpiresAtMs,
                $fencingToken,
                null,
                $nowMs,
            );
            $this->updateToolExecutionInTransaction($started);

            return $started;
        });
    }

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
    ): RunEvent {
        if (! in_array($state, [
            ToolExecutionState::Completed,
            ToolExecutionState::Failed,
            ToolExecutionState::Interrupted,
            ToolExecutionState::Cancelled,
            ToolExecutionState::Unknown,
        ], true)) {
            throw new \InvalidArgumentException('Tool execution commit requires a terminal or interrupted state.');
        }

        return $this->transaction(function () use (
            $idempotencyKey,
            $ownerId,
            $fencingToken,
            $state,
            $result,
            $nowMs,
            $terminalEvent,
            $checkpointStatus,
            $stateDelta,
        ): RunEvent {
            $record = $this->requireOwnedToolExecution(
                $idempotencyKey,
                $ownerId,
                $fencingToken,
                [ToolExecutionState::Claimed, ToolExecutionState::Started],
            );

            $committed = new ToolExecutionRecord(
                $record->request,
                $state,
                null,
                0,
                $fencingToken,
                $result,
                $nowMs,
            );
            $this->updateToolExecutionInTransaction($committed);
            $event = $this->appendEventInTransaction($terminalEvent);
            $this->insertCheckpointInTransaction($this->checkpointForEvent(
                $event,
                $checkpointStatus,
                $stateDelta,
            ));

            return $event;
        });
    }

    public function getToolExecution(string $idempotencyKey): ?ToolExecutionRecord
    {
        return $this->toolExecutionInTransaction($idempotencyKey);
    }

    public function recoverExpiredToolExecutions(string $runId, int $nowMs): array
    {
        return $this->transaction(function () use ($runId, $nowMs): array {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT * FROM tool_executions
                WHERE run_id = ? AND state IN ('claimed', 'started', 'interrupted')
                  AND lease_expires_at_ms <= ?
                ORDER BY updated_at_ms ASC
            SQL);
            $statement->execute([$runId, $nowMs]);
            $records = [];
            foreach ($statement->fetchAll() as $row) {
                $record = $this->toolExecutionFromRow($row);
                if ($record->state === ToolExecutionState::Started && ! $record->request->readOnly) {
                    $record = $this->transitionExpiredToUnknownInTransaction($record, $nowMs);
                }
                $records[] = $record;
            }

            return $records;
        });
    }

    public function claimRun(string $runId, string $ownerId, int $leaseDurationMs, int $nowMs): RunLease
    {
        $this->assertLeaseInput($ownerId, $leaseDurationMs, $nowMs);

        return $this->transaction(function () use ($runId, $ownerId, $leaseDurationMs, $nowMs): RunLease {
            $statement = $this->pdo->prepare('SELECT * FROM run_leases WHERE run_id = ? LIMIT 1');
            $statement->execute([$runId]);
            $row = $statement->fetch();
            if (! is_array($row)) {
                $token = 1;
                $expires = $nowMs + $leaseDurationMs;
                $insert = $this->pdo->prepare(
                    'INSERT INTO run_leases (run_id, owner_id, lease_expires_at_ms, fencing_token, updated_at_ms) VALUES (?, ?, ?, ?, ?)',
                );
                $insert->execute([$runId, $ownerId, $expires, $token, $nowMs]);

                return new RunLease($runId, $ownerId, $expires, $token, true);
            }

            $activeOwner = is_string($row['owner_id'] ?? null) ? $row['owner_id'] : '';
            $expires = (int) $row['lease_expires_at_ms'];
            $token = (int) $row['fencing_token'];
            if ($activeOwner !== $ownerId && $expires > $nowMs) {
                return new RunLease($runId, $activeOwner, $expires, $token, false);
            }
            if ($activeOwner !== $ownerId) {
                $token++;
            }
            $expires = $nowMs + $leaseDurationMs;
            $update = $this->pdo->prepare(
                'UPDATE run_leases SET owner_id = ?, lease_expires_at_ms = ?, fencing_token = ?, updated_at_ms = ? WHERE run_id = ?',
            );
            $update->execute([$ownerId, $expires, $token, $nowMs, $runId]);

            return new RunLease($runId, $ownerId, $expires, $token, true);
        });
    }

    public function renewRun(
        string $runId,
        string $ownerId,
        int $fencingToken,
        int $leaseDurationMs,
        int $nowMs,
    ): RunLease {
        $this->assertLeaseInput($ownerId, $leaseDurationMs, $nowMs);

        return $this->transaction(function () use ($runId, $ownerId, $fencingToken, $leaseDurationMs, $nowMs): RunLease {
            $expires = $nowMs + $leaseDurationMs;
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE run_leases SET lease_expires_at_ms = ?, updated_at_ms = ?
                WHERE run_id = ? AND owner_id = ? AND fencing_token = ?
            SQL);
            $statement->execute([$expires, $nowMs, $runId, $ownerId, $fencingToken]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException("Run lease {$runId} is stale.");
            }

            return new RunLease($runId, $ownerId, $expires, $fencingToken, true);
        });
    }

    public function releaseRun(string $runId, string $ownerId, int $fencingToken): void
    {
        $this->transaction(function () use ($runId, $ownerId, $fencingToken): void {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE run_leases SET owner_id = NULL, lease_expires_at_ms = 0
                WHERE run_id = ? AND owner_id = ? AND fencing_token = ?
            SQL);
            $statement->execute([$runId, $ownerId, $fencingToken]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException("Run lease {$runId} is stale.");
            }
        });
    }

    private function transitionExpiredToUnknownInTransaction(
        ToolExecutionRecord $record,
        int $nowMs,
    ): ToolExecutionRecord {
        $unknown = new ToolExecutionRecord(
            $record->request,
            ToolExecutionState::Unknown,
            null,
            0,
            $record->fencingToken,
            [
                'type' => 'tool_result',
                'tool_use_id' => $record->request->toolUseId,
                'content' => 'Tool side effect status is unknown; automatic retry is disabled.',
                'is_error' => true,
            ],
            $nowMs,
        );
        $this->updateToolExecutionInTransaction($unknown);
        $event = $this->appendEventInTransaction(RunEvent::draft(
            runId: $record->request->runId,
            invocationId: $record->request->invocationId,
            phase: RunEventPhase::Tool,
            type: 'tool.unknown',
            dedupeKey: $record->request->idempotencyKey.':unknown',
            payload: [
                'idempotency_key' => $record->request->idempotencyKey,
                'tool_use_id' => $record->request->toolUseId,
                'tool_name' => $record->request->toolName,
                'reason' => 'lease_expired_after_start',
            ],
        ));
        $this->insertCheckpointInTransaction($this->checkpointForEvent(
            $event,
            RunStatus::Unknown,
            ['tool_execution' => $unknown->request->idempotencyKey],
        ));

        return $unknown;
    }

    private function checkpointForEvent(RunEvent $event, RunStatus $status, array $delta): RunCheckpoint
    {
        return new RunCheckpoint(
            $event->runId,
            $event->invocationId,
            $event->sequence,
            $status,
            $delta,
            gmdate('c'),
        );
    }

    private function toolExecutionInTransaction(string $idempotencyKey): ?ToolExecutionRecord
    {
        $statement = $this->pdo->prepare('SELECT * FROM tool_executions WHERE idempotency_key = ? LIMIT 1');
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch();

        return is_array($row) ? $this->toolExecutionFromRow($row) : null;
    }

    private function requireOwnedToolExecution(
        string $idempotencyKey,
        string $ownerId,
        int $fencingToken,
        array $states,
    ): ToolExecutionRecord {
        $record = $this->toolExecutionInTransaction($idempotencyKey);
        if ($record === null || ! $record->belongsTo($ownerId)
            || $record->fencingToken !== $fencingToken
            || ! in_array($record->state, $states, true)) {
            throw new \RuntimeException("Stale fencing token for tool execution {$idempotencyKey}.");
        }

        return $record;
    }

    private function insertToolExecutionInTransaction(ToolExecutionRecord $record): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO tool_executions (
                idempotency_key, run_id, invocation_id, tool_use_id, tool_name,
                input_hash, read_only, state, owner_id, lease_expires_at_ms,
                fencing_token, result_json, updated_at_ms
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $statement->execute($this->toolExecutionValues($record));
    }

    private function updateToolExecutionInTransaction(ToolExecutionRecord $record): void
    {
        $values = $this->toolExecutionValues($record);
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE tool_executions SET
                run_id = ?, invocation_id = ?, tool_use_id = ?, tool_name = ?,
                input_hash = ?, read_only = ?, state = ?, owner_id = ?,
                lease_expires_at_ms = ?, fencing_token = ?, result_json = ?, updated_at_ms = ?
            WHERE idempotency_key = ?
        SQL);
        $statement->execute(array_merge(array_slice($values, 1), [$values[0]]));
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Tool execution update lost its claim.');
        }
    }

    /** @return list<mixed> */
    private function toolExecutionValues(ToolExecutionRecord $record): array
    {
        $request = $record->request;

        return [
            $request->idempotencyKey,
            $request->runId,
            $request->invocationId,
            $request->toolUseId,
            $request->toolName,
            $request->inputHash,
            (int) $request->readOnly,
            $record->state->value,
            $record->ownerId,
            $record->leaseExpiresAtMs,
            $record->fencingToken,
            $record->result === null ? null : $this->encodeJson($record->result),
            $record->updatedAtMs,
        ];
    }

    /** @param array<string, mixed> $row */
    private function toolExecutionFromRow(array $row): ToolExecutionRecord
    {
        $state = ToolExecutionState::tryFrom((string) $row['state']);
        if ($state === null) {
            throw new \RuntimeException('Run store contains an invalid tool execution state.');
        }

        return new ToolExecutionRecord(
            new ToolExecutionRequest(
                (string) $row['idempotency_key'],
                (string) $row['run_id'],
                (string) $row['invocation_id'],
                (string) $row['tool_use_id'],
                (string) $row['tool_name'],
                (string) $row['input_hash'],
                (bool) $row['read_only'],
                $state === ToolExecutionState::Interrupted,
            ),
            $state,
            is_string($row['owner_id'] ?? null) ? $row['owner_id'] : null,
            (int) $row['lease_expires_at_ms'],
            (int) $row['fencing_token'],
            is_string($row['result_json'] ?? null) ? $this->decodeJson($row['result_json']) : null,
            (int) $row['updated_at_ms'],
        );
    }

    private function assertSameToolRequest(ToolExecutionRequest $left, ToolExecutionRequest $right): void
    {
        if ($left->runId !== $right->runId
            || $left->invocationId !== $right->invocationId
            || $left->toolUseId !== $right->toolUseId
            || $left->toolName !== $right->toolName
            || $left->inputHash !== $right->inputHash
            || $left->readOnly !== $right->readOnly) {
            throw new \RuntimeException("Tool idempotency conflict for {$right->idempotencyKey}.");
        }
    }

    private function assertLeaseInput(string $ownerId, int $leaseDurationMs, int $nowMs): void
    {
        if ($ownerId === '' || $leaseDurationMs < 1 || $nowMs < 0) {
            throw new \InvalidArgumentException('Invalid lease parameters.');
        }
    }
}
