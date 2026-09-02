<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Collects model-facing text that must reach the model at the next turn boundary.
 *
 * Two kinds of content flow through here. `push()` queues a one-off notice (a tool
 * asking the loop to tell the model something). `addProducer()` registers a callback
 * consulted at every boundary, for content that depends on the turn number or on
 * external state (background task completions, periodic goal reminders).
 *
 * Producers run inside `drain()` rather than in the loop's event pump so their text
 * lands on a message that has not been sent yet — appending to the pending
 * tool-result user message keeps the cached prompt prefix intact, because the single
 * cache breakpoint sits on the penultimate message.
 *
 * @internal
 */
final class TurnInjectionQueue
{
    /** @var list<string> */
    private array $pending = [];

    /** @var list<\Closure(int, string): ?string> */
    private array $producers = [];

    /** @var array{reason: string, text: string}|null */
    private ?array $termination = null;

    /** Queue text for delivery at the next turn boundary. */
    public function push(string $text): void
    {
        $text = trim($text);
        if ($text !== '') {
            $this->pending[] = $text;
        }
    }

    /**
     * Register a producer consulted at every turn boundary.
     *
     * @param  callable(int, string): ?string  $producer  Receives the completed turn number
     *                                                    (0 at run start) and the session id.
     */
    public function addProducer(callable $producer): void
    {
        $this->producers[] = \Closure::fromCallable($producer);
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Collect everything owed to the model at this boundary, clearing the queue.
     *
     * Pushed text comes first (it was queued by an earlier tool call in this turn),
     * then producer output in registration order. Returns null when nothing is owed
     * so callers can skip the injection entirely.
     */
    public function drain(int $completedTurn, string $sessionId): ?string
    {
        $parts = $this->pending;
        $this->pending = [];
        foreach ($this->producers as $producer) {
            $produced = $producer($completedTurn, $sessionId);
            if (is_string($produced) && trim($produced) !== '') {
                $parts[] = trim($produced);
            }
        }
        if ($parts === []) {
            return null;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Ask the loop to end the run once the current tool batch is fed back.
     *
     * Used by ExitPlanMode when the host, not the model, decides what happens next.
     * The first request wins; a second call is ignored so a tool batch cannot
     * overwrite an earlier termination with a different reason.
     */
    public function requestTermination(string $reason, string $text): void
    {
        $this->termination ??= ['reason' => $reason, 'text' => $text];
    }

    /**
     * Take the pending termination request, if any. One-shot.
     *
     * @return array{reason: string, text: string}|null
     */
    public function takeTermination(): ?array
    {
        $termination = $this->termination;
        $this->termination = null;

        return $termination;
    }

    /**
     * Append a text block to user-message content, normalizing a plain string to blocks.
     *
     * @param  string|array<int, mixed>  $input
     * @return array<int, mixed>
     */
    public static function appendTextBlock(string|array $input, string $text): array
    {
        $blocks = is_string($input)
            ? [['type' => 'text', 'text' => $input]]
            : $input;
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }
}
