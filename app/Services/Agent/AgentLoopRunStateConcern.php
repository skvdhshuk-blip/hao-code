<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Run\RunEvent;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunStatus;

trait AgentLoopRunStateConcern
{
    private function beginRunState(string|array $userInput): ?RunEvent
    {
        if ($this->runJournal === null) {
            return null;
        }
        $invocationId = $this->runJournal->beginInvocation();
        $started = $this->runJournal->record(
            RunEventPhase::Run,
            'run.started',
            [],
            $invocationId.':run.started',
        );
        $message = ['role' => 'user', 'content' => $userInput];
        return $this->runJournal->record(
            RunEventPhase::Run,
            'run.input_recorded',
            ['message' => $message],
            $invocationId.':run.input_recorded',
            $started->eventId,
        );
    }

    /** @param array<int, mixed> $decisions */
    private function resumeRunState(string $interruptId, array $decisions): void
    {
        if ($this->runJournal === null) {
            return;
        }
        // Session loading happens after the loop snapshot is restored. Refresh
        // now so causation is read from the canonical resumed session id.
        $this->runJournal->refreshInvocation();
        $invocationId = $this->runJournal->invocationId() ?? $this->runJournal->beginInvocation();
        $causationId = $this->runJournal->causationId();
        $decisionKinds = [];
        foreach ($decisions as $decision) {
            $value = is_object($decision) && isset($decision->type)
                ? $decision->type
                : (is_array($decision) ? ($decision['type'] ?? null) : null);
            if (is_string($value)) {
                $decisionKinds[] = $value;
            }
        }
        $this->runJournal->record(
            RunEventPhase::Human,
            'human.resumed',
            [
                'interrupt_id' => $interruptId,
                'decision_count' => count($decisions),
                'decision_kinds' => $decisionKinds,
            ],
            $invocationId.':human:'.$interruptId.':resumed:'.($causationId ?? 'root'),
            $causationId,
        );
    }

    private function completeRunState(string $output): void
    {
        if ($this->runJournal === null) {
            return;
        }
        $cancelled = $output === '(aborted)' || $this->isCancellationRequested();
        $type = $cancelled ? 'run.cancelled' : 'run.completed';
        $status = $cancelled ? RunStatus::Cancelled : RunStatus::Completed;
        $invocationId = $this->runJournal->invocationId() ?? $this->runJournal->beginInvocation();
        $event = $this->runJournal->record(
            RunEventPhase::Run,
            $type,
            [
                'text' => $output,
                'turns' => $this->lastRunTurns,
                'usage' => [
                    'input_tokens' => $this->totalInputTokens,
                    'output_tokens' => $this->totalOutputTokens,
                    'cache_creation_tokens' => $this->totalCacheCreationTokens,
                    'cache_read_tokens' => $this->totalCacheReadTokens,
                ],
            ],
            $invocationId.':'.$type,
        );
        $this->runJournal->checkpoint($event, $status, [
            'turns' => $this->lastRunTurns,
            'output_hash' => hash('sha256', $output),
        ]);
    }

    private function failRunState(\Throwable $error): void
    {
        if ($this->runJournal === null) {
            return;
        }
        $invocationId = $this->runJournal->invocationId() ?? $this->runJournal->beginInvocation();
        if ($error instanceof HumanInterruptException) {
            $interrupt = $error->interrupt;
            $human = $this->runJournal->record(
                RunEventPhase::Human,
                'human.interrupted',
                [
                    'interrupt_id' => $interrupt->id,
                    'action_count' => count($interrupt->actions),
                    'source_agent_id' => $interrupt->sourceAgentId,
                ],
                $invocationId.':human:'.$interrupt->id.':interrupted',
            );
            $event = $this->runJournal->record(
                RunEventPhase::Run,
                'run.interrupted',
                ['interrupt_id' => $interrupt->id],
                $invocationId.':run.interrupted:'.$interrupt->id,
                $human->eventId,
            );
            $this->runJournal->checkpoint($event, RunStatus::Interrupted, [
                'interrupt_id' => $interrupt->id,
                'run_snapshot' => $this->buildRunSnapshot($this->lastRunTurns),
            ]);

            return;
        }

        $event = $this->runJournal->record(
            RunEventPhase::Run,
            'run.failed',
            ['error_class' => $error::class, 'error' => $error->getMessage()],
            $invocationId.':run.failed',
        );
        $this->runJournal->checkpoint($event, RunStatus::Failed, [
            'turns' => $this->lastRunTurns,
        ]);
    }
}
