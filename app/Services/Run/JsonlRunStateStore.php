<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

use HaoCode\Services\Session\SessionJsonlStore;

/**
 * Run-state adapter for the existing session JSONL files.
 *
 * Legacy transcript records remain untouched. New execution records use
 * `run_event` and `run_checkpoint` envelopes in the same file.
 *
 * @internal
 */
final class JsonlRunStateStore implements RunStateStoreInterface
{
    private const MAX_ENTRY_BYTES = 32 * 1024 * 1024;
    private const MAX_SESSION_BYTES = 128 * 1024 * 1024;

    private SessionJsonlStore $jsonl;

    /**
     * Per-process append cursor. The file lock remains authoritative; this
     * cache only avoids rescanning the immutable prefix on every event.
     *
     * @var array<string, array{offset: int, last_sequence: int, dedupe: array<string, RunEvent>}>
     */
    private array $eventIndexes = [];

    public function __construct(private readonly string $sessionPath)
    {
        if (trim($sessionPath) === '') {
            throw new \InvalidArgumentException('Session path must not be empty.');
        }
        $this->jsonl = new SessionJsonlStore(self::MAX_ENTRY_BYTES, self::MAX_SESSION_BYTES);
    }

    public function append(RunEvent $event): RunEvent
    {
        $handle = $this->openForUpdate($event->runId);
        try {
            $this->lock($handle, $event->runId);
            [$lastSequence, $existing] = $this->inspectEvents($handle, $event->runId, $event->dedupeKey);
            if ($existing !== null) {
                $this->assertSameFact($existing, $event);

                return $existing;
            }

            $stored = $event->withSequence($lastSequence + 1);
            $this->jsonl->appendJsonLine($handle, $this->eventEntry($stored));
            $this->eventIndexes[$event->runId]['last_sequence'] = $stored->sequence;
            $this->eventIndexes[$event->runId]['dedupe'][$stored->dedupeKey] = $stored;
            $offset = ftell($handle);
            if (is_int($offset)) {
                $this->eventIndexes[$event->runId]['offset'] = $offset;
            }

            return $stored;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(string $runId, int $afterSequence = 0): iterable
    {
        $path = $this->path($runId);
        if (! is_file($path)) {
            return [];
        }

        $events = [];
        foreach ($this->jsonl->readEntries($path) as $entry) {
            if (($entry['type'] ?? null) !== 'run_event' || ! is_array($entry['event'] ?? null)) {
                continue;
            }
            $event = RunEvent::fromArray($entry['event']);
            if ($event->runId === $runId && $event->sequence > $afterSequence) {
                $events[] = $event;
            }
        }
        usort($events, static fn (RunEvent $a, RunEvent $b): int => $a->sequence <=> $b->sequence);

        return $events;
    }

    public function commitCheckpoint(RunCheckpoint $checkpoint): void
    {
        $handle = $this->openForUpdate($checkpoint->runId);
        try {
            $this->lock($handle, $checkpoint->runId);
            $this->jsonl->appendJsonLine($handle, [
                'timestamp' => $checkpoint->createdAt,
                'session_id' => $checkpoint->runId,
                'type' => 'run_checkpoint',
                'checkpoint' => $checkpoint->toArray(),
            ]);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function latestCheckpoint(string $runId): ?RunCheckpoint
    {
        $path = $this->path($runId);
        if (! is_file($path)) {
            return null;
        }

        $latest = null;
        foreach ($this->jsonl->readEntries($path) as $entry) {
            if (($entry['type'] ?? null) !== 'run_checkpoint'
                || ! is_array($entry['checkpoint'] ?? null)) {
                continue;
            }
            $candidate = RunCheckpoint::fromArray($entry['checkpoint']);
            if ($candidate->runId === $runId
                && ($latest === null || $candidate->eventSequence >= $latest->eventSequence)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /** @return resource */
    private function openForUpdate(string $runId)
    {
        $this->assertRunId($runId);
        if (! is_dir($this->sessionPath)
            && ! @mkdir($this->sessionPath, 0700, true)
            && ! is_dir($this->sessionPath)) {
            throw new \RuntimeException("Could not create session directory: {$this->sessionPath}");
        }
        $handle = @fopen($this->path($runId), 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open run state for {$runId}.");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function lock($handle, string $runId): void
    {
        if (! flock($handle, LOCK_EX)) {
            throw new \RuntimeException("Could not lock run state for {$runId}.");
        }
    }

    /**
     * @param resource $handle
     * @return array{int, ?RunEvent}
     */
    private function inspectEvents($handle, string $runId, string $dedupeKey): array
    {
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new \RuntimeException("Could not inspect run state for {$runId}.");
        }
        $size = ftell($handle);
        if (! is_int($size)) {
            throw new \RuntimeException("Could not inspect run state size for {$runId}.");
        }
        $index = $this->eventIndexes[$runId] ?? [
            'offset' => 0,
            'last_sequence' => 0,
            'dedupe' => [],
        ];
        if ($size < $index['offset']) {
            $index = ['offset' => 0, 'last_sequence' => 0, 'dedupe' => []];
        }
        if (fseek($handle, $index['offset'], SEEK_SET) !== 0) {
            throw new \RuntimeException("Could not seek run state for {$runId}.");
        }
        $lastSequence = $index['last_sequence'];
        while (($line = fgets($handle, self::MAX_ENTRY_BYTES + 1)) !== false) {
            if (strlen($line) >= self::MAX_ENTRY_BYTES && ! str_ends_with($line, "\n")) {
                throw new \RuntimeException('Session entry exceeds the 32 MiB persistence limit.');
            }
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                throw new \RuntimeException(
                    "Run state {$runId} contains invalid JSON: ".json_last_error_msg(),
                );
            }
            if (($entry['type'] ?? null) !== 'run_event' || ! is_array($entry['event'] ?? null)) {
                continue;
            }
            $candidate = RunEvent::fromArray($entry['event']);
            if ($candidate->runId !== $runId) {
                continue;
            }
            $lastSequence = max($lastSequence, $candidate->sequence);
            $index['dedupe'][$candidate->dedupeKey] = $candidate;
        }
        $offset = ftell($handle);
        if (! is_int($offset)) {
            throw new \RuntimeException("Could not update run state cursor for {$runId}.");
        }
        $index['offset'] = $offset;
        $index['last_sequence'] = $lastSequence;
        $this->eventIndexes[$runId] = $index;

        return [$lastSequence, $index['dedupe'][$dedupeKey] ?? null];
    }

    private function assertSameFact(RunEvent $stored, RunEvent $candidate): void
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

    /** @return array<string, mixed> */
    private function eventEntry(RunEvent $event): array
    {
        return [
            'timestamp' => $event->occurredAt,
            'session_id' => $event->runId,
            'type' => 'run_event',
            'event' => $event->toArray(),
        ];
    }

    private function path(string $runId): string
    {
        $this->assertRunId($runId);

        return rtrim($this->sessionPath, '/\\').DIRECTORY_SEPARATOR.$runId.'.jsonl';
    }

    private function assertRunId(string $runId): void
    {
        if ($runId === '' || strlen($runId) > 128 || preg_match('/[^A-Za-z0-9_-]/', $runId) === 1) {
            throw new \InvalidArgumentException('Invalid run id.');
        }
    }
}
