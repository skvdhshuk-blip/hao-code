<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunStatus;
use HaoCode\Services\Run\ToolExecutionAttempt;
use HaoCode\Services\Run\ToolExecutionState;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\ToolUseContext;

trait ToolOrchestratorRunStateConcern
{
    /** @internal */
    public function requiresSequentialToolExecution(): bool
    {
        return $this->durableToolCoordinator !== null;
    }

    /** @internal */
    public function settlePreparedToolBlock(
        array $block,
        ToolExecutionState $state,
        array $result,
    ): void {
        $toolName = (string) ($block['name'] ?? '');
        $toolUseId = (string) ($block['id'] ?? '');
        $tool = $this->toolRegistry->getTool($toolName);
        if ($tool === null) {
            return;
        }
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
        $block['_haocode_prepared'] = true;
        $attempt = $this->beginToolRunState($block, $tool, $input);
        if ($attempt !== null && ! $attempt->execute) {
            return;
        }
        $storedResult = ['type' => 'tool_result'] + $result;
        $this->finishToolRunState($attempt, $toolUseId, $state, $storedResult);
    }

    private function executeSingleTool(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = (string) ($block['id'] ?? '');
        $toolName = (string) ($block['name'] ?? '');
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
        $tool = $this->toolRegistry->getTool($toolName);
        $readOnly = $tool !== null
            && ! $this->mayRunToolHooks($toolName)
            && $this->readOnlyForRunState($tool, $input);
        $attempt = null;

        $toolSpan = $this->tracer?->startSpan(
            name: "tool.{$toolName}",
            openInferenceKind: PhoenixTracer::KIND_TOOL,
            attributes: [
                'tool.name' => $toolName,
                'tool.call_id' => $toolUseId,
                'input.value' => json_encode($input, JSON_UNESCAPED_UNICODE) ?: '',
                'input.mime_type' => 'application/json',
            ],
        );
        $toolScope = $toolSpan?->activate();

        try {
            if ($tool !== null && $tool->isEnabled()) {
                $attempt = $this->beginToolRunState($block, $tool, $input);
                if ($attempt !== null && ! $attempt->execute) {
                    return $attempt->cachedResult ?? $this->runStateError($toolUseId, 'Tool execution is unavailable.');
                }
            }

            $apiResult = $this->executeSingleToolInner($block, $context, $onStart, $onComplete);
            $state = ($apiResult['content'] ?? null) === 'Tool execution aborted'
                ? ToolExecutionState::Cancelled
                : (($apiResult['is_error'] ?? false) ? ToolExecutionState::Failed : ToolExecutionState::Completed);
            $this->finishToolRunState($attempt, $toolUseId, $state, $apiResult);

            if ($toolSpan !== null) {
                $this->tracer?->setAttribute($toolSpan, 'output.value', (string) ($apiResult['content'] ?? ''));
                $this->tracer?->setAttribute($toolSpan, 'tool.is_error', (bool) ($apiResult['is_error'] ?? false));
            }

            return $apiResult;
        } catch (HumanInterruptException $error) {
            $this->finishToolRunState(
                $attempt,
                $toolUseId,
                ToolExecutionState::Interrupted,
                $this->runStateError($toolUseId, 'Tool execution interrupted for human input.'),
            );
            throw $error;
        } catch (\Throwable $error) {
            $state = $readOnly ? ToolExecutionState::Failed : ToolExecutionState::Unknown;
            $this->finishToolRunState(
                $attempt,
                $toolUseId,
                $state,
                $this->runStateError($toolUseId, $state === ToolExecutionState::Unknown
                    ? 'Tool side effect status is unknown; automatic retry is disabled.'
                    : 'Tool execution failed before a result was committed.'),
            );
            $this->tracer?->recordException($toolSpan, $error);
            throw $error;
        } finally {
            $toolScope?->detach();
            $toolSpan?->end();
        }
    }

    private function beginToolRunState(
        array $block,
        ToolInterface $tool,
        array $input,
    ): ?ToolExecutionAttempt {
        if ($this->durableToolCoordinator !== null) {
            return $this->durableToolCoordinator->begin(
                (string) ($block['id'] ?? ''),
                $tool,
                $input,
                ($block['_haocode_prepared'] ?? false) === true,
                is_array($block['_haocode_run_identity_input'] ?? null)
                    ? $block['_haocode_run_identity_input']
                    : null,
                is_bool($block['_haocode_run_read_only'] ?? null)
                    ? $block['_haocode_run_read_only']
                    : ($this->mayRunToolHooks($tool->name()) ? false : null),
            );
        }
        if ($this->runJournal !== null) {
            $id = (string) ($block['id'] ?? '');
            $this->runJournal->record(
                RunEventPhase::Tool,
                'tool.started',
                [
                    'tool_use_id' => $id,
                    'tool_name' => $tool->name(),
                    'input_hash' => $this->toolInputHash($input),
                    'read_only' => ! $this->mayRunToolHooks($tool->name())
                        && $this->readOnlyForRunState($tool, $input),
                ],
                $this->toolEventKey($id, 'started'),
            );
        }

        return null;
    }

    private function finishToolRunState(
        ?ToolExecutionAttempt $attempt,
        string $toolUseId,
        ToolExecutionState $state,
        array $result,
    ): void {
        if ($attempt !== null) {
            if ($attempt->execute) {
                $this->durableToolCoordinator?->finish($attempt, $state, $result);
            }

            return;
        }
        if ($this->runJournal === null) {
            return;
        }

        $event = $this->runJournal->record(
            RunEventPhase::Tool,
            'tool.'.$state->value,
            ['tool_use_id' => $toolUseId, 'result' => $result],
            $this->toolEventKey($toolUseId, $state->value),
        );
        $status = match ($state) {
            ToolExecutionState::Interrupted => RunStatus::Interrupted,
            ToolExecutionState::Cancelled => RunStatus::Cancelled,
            ToolExecutionState::Unknown => RunStatus::Unknown,
            default => RunStatus::Running,
        };
        $this->runJournal->checkpoint($event, $status, [
            'tool_use_id' => $toolUseId,
            'state' => $state->value,
            'result_hash' => $this->toolInputHash($result),
        ]);
    }

    private function toolEventKey(string $toolUseId, string $state): string
    {
        return ($this->runJournal?->invocationId() ?? 'invocation')
            .':tool:'.$toolUseId.':'.$state.':'.($this->runJournal?->causationId() ?? 'root');
    }

    private function pausePreparedToolRunState(?ToolExecutionAttempt $attempt, string $toolUseId): void
    {
        if ($attempt === null || ! $attempt->execute) {
            return;
        }
        $this->durableToolCoordinator?->finish(
            $attempt,
            ToolExecutionState::Interrupted,
            $this->runStateError($toolUseId, 'Tool input and hooks prepared; execution is pending.'),
        );
    }

    private function toolInputHash(array $input): string
    {
        $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($input));
    }

    private function readOnlyForRunState(ToolInterface $tool, array $input): bool
    {
        try {
            return $tool->isReadOnly($input);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function runStateError(string $toolUseId, string $message): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $message,
            'is_error' => true,
        ];
    }
}
