<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunJournal
{
    private ?string $invocationId = null;
    private ?string $lastEventId = null;
    private int $logicalCounter = 0;

    /** @param \Closure(): string $runId */
    public function __construct(
        private readonly RunEventStoreInterface $events,
        private readonly RunCheckpointStoreInterface $checkpoints,
        private readonly \Closure $runId,
    ) {}

    public function beginInvocation(?string $invocationId = null): string
    {
        $this->invocationId = $invocationId ?? 'inv_'.bin2hex(random_bytes(16));
        $this->lastEventId = null;
        $this->logicalCounter = 0;

        return $this->invocationId;
    }

    public function restoreInvocation(string $invocationId): void
    {
        if (trim($invocationId) === '') {
            throw new \InvalidArgumentException('Invocation id must not be empty.');
        }
        $this->invocationId = $invocationId;
        $this->refreshInvocation();
    }

    public function refreshInvocation(): void
    {
        $this->lastEventId = null;
        $this->logicalCounter = 0;
        if ($this->invocationId === null) {
            return;
        }
        foreach ($this->events->read($this->runId()) as $event) {
            if ($event->invocationId !== $this->invocationId) {
                continue;
            }
            $this->lastEventId = $event->eventId;
            $this->logicalCounter++;
        }
    }

    public function invocationId(): ?string
    {
        return $this->invocationId;
    }

    public function causationId(): ?string
    {
        return $this->lastEventId;
    }

    public function runId(): string
    {
        return ($this->runId)();
    }

    /** @param array<string, mixed> $payload */
    public function draft(
        RunEventPhase $phase,
        string $type,
        array $payload,
        string $dedupeKey,
        ?string $causationId = null,
    ): RunEvent {
        $invocationId = $this->invocationId ?? $this->beginInvocation();

        return RunEvent::draft(
            runId: $this->runId(),
            invocationId: $invocationId,
            phase: $phase,
            type: $type,
            dedupeKey: $dedupeKey,
            payload: $payload,
            causationId: $causationId ?? $this->lastEventId,
        );
    }

    public function accept(RunEvent $event): void
    {
        if ($event->runId !== $this->runId()) {
            throw new \LogicException('Cannot accept an event from another run.');
        }
        $this->lastEventId = $event->eventId;
    }

    /** @param array<string, mixed> $payload */
    public function record(
        RunEventPhase $phase,
        string $type,
        array $payload = [],
        ?string $dedupeKey = null,
        ?string $causationId = null,
    ): RunEvent {
        $invocationId = $this->invocationId ?? $this->beginInvocation();
        $dedupeKey ??= $invocationId.':'.$type.':'.(++$this->logicalCounter).':'.bin2hex(random_bytes(6));
        $event = $this->events->append($this->draft(
            $phase,
            $type,
            $payload,
            $dedupeKey,
            $causationId,
        ));
        $this->accept($event);

        return $event;
    }

    /** @param array<string, mixed> $stateDelta */
    public function checkpoint(
        RunEvent $afterEvent,
        RunStatus $status,
        array $stateDelta,
    ): RunCheckpoint {
        $checkpoint = new RunCheckpoint(
            runId: $this->runId(),
            invocationId: $this->invocationId ?? $afterEvent->invocationId,
            eventSequence: $afterEvent->sequence,
            status: $status,
            stateDelta: $stateDelta,
            createdAt: gmdate('c'),
        );
        $this->checkpoints->commitCheckpoint($checkpoint);

        return $checkpoint;
    }

    public function eventStore(): RunEventStoreInterface
    {
        return $this->events;
    }
}
