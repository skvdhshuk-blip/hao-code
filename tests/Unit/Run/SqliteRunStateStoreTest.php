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

final class SqliteRunStateStoreTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $this->database = sys_get_temp_dir().'/haocode-run-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
        @unlink($this->database.'-shm');
        @unlink($this->database.'-wal');
    }

    public function test_claim_checkpoint_and_result_commit_are_durable(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $request = $this->request(readOnly: false);
        $claim = $store->claimToolExecution(
            $request,
            'worker-a',
            10_000,
            1_000,
            $this->event('tool.claimed', 'claim-a'),
            ['boundary' => 'before_effect'],
        );
        self::assertTrue($claim->execute);
        self::assertSame(1, $claim->record->fencingToken);
        self::assertSame('before_effect', $store->latestCheckpoint('run-1')?->stateDelta['boundary']);

        $store->markToolExecutionStarted('tool-key', 'worker-a', 1, 1_001);
        $event = $store->commitToolExecution(
            'tool-key',
            'worker-a',
            1,
            ToolExecutionState::Completed,
            ['content' => 'ok', 'is_error' => false],
            1_002,
            $this->event('tool.completed', 'completed'),
            RunStatus::Running,
            ['boundary' => 'after_result'],
        );

        self::assertSame(2, $event->sequence);
        self::assertSame(ToolExecutionState::Completed, $store->getToolExecution('tool-key')?->state);
        self::assertSame('after_result', $store->latestCheckpoint('run-1')?->stateDelta['boundary']);
    }

    public function test_two_workers_cannot_claim_the_same_active_run(): void
    {
        $left = new SqliteRunStateStore($this->database);
        $right = new SqliteRunStateStore($this->database);

        $a = $left->claimRun('run-1', 'worker-a', 1_000, 1_000);
        $b = $right->claimRun('run-1', 'worker-b', 1_000, 1_100);
        $c = $right->claimRun('run-1', 'worker-b', 1_000, 2_001);

        self::assertTrue($a->acquired);
        self::assertFalse($b->acquired);
        self::assertTrue($c->acquired);
        self::assertGreaterThan($a->fencingToken, $c->fencingToken);
    }

    public function test_expired_started_side_effect_becomes_unknown_and_is_not_reclaimed(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $claim = $store->claimToolExecution(
            $this->request(readOnly: false),
            'worker-a',
            100,
            1_000,
            $this->event('tool.claimed', 'claim-a'),
            [],
        );
        $store->markToolExecutionStarted('tool-key', 'worker-a', $claim->record->fencingToken, 1_001);

        $retry = $store->claimToolExecution(
            $this->request(readOnly: false),
            'worker-b',
            100,
            1_101,
            $this->event('tool.claimed', 'claim-b'),
            [],
        );

        self::assertFalse($retry->execute);
        self::assertSame(ToolExecutionState::Unknown, $retry->record->state);
        self::assertSame(RunStatus::Unknown, $store->latestCheckpoint('run-1')?->status);
    }

    public function test_expired_read_only_execution_can_be_reclaimed_with_new_fence(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $claim = $store->claimToolExecution(
            $this->request(readOnly: true),
            'worker-a',
            100,
            1_000,
            $this->event('tool.claimed', 'claim-a'),
            [],
        );
        $store->markToolExecutionStarted('tool-key', 'worker-a', $claim->record->fencingToken, 1_001);

        $retry = $store->claimToolExecution(
            $this->request(readOnly: true),
            'worker-b',
            100,
            1_101,
            $this->event('tool.claimed', 'claim-b'),
            [],
        );

        self::assertTrue($retry->execute);
        self::assertSame(2, $retry->record->fencingToken);
    }

    public function test_active_started_execution_is_not_reexecuted_by_the_same_worker(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $request = $this->request(readOnly: true);
        $claim = $store->claimToolExecution(
            $request,
            'worker-a',
            1_000,
            1_000,
            $this->event('tool.claimed', 'claim-a'),
            [],
        );
        $store->markToolExecutionStarted('tool-key', 'worker-a', $claim->record->fencingToken, 1_001);

        $duplicate = $store->claimToolExecution(
            $request,
            'worker-a',
            1_000,
            1_002,
            $this->event('tool.claimed', 'claim-b'),
            [],
        );

        self::assertFalse($duplicate->execute);
        self::assertSame(ToolExecutionState::Started, $duplicate->record->state);
        self::assertCount(1, [...$store->read('run-1')]);
    }

    public function test_current_fence_can_commit_after_lease_expiry_without_takeover(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $claim = $store->claimToolExecution(
            $this->request(readOnly: false),
            'worker-a',
            10,
            1_000,
            $this->event('tool.claimed', 'claim-a'),
            [],
        );
        $store->markToolExecutionStarted('tool-key', 'worker-a', $claim->record->fencingToken, 1_001);

        $store->commitToolExecution(
            'tool-key',
            'worker-a',
            $claim->record->fencingToken,
            ToolExecutionState::Completed,
            ['content' => 'done', 'is_error' => false],
            2_000,
            $this->event('tool.completed', 'completed'),
            RunStatus::Running,
            [],
        );

        self::assertSame(ToolExecutionState::Completed, $store->getToolExecution('tool-key')?->state);
    }

    public function test_run_lease_can_renew_after_expiry_when_no_takeover_occurred(): void
    {
        $store = new SqliteRunStateStore($this->database);
        $claim = $store->claimRun('run-1', 'worker-a', 10, 1_000);

        $renewed = $store->renewRun('run-1', 'worker-a', $claim->fencingToken, 10, 2_000);

        self::assertTrue($renewed->acquired);
        self::assertSame($claim->fencingToken, $renewed->fencingToken);
        self::assertSame(2_010, $renewed->leaseExpiresAtMs);
    }

    public function test_schema_mismatch_is_rejected_before_operational_tables_are_created(): void
    {
        $pdo = new \PDO('sqlite:'.$this->database);
        $pdo->exec('CREATE TABLE run_schema_meta (version INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO run_schema_meta (version) VALUES (999)');
        $pdo = null;

        try {
            new SqliteRunStateStore($this->database);
            self::fail('Expected schema mismatch.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('schema mismatch', $error->getMessage());
        }

        $pdo = new \PDO('sqlite:'.$this->database);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('run_events', $tables);
        self::assertNotContains('tool_executions', $tables);
    }

    private function request(bool $readOnly): ToolExecutionRequest
    {
        return new ToolExecutionRequest(
            'tool-key', 'run-1', 'inv-1', 'call-1', 'Example', hash('sha256', '{}'), $readOnly,
        );
    }

    private function event(string $type, string $dedupe): RunEvent
    {
        return RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Tool, $type, $dedupe,
            ['idempotency_key' => 'tool-key'],
        );
    }
}
