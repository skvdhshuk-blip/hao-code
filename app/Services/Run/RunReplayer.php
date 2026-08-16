<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/**
 * Offline projector. It consumes recorded facts and never owns a Provider or a
 * ToolRegistry, so replay cannot execute external side effects.
 *
 * @internal
 */
final class RunReplayer
{
    public function __construct(private readonly RunEventStoreInterface $events) {}

    public function replay(string $runId): RunReplayResult
    {
        $messages = [];
        $toolResults = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0,
            'cache_creation_tokens' => 0, 'cache_read_tokens' => 0];
        $status = RunStatus::Unknown;
        $text = null;
        $lastSequence = 0;
        $seenIds = [];
        $seenDedupe = [];

        foreach ($this->events->read($runId) as $event) {
            if ($event->sequence !== $lastSequence + 1) {
                throw new \RuntimeException("Run {$runId} has a sequence gap at {$event->sequence}.");
            }
            if (isset($seenIds[$event->eventId]) || isset($seenDedupe[$event->dedupeKey])) {
                throw new \RuntimeException("Run {$runId} contains a duplicate event fact.");
            }
            if ($event->causationId !== null && ! isset($seenIds[$event->causationId])) {
                throw new \RuntimeException("Run {$runId} has an unknown causation event.");
            }
            $seenIds[$event->eventId] = true;
            $seenDedupe[$event->dedupeKey] = true;
            $lastSequence = $event->sequence;

            if ($event->type === 'run.started') {
                $status = RunStatus::Running;
                $text = null;
            } elseif ($event->type === 'run.input_recorded' && is_array($event->payload['message'] ?? null)) {
                $messages[] = $event->payload['message'];
            } elseif ($event->type === 'model.completed' && is_array($event->payload['message'] ?? null)) {
                $messages[] = $event->payload['message'];
                foreach ($usage as $key => $_) {
                    $usage[$key] += max(0, (int) ($event->payload['usage'][$key] ?? 0));
                }
                if (is_string($event->payload['text'] ?? null)) {
                    $text = $event->payload['text'];
                }
            } elseif (str_starts_with($event->type, 'tool.')
                && in_array($event->type, ['tool.completed', 'tool.failed', 'tool.cancelled', 'tool.unknown'], true)) {
                $key = (string) ($event->payload['idempotency_key'] ?? $event->dedupeKey);
                $toolResults[$key] = $event->payload;
            } elseif ($event->type === 'run.completed') {
                $status = RunStatus::Completed;
                $text = is_string($event->payload['text'] ?? null) ? $event->payload['text'] : $text;
            } elseif ($event->type === 'run.failed') {
                $status = RunStatus::Failed;
            } elseif ($event->type === 'run.interrupted') {
                $status = RunStatus::Interrupted;
            } elseif ($event->type === 'run.cancelled') {
                $status = RunStatus::Cancelled;
            } elseif ($event->type === 'run.unknown') {
                $status = RunStatus::Unknown;
            } elseif ($event->type === 'human.resumed') {
                $status = RunStatus::Running;
            }
        }

        return new RunReplayResult($runId, $status, $messages, $toolResults, $usage, $text, $lastSequence);
    }
}
