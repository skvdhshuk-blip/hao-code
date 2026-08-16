<?php

declare(strict_types=1);

namespace Tests\Unit\Run;

use HaoCode\Services\Run\RunEvent;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunStatus;
use HaoCode\Services\Run\SqliteRunStateStore;
use HaoCode\Services\Run\ToolExecutionRequest;
use HaoCode\Services\Run\ToolExecutionState;
use PHPUnit\Framework\TestCase;

final class RunRecoveryChaosTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $this->database = sys_get_temp_dir().'/haocode-chaos-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
        @unlink($this->database.'-shm');
        @unlink($this->database.'-wal');
    }

    public function test_model_boundary_survives_one_hundred_record_and_retry_cycles(): void
    {
        $store = new SqliteRunStateStore($this->database);
        for ($index = 0; $index < 100; $index++) {
            $runId = 'model-run-'.$index;
            $requested = RunEvent::draft(
                $runId, 'inv-1', RunEventPhase::Model, 'model.requested', 'request',
            );
            $first = $store->append($requested);
            $retry = $store->append($requested);
            $completed = RunEvent::draft(
                $runId,
                'inv-1',
                RunEventPhase::Model,
                'model.completed',
                'completed',
                ['text' => 'ok'],
                $first->eventId,
            );
            $store->append($completed);
            $store->append($completed);

            self::assertSame($first->eventId, $retry->eventId);
            self::assertCount(2, iterator_to_array($store->read($runId)));
        }
    }

    public function test_tool_boundary_survives_one_hundred_state_and_retry_cycles_without_duplicate_commit(): void
    {
        $store = new SqliteRunStateStore($this->database);
        for ($index = 0; $index < 100; $index++) {
            $request = $this->request('tool-'.$index, 'tool-run-'.$index, false);
            $claim = $store->claimToolExecution(
                $request,
                'worker-a',
                10,
                1_000,
                $this->event($request, 'tool.claimed', 'claim-a'),
                ['boundary' => 'before_effect'],
            );
            $store->markToolExecutionStarted(
                $request->idempotencyKey,
                'worker-a',
                $claim->record->fencingToken,
                1_001,
            );

            if ($index % 2 === 0) {
                $store->commitToolExecution(
                    $request->idempotencyKey,
                    'worker-a',
                    $claim->record->fencingToken,
                    ToolExecutionState::Completed,
                    ['content' => 'committed', 'is_error' => false],
                    1_002,
                    $this->event($request, 'tool.completed', 'completed'),
                    RunStatus::Running,
                    ['boundary' => 'after_result'],
                );
            }

            $retry = $store->claimToolExecution(
                $request,
                'worker-b',
                10,
                1_011,
                $this->event($request, 'tool.claimed', 'claim-b'),
                [],
            );
            self::assertFalse($retry->execute);
            self::assertSame(
                $index % 2 === 0 ? ToolExecutionState::Completed : ToolExecutionState::Unknown,
                $retry->record->state,
            );
        }
    }

    public function test_hitl_boundary_survives_one_hundred_interrupt_resume_and_retry_cycles(): void
    {
        $store = new SqliteRunStateStore($this->database);
        for ($index = 0; $index < 100; $index++) {
            $request = $this->request('hitl-'.$index, 'hitl-run-'.$index, false);
            $claim = $store->claimToolExecution(
                $request,
                'worker-a',
                100,
                1_000,
                $this->event($request, 'tool.claimed', 'claim-a'),
                [],
            );
            $store->markToolExecutionStarted($request->idempotencyKey, 'worker-a', 1, 1_001);
            $store->commitToolExecution(
                $request->idempotencyKey,
                'worker-a',
                1,
                ToolExecutionState::Interrupted,
                ['content' => 'waiting', 'is_error' => true],
                1_002,
                $this->event($request, 'tool.interrupted', 'interrupted'),
                RunStatus::Interrupted,
                ['interrupt_id' => 'int-'.$index],
            );

            $resumeRequest = $this->request('hitl-'.$index, 'hitl-run-'.$index, false, true);
            $resumed = $store->claimToolExecution(
                $resumeRequest,
                'worker-b',
                100,
                1_003,
                $this->event($resumeRequest, 'tool.claimed', 'claim-b'),
                [],
            );
            self::assertTrue($resumed->execute);
            self::assertSame(2, $resumed->record->fencingToken);
            $store->markToolExecutionStarted($request->idempotencyKey, 'worker-b', 2, 1_004);
            $store->commitToolExecution(
                $request->idempotencyKey,
                'worker-b',
                2,
                ToolExecutionState::Completed,
                ['content' => 'done', 'is_error' => false],
                1_005,
                $this->event($request, 'tool.completed', 'completed'),
                RunStatus::Running,
                [],
            );

            $retry = $store->claimToolExecution(
                $resumeRequest,
                'worker-c',
                100,
                1_006,
                $this->event($resumeRequest, 'tool.claimed', 'claim-c'),
                [],
            );
            self::assertFalse($retry->execute);
            self::assertSame(ToolExecutionState::Completed, $retry->record->state);
        }
    }

    private function request(
        string $key,
        string $runId,
        bool $readOnly,
        bool $resumeInterrupted = false,
    ): ToolExecutionRequest {
        return new ToolExecutionRequest(
            $key,
            $runId,
            'inv-1',
            'call-1',
            'Example',
            hash('sha256', '{}'),
            $readOnly,
            $resumeInterrupted,
        );
    }

    private function event(ToolExecutionRequest $request, string $type, string $dedupe): RunEvent
    {
        return RunEvent::draft(
            $request->runId,
            $request->invocationId,
            RunEventPhase::Tool,
            $type,
            $request->idempotencyKey.':'.$dedupe,
            ['idempotency_key' => $request->idempotencyKey],
        );
    }
}
