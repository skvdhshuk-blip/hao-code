<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

use HaoCode\Contracts\ToolInterface;

/**
 * Owns the durable boundary around hooks and tool code. It provides at-least-
 * once execution for read-only calls and fail-closed recovery for mutations.
 *
 * @internal
 */
final class DurableToolExecutionCoordinator
{
    private const LEASE_DURATION_MS = 300_000;

    public function __construct(
        private readonly ToolExecutionStoreInterface $store,
        private readonly RunJournal $journal,
        private readonly string $ownerId,
    ) {
        if (trim($ownerId) === '') {
            throw new \InvalidArgumentException('Tool execution owner id must not be empty.');
        }
    }

    /** @param array<string, mixed> $input */
    public function begin(
        string $toolUseId,
        ToolInterface $tool,
        array $input,
        bool $resumeInterrupted,
        ?array $identityInput = null,
        ?bool $readOnlyOverride = null,
    ): ToolExecutionAttempt {
        $invocationId = $this->journal->invocationId() ?? $this->journal->beginInvocation();
        $key = hash('sha256', implode("\0", [
            $this->journal->runId(),
            $invocationId,
            $toolUseId,
        ]));
        $readOnly = $readOnlyOverride ?? $this->classifyReadOnly($tool, $input);
        $request = new ToolExecutionRequest(
            idempotencyKey: 'tool_'.$key,
            runId: $this->journal->runId(),
            invocationId: $invocationId,
            toolUseId: $toolUseId,
            toolName: $tool->name(),
            inputHash: hash('sha256', $this->canonicalJson($identityInput ?? $input)),
            readOnly: $readOnly,
            resumeInterrupted: $resumeInterrupted,
        );
        $event = $this->journal->draft(
            RunEventPhase::Tool,
            'tool.claimed',
            $this->identityPayload($request),
            $request->idempotencyKey.':claim:'.$this->ownerId.':'.bin2hex(random_bytes(8)),
        );
        $claim = $this->store->claimToolExecution(
            $request,
            $this->ownerId,
            self::LEASE_DURATION_MS,
            $this->nowMs(),
            $event,
            ['tool_execution' => $request->idempotencyKey, 'state' => ToolExecutionState::Claimed->value],
        );
        if ($claim->event !== null) {
            $this->journal->accept($claim->event);
        }

        if (! $claim->execute) {
            return new ToolExecutionAttempt(
                $request->idempotencyKey,
                $this->ownerId,
                $claim->record->fencingToken,
                false,
                $readOnly,
                $claim->record->result ?? $this->unavailableResult($request, $claim->record->state),
            );
        }

        $started = $this->store->markToolExecutionStarted(
            $request->idempotencyKey,
            $this->ownerId,
            $claim->record->fencingToken,
            $this->nowMs(),
        );

        return new ToolExecutionAttempt(
            $request->idempotencyKey,
            $this->ownerId,
            $started->fencingToken,
            true,
            $readOnly,
        );
    }

    /** @param array<string, mixed> $result */
    public function finish(
        ToolExecutionAttempt $attempt,
        ToolExecutionState $state,
        array $result,
    ): RunEvent {
        $status = match ($state) {
            ToolExecutionState::Completed => RunStatus::Running,
            ToolExecutionState::Failed => RunStatus::Running,
            ToolExecutionState::Interrupted => RunStatus::Interrupted,
            ToolExecutionState::Cancelled => RunStatus::Cancelled,
            ToolExecutionState::Unknown => RunStatus::Unknown,
            default => throw new \InvalidArgumentException('Tool execution has not reached a committable state.'),
        };
        $type = 'tool.'.$state->value;
        $event = $this->journal->draft(
            RunEventPhase::Tool,
            $type,
            [
                'idempotency_key' => $attempt->idempotencyKey,
                'result' => $result,
            ],
            $attempt->idempotencyKey.':'.$state->value.':'.$attempt->fencingToken,
        );
        $stored = $this->store->commitToolExecution(
            $attempt->idempotencyKey,
            $attempt->ownerId,
            $attempt->fencingToken,
            $state,
            $result,
            $this->nowMs(),
            $event,
            $status,
            [
                'tool_execution' => $attempt->idempotencyKey,
                'state' => $state->value,
                'result_hash' => hash('sha256', $this->canonicalJson($result)),
            ],
        );
        $this->journal->accept($stored);

        return $stored;
    }

    /** @param array<string, mixed> $input */
    private function classifyReadOnly(ToolInterface $tool, array $input): bool
    {
        try {
            return $tool->isReadOnly($input);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function identityPayload(ToolExecutionRequest $request): array
    {
        return [
            'idempotency_key' => $request->idempotencyKey,
            'tool_use_id' => $request->toolUseId,
            'tool_name' => $request->toolName,
            'input_hash' => $request->inputHash,
            'read_only' => $request->readOnly,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableResult(ToolExecutionRequest $request, ToolExecutionState $state): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $request->toolUseId,
            'content' => $state === ToolExecutionState::Interrupted
                ? 'Tool execution is waiting for a human decision.'
                : 'Tool execution is already claimed by another worker.',
            'is_error' => true,
        ];
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }

            return $item;
        };
        $encoded = json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not hash tool input.');
        }

        return $encoded;
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
