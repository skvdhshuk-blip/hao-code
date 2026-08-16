<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunCheckpoint
{
    /** @param array<string, mixed> $stateDelta */
    public function __construct(
        public readonly string $runId,
        public readonly string $invocationId,
        public readonly int $eventSequence,
        public readonly RunStatus $status,
        public readonly array $stateDelta,
        public readonly string $createdAt,
        public readonly int $schemaVersion = 1,
    ) {
        if ($runId === '' || $invocationId === '' || $eventSequence < 0 || $schemaVersion !== 1) {
            throw new \InvalidArgumentException('Invalid RunCheckpoint.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'run_id' => $this->runId,
            'invocation_id' => $this->invocationId,
            'event_sequence' => $this->eventSequence,
            'status' => $this->status->value,
            'state_delta' => $this->stateDelta,
            'created_at' => $this->createdAt,
        ];
    }

    public static function fromArray(array $value): self
    {
        $status = is_string($value['status'] ?? null)
            ? RunStatus::tryFrom($value['status'])
            : null;
        if ($status === null || ! is_array($value['state_delta'] ?? null)) {
            throw new \InvalidArgumentException('Invalid RunCheckpoint payload.');
        }

        return new self(
            (string) ($value['run_id'] ?? ''),
            (string) ($value['invocation_id'] ?? ''),
            (int) ($value['event_sequence'] ?? -1),
            $status,
            $value['state_delta'],
            (string) ($value['created_at'] ?? ''),
            (int) ($value['schema_version'] ?? 0),
        );
    }
}
