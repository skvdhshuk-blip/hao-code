<?php

declare(strict_types=1);

use HaoCode\Services\Run\RunEvent;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunStatus;
use HaoCode\Services\Run\SqliteRunStateStore;
use HaoCode\Services\Run\ToolExecutionRequest;
use HaoCode\Services\Run\ToolExecutionState;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "usage: worker.php <mode> <scenario> <run-store> <effect-store> <ready-file>\n");
    exit(64);
}

[$script, $mode, $scenario, $runStorePath, $effectStorePath, $readyFile] = $argv;
if (! in_array($scenario, ['after_claim', 'after_effect', 'after_commit'], true)) {
    fwrite(STDERR, "unknown scenario\n");
    exit(64);
}

$runId = 'process-recovery-'.$scenario;
$request = new ToolExecutionRequest(
    idempotencyKey: 'tool-'.$scenario,
    runId: $runId,
    invocationId: 'inv-1',
    toolUseId: 'call-1',
    toolName: 'ExternalCounter',
    inputHash: hash('sha256', '{}'),
    readOnly: false,
);
$store = new SqliteRunStateStore($runStorePath);

$event = static function (string $type, string $owner) use ($request): RunEvent {
    return RunEvent::draft(
        $request->runId,
        $request->invocationId,
        RunEventPhase::Tool,
        $type,
        $request->idempotencyKey.':'.$type.':'.$owner,
        ['idempotency_key' => $request->idempotencyKey],
    );
};

$incrementEffect = static function () use ($effectStorePath): int {
    $effects = new PDO('sqlite:'.$effectStorePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $effects->exec('CREATE TABLE IF NOT EXISTS effects (id INTEGER PRIMARY KEY, executions INTEGER NOT NULL)');
    $effects->exec('INSERT OR IGNORE INTO effects (id, executions) VALUES (1, 0)');
    $effects->exec('BEGIN IMMEDIATE');
    try {
        $effects->exec('UPDATE effects SET executions = executions + 1 WHERE id = 1');
        $count = (int) $effects->query('SELECT executions FROM effects WHERE id = 1')->fetchColumn();
        $effects->exec('COMMIT');

        return $count;
    } catch (Throwable $error) {
        $effects->exec('ROLLBACK');
        throw $error;
    }
};

$effectCount = static function () use ($effectStorePath): int {
    if (! is_file($effectStorePath)) {
        return 0;
    }
    $effects = new PDO('sqlite:'.$effectStorePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $value = $effects->query("SELECT executions FROM effects WHERE id = 1")->fetchColumn();

    return $value === false ? 0 : (int) $value;
};

if ($mode === 'owner') {
    $lease = $store->claimRun($runId, 'worker-a', 100, 1_000);
    if (! $lease->acquired) {
        throw new RuntimeException('The first worker could not claim the run.');
    }
    $claim = $store->claimToolExecution(
        $request,
        'worker-a',
        100,
        1_000,
        $event('tool.claimed', 'worker-a'),
        ['boundary' => 'before_effect'],
    );
    if (! $claim->execute) {
        throw new RuntimeException('The first worker could not claim the tool execution.');
    }

    if ($scenario !== 'after_claim') {
        $store->markToolExecutionStarted(
            $request->idempotencyKey,
            'worker-a',
            $claim->record->fencingToken,
            1_001,
        );
        $incrementEffect();
    }
    if ($scenario === 'after_commit') {
        $store->commitToolExecution(
            $request->idempotencyKey,
            'worker-a',
            $claim->record->fencingToken,
            ToolExecutionState::Completed,
            ['content' => 'committed', 'is_error' => false],
            1_002,
            $event('tool.completed', 'worker-a'),
            RunStatus::Running,
            ['boundary' => 'after_result'],
        );
    }

    file_put_contents($readyFile, json_encode([
        'pid' => getmypid(),
        'scenario' => $scenario,
        'fencing_token' => $claim->record->fencingToken,
        'effect_count' => $effectCount(),
    ], JSON_THROW_ON_ERROR));

    while (true) {
        usleep(100_000);
    }
}

if ($mode !== 'recover') {
    fwrite(STDERR, "unknown mode\n");
    exit(64);
}

$lease = $store->claimRun($runId, 'worker-b', 100, 1_101);
$claim = $store->claimToolExecution(
    $request,
    'worker-b',
    100,
    1_101,
    $event('tool.claimed', 'worker-b'),
    ['boundary' => 'recovered'],
);
if ($claim->execute) {
    $store->markToolExecutionStarted(
        $request->idempotencyKey,
        'worker-b',
        $claim->record->fencingToken,
        1_102,
    );
    $incrementEffect();
    $store->commitToolExecution(
        $request->idempotencyKey,
        'worker-b',
        $claim->record->fencingToken,
        ToolExecutionState::Completed,
        ['content' => 'recovered', 'is_error' => false],
        1_103,
        $event('tool.completed', 'worker-b'),
        RunStatus::Running,
        ['boundary' => 'after_recovery'],
    );
}

fwrite(STDOUT, json_encode([
    'run_acquired' => $lease->acquired,
    'run_fencing_token' => $lease->fencingToken,
    'execute' => $claim->execute,
    'tool_state' => $store->getToolExecution($request->idempotencyKey)?->state->value,
    'tool_fencing_token' => $store->getToolExecution($request->idempotencyKey)?->fencingToken,
    'effect_count' => $effectCount(),
], JSON_THROW_ON_ERROR));
