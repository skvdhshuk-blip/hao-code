<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

trait SqliteRunStateStoreEventConcern
{
    public function append(RunEvent $event): RunEvent
    {
        return $this->transaction(fn (): RunEvent => $this->appendEventInTransaction($event));
    }

    public function read(string $runId, int $afterSequence = 0): iterable
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM run_events WHERE run_id = ? AND sequence > ? ORDER BY sequence ASC',
        );
        $statement->execute([$runId, $afterSequence]);
        $events = [];
        foreach ($statement->fetchAll() as $row) {
            $events[] = $this->eventFromRow($row);
        }

        return $events;
    }

    public function commitCheckpoint(RunCheckpoint $checkpoint): void
    {
        $this->transaction(function () use ($checkpoint): void {
            $this->insertCheckpointInTransaction($checkpoint);
        });
    }

    public function latestCheckpoint(string $runId): ?RunCheckpoint
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM run_checkpoints WHERE run_id = ? ORDER BY event_sequence DESC, id DESC LIMIT 1',
        );
        $statement->execute([$runId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->checkpointFromRow($row) : null;
    }

    private function appendEventInTransaction(RunEvent $event): RunEvent
    {
        $existingStatement = $this->pdo->prepare(
            'SELECT * FROM run_events WHERE run_id = ? AND dedupe_key = ? LIMIT 1',
        );
        $existingStatement->execute([$event->runId, $event->dedupeKey]);
        $existing = $existingStatement->fetch();
        if (is_array($existing)) {
            $stored = $this->eventFromRow($existing);
            $this->assertSameEventFact($stored, $event);

            return $stored;
        }

        $sequenceStatement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM run_events WHERE run_id = ?',
        );
        $sequenceStatement->execute([$event->runId]);
        $stored = $event->withSequence((int) $sequenceStatement->fetchColumn());
        $insert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO run_events (
                run_id, sequence, event_id, invocation_id, causation_id, phase,
                event_type, dedupe_key, occurred_at, schema_version, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $insert->execute([
            $stored->runId,
            $stored->sequence,
            $stored->eventId,
            $stored->invocationId,
            $stored->causationId,
            $stored->phase->value,
            $stored->type,
            $stored->dedupeKey,
            $stored->occurredAt,
            $stored->schemaVersion,
            $this->encodeJson($stored->payload),
        ]);

        return $stored;
    }

    private function insertCheckpointInTransaction(RunCheckpoint $checkpoint): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO run_checkpoints (
                run_id, invocation_id, event_sequence, status, state_delta_json,
                created_at, schema_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL);
        $statement->execute([
            $checkpoint->runId,
            $checkpoint->invocationId,
            $checkpoint->eventSequence,
            $checkpoint->status->value,
            $this->encodeJson($checkpoint->stateDelta),
            $checkpoint->createdAt,
            $checkpoint->schemaVersion,
        ]);
    }

    private function assertSameEventFact(RunEvent $stored, RunEvent $candidate): void
    {
        $left = $stored->toArray();
        $right = $candidate->withSequence($stored->sequence)->toArray();
        unset($left['event_id'], $left['occurred_at'], $right['event_id'], $right['occurred_at']);
        if ($left !== $right) {
            throw new \RuntimeException(
                "Run event dedupe conflict for {$candidate->runId}:{$candidate->dedupeKey}.",
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function eventFromRow(array $row): RunEvent
    {
        return RunEvent::fromArray([
            'schema_version' => (int) $row['schema_version'],
            'event_id' => $row['event_id'],
            'run_id' => $row['run_id'],
            'invocation_id' => $row['invocation_id'],
            'sequence' => (int) $row['sequence'],
            'causation_id' => $row['causation_id'],
            'phase' => $row['phase'],
            'type' => $row['event_type'],
            'dedupe_key' => $row['dedupe_key'],
            'occurred_at' => $row['occurred_at'],
            'payload' => $this->decodeJson($row['payload_json']),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function checkpointFromRow(array $row): RunCheckpoint
    {
        return RunCheckpoint::fromArray([
            'schema_version' => (int) $row['schema_version'],
            'run_id' => $row['run_id'],
            'invocation_id' => $row['invocation_id'],
            'event_sequence' => (int) $row['event_sequence'],
            'status' => $row['status'],
            'state_delta' => $this->decodeJson($row['state_delta_json']),
            'created_at' => $row['created_at'],
        ]);
    }
}
