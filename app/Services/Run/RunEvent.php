<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunEvent
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $runId,
        public readonly string $invocationId,
        public readonly int $sequence,
        public readonly ?string $causationId,
        public readonly RunEventPhase $phase,
        public readonly string $type,
        public readonly string $dedupeKey,
        public readonly string $occurredAt,
        public readonly array $payload = [],
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('Unsupported RunEvent schema version.');
        }
        foreach (['eventId' => $eventId, 'runId' => $runId, 'invocationId' => $invocationId,
            'type' => $type, 'dedupeKey' => $dedupeKey, 'occurredAt' => $occurredAt] as $name => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("RunEvent {$name} must not be empty.");
            }
        }
        if ($sequence < 0) {
            throw new \InvalidArgumentException('RunEvent sequence must be non-negative.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function draft(
        string $runId,
        string $invocationId,
        RunEventPhase $phase,
        string $type,
        string $dedupeKey,
        array $payload = [],
        ?string $causationId = null,
    ): self {
        return new self(
            eventId: 'evt_'.bin2hex(random_bytes(16)),
            runId: $runId,
            invocationId: $invocationId,
            sequence: 0,
            causationId: $causationId,
            phase: $phase,
            type: $type,
            dedupeKey: $dedupeKey,
            occurredAt: self::now(),
            payload: $payload,
        );
    }

    public function withSequence(int $sequence): self
    {
        return new self(
            $this->eventId,
            $this->runId,
            $this->invocationId,
            $sequence,
            $this->causationId,
            $this->phase,
            $this->type,
            $this->dedupeKey,
            $this->occurredAt,
            $this->payload,
            $this->schemaVersion,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'event_id' => $this->eventId,
            'run_id' => $this->runId,
            'invocation_id' => $this->invocationId,
            'sequence' => $this->sequence,
            'causation_id' => $this->causationId,
            'phase' => $this->phase->value,
            'type' => $this->type,
            'dedupe_key' => $this->dedupeKey,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $value): self
    {
        $phase = is_string($value['phase'] ?? null)
            ? RunEventPhase::tryFrom($value['phase'])
            : null;
        if ($phase === null || ! is_array($value['payload'] ?? [])) {
            throw new \InvalidArgumentException('Invalid RunEvent payload.');
        }

        return new self(
            eventId: (string) ($value['event_id'] ?? ''),
            runId: (string) ($value['run_id'] ?? ''),
            invocationId: (string) ($value['invocation_id'] ?? ''),
            sequence: (int) ($value['sequence'] ?? -1),
            causationId: is_string($value['causation_id'] ?? null) ? $value['causation_id'] : null,
            phase: $phase,
            type: (string) ($value['type'] ?? ''),
            dedupeKey: (string) ($value['dedupe_key'] ?? ''),
            occurredAt: (string) ($value['occurred_at'] ?? ''),
            payload: $value['payload'] ?? [],
            schemaVersion: (int) ($value['schema_version'] ?? 0),
        );
    }

    private static function now(): string
    {
        $now = microtime(true);
        $seconds = (int) $now;
        $micros = (int) (($now - $seconds) * 1_000_000);

        return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%06dZ', $micros);
    }
}
