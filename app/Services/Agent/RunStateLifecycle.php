<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Run\RunEvent;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Run\RunStatus;

/** Owns the RunJournal invocation and terminal-event lifecycle. @internal */
final class RunStateLifecycle
{
    public function __construct(private readonly ?RunJournal $journal) {}

    public function begin(string|array $userInput): ?RunEvent
    {
        if ($this->journal === null) {
            return null;
        }
        $invocationId = $this->journal->beginInvocation();
        $started = $this->journal->record(
            RunEventPhase::Run,
            'run.started',
            [],
            $invocationId.':run.started',
        );

        return $this->journal->record(
            RunEventPhase::Run,
            'run.input_recorded',
            ['message' => ['role' => 'user', 'content' => $userInput]],
            $invocationId.':run.input_recorded',
            $started->eventId,
        );
    }

    /** @param array<int, mixed> $decisions */
    public function resume(string $interruptId, array $decisions): void
    {
        if ($this->journal === null) {
            return;
        }
        $this->journal->refreshInvocation();
        $invocationId = $this->journal->invocationId() ?? $this->journal->beginInvocation();
        $causationId = $this->journal->causationId();
        $kinds = [];
        foreach ($decisions as $decision) {
            $value = is_object($decision) && isset($decision->type)
                ? $decision->type
                : (is_array($decision) ? ($decision['type'] ?? null) : null);
            if (is_string($value)) {
                $kinds[] = $value;
            }
        }
        $this->journal->record(
            RunEventPhase::Human,
            'human.resumed',
            [
                'interrupt_id' => $interruptId,
                'decision_count' => count($decisions),
                'decision_kinds' => $kinds,
            ],
            $invocationId.':human:'.$interruptId.':resumed:'.($causationId ?? 'root'),
            $causationId,
        );
    }

    /** @param array<string, int> $usage */
    public function complete(AgentRunOutcome $outcome, int $turns, array $usage): void
    {
        if ($this->journal === null) {
            return;
        }
        $type = $outcome->status === RunStatus::Cancelled ? 'run.cancelled' : 'run.completed';
        $invocationId = $this->journal->invocationId() ?? $this->journal->beginInvocation();
        $event = $this->journal->record(
            RunEventPhase::Run,
            $type,
            [
                'text' => $outcome->text,
                'termination_reason' => $outcome->terminationReason->value,
                'turns' => $turns,
                'usage' => $usage,
            ],
            $invocationId.':'.$type,
        );
        $this->journal->checkpoint($event, $outcome->status, [
            'turns' => $turns,
            'output_hash' => hash('sha256', $outcome->text),
            'termination_reason' => $outcome->terminationReason->value,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public function fail(\Throwable $error, int $turns, array $snapshot): void
    {
        if ($this->journal === null) {
            return;
        }
        $invocationId = $this->journal->invocationId() ?? $this->journal->beginInvocation();
        if ($error instanceof HumanInterruptException) {
            $interrupt = $error->interrupt;
            $human = $this->journal->record(
                RunEventPhase::Human,
                'human.interrupted',
                [
                    'interrupt_id' => $interrupt->id,
                    'action_count' => count($interrupt->actions),
                    'source_agent_id' => $interrupt->sourceAgentId,
                ],
                $invocationId.':human:'.$interrupt->id.':interrupted',
            );
            $event = $this->journal->record(
                RunEventPhase::Run,
                'run.interrupted',
                ['interrupt_id' => $interrupt->id],
                $invocationId.':run.interrupted:'.$interrupt->id,
                $human->eventId,
            );
            $this->journal->checkpoint($event, RunStatus::Interrupted, [
                'interrupt_id' => $interrupt->id,
                'run_snapshot' => $snapshot,
            ]);

            return;
        }
        $event = $this->journal->record(
            RunEventPhase::Run,
            'run.failed',
            ['error_class' => $error::class, 'error' => $error->getMessage()],
            $invocationId.':run.failed',
        );
        $this->journal->checkpoint($event, RunStatus::Failed, ['turns' => $turns]);
    }
}
