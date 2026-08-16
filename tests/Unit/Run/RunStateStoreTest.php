<?php

declare(strict_types=1);

namespace Tests\Unit\Run;

use HaoCode\Services\Run\JsonlRunStateStore;
use HaoCode\Services\Run\RunEvent;
use HaoCode\Services\Run\RunEventExporter;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunReplayer;
use HaoCode\Services\Run\RunStatus;
use HaoCode\Services\Run\RunStateStoreFactory;
use PHPUnit\Framework\TestCase;

final class RunStateStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/haocode-run-jsonl-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_jsonl_adapter_assigns_sequence_and_deduplicates_same_fact(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $draft = RunEvent::draft(
            'run-1',
            'inv-1',
            RunEventPhase::Run,
            'run.started',
            'inv-1:started',
        );

        $first = $store->append($draft);
        $same = $store->append($draft);
        $next = $store->append(RunEvent::draft(
            'run-1',
            'inv-1',
            RunEventPhase::Run,
            'run.completed',
            'inv-1:completed',
            ['text' => 'done'],
            $first->eventId,
        ));

        self::assertSame(1, $first->sequence);
        self::assertSame($first->eventId, $same->eventId);
        self::assertSame(2, $next->sequence);
        self::assertCount(2, [...$store->read('run-1')]);
    }

    public function test_jsonl_adapter_rejects_conflicting_dedupe_fact(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.started', 'same', ['value' => 1],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dedupe conflict');
        $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.started', 'same', ['value' => 2],
        ));
    }

    public function test_jsonl_incremental_index_observes_other_writer_tail(): void
    {
        $left = new JsonlRunStateStore($this->directory);
        $right = new JsonlRunStateStore($this->directory);
        $firstDraft = RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.started', 'first',
        );

        $first = $left->append($firstDraft);
        $same = $right->append($firstDraft);
        $second = $right->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.progressed', 'second',
        ));
        $third = $left->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.completed', 'third',
        ));

        self::assertSame($first->eventId, $same->eventId);
        self::assertSame(2, $second->sequence);
        self::assertSame(3, $third->sequence);
        self::assertCount(3, [...$left->read('run-1')]);
    }

    public function test_jsonl_incremental_index_fails_closed_on_corrupt_tail(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.started', 'first',
        ));
        file_put_contents($this->directory.'/run-1.jsonl', "{broken}\n", FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains invalid JSON');
        $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.completed', 'second',
        ));
    }

    public function test_export_and_replay_are_read_only_consumers(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $started = $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.started', 'started',
        ));
        $model = $store->append(RunEvent::draft(
            'run-1',
            'inv-1',
            RunEventPhase::Model,
            'model.completed',
            'model-1',
            [
                'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'done']]],
                'text' => 'done',
                'usage' => ['input_tokens' => 4, 'output_tokens' => 2],
            ],
            $started->eventId,
        ));
        $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.completed', 'completed', ['text' => 'done'], $model->eventId,
        ));

        $before = file_get_contents($this->directory.'/run-1.jsonl');
        $export = (new RunEventExporter($store))->export('run-1');
        $replay = (new RunReplayer($store))->replay('run-1');

        self::assertSame($before, file_get_contents($this->directory.'/run-1.jsonl'));
        self::assertStringContainsString('"schema_version":1', $export);
        self::assertSame(RunStatus::Completed, $replay->status);
        self::assertSame('done', $replay->text);
        self::assertSame(4, $replay->usage['input_tokens']);
    }

    public function test_export_redacts_content_while_replay_retains_the_recorded_facts(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $started = $store->append(RunEvent::draft(
            'run-secret', 'inv-1', RunEventPhase::Run, 'run.started', 'started',
        ));
        $input = $store->append(RunEvent::draft(
            'run-secret',
            'inv-1',
            RunEventPhase::Run,
            'run.input_recorded',
            'input',
            ['message' => ['role' => 'user', 'content' => 'user-secret']],
            $started->eventId,
        ));
        $model = $store->append(RunEvent::draft(
            'run-secret',
            'inv-1',
            RunEventPhase::Model,
            'model.completed',
            'model',
            [
                'attempt_id' => 'model-1',
                'message' => ['role' => 'assistant', 'content' => 'model-secret'],
                'text' => 'model-secret',
                'usage' => ['input_tokens' => 7, 'output_tokens' => 3, 'future_metric' => 'metric-secret'],
                'result' => ['content' => 'tool-secret'],
                'error' => 'error-secret',
                'future_content' => 'future-secret',
            ],
            $input->eventId,
        ));
        $store->append(RunEvent::draft(
            'run-secret',
            'inv-1',
            RunEventPhase::Run,
            'run.completed',
            'completed',
            ['text' => 'model-secret', 'turns' => 1],
            $model->eventId,
        ));

        $export = (new RunEventExporter($store))->export('run-secret');
        $replay = (new RunReplayer($store))->replay('run-secret');

        self::assertStringNotContainsString('user-secret', $export);
        self::assertStringNotContainsString('model-secret', $export);
        self::assertStringNotContainsString('tool-secret', $export);
        self::assertStringNotContainsString('error-secret', $export);
        self::assertStringNotContainsString('future-secret', $export);
        self::assertStringNotContainsString('metric-secret', $export);
        self::assertStringContainsString('"message":"[redacted]"', $export);
        self::assertStringContainsString('"text":"[redacted]"', $export);
        self::assertStringContainsString('"result":"[redacted]"', $export);
        self::assertStringContainsString('"error":"[redacted]"', $export);
        self::assertStringContainsString('"future_content":"[redacted]"', $export);
        self::assertStringContainsString('"attempt_id":"model-1"', $export);
        self::assertStringContainsString('"input_tokens":7', $export);
        self::assertStringContainsString('"future_metric":"[redacted]"', $export);
        self::assertSame('user-secret', $replay->messages[0]['content']);
        self::assertSame('model-secret', $replay->text);
    }

    public function test_replay_reports_the_latest_invocation_as_running(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $first = $store->append(RunEvent::draft(
            'run-1', 'inv-1', RunEventPhase::Run, 'run.completed', 'first-complete', ['text' => 'old'],
        ));
        $store->append(RunEvent::draft(
            'run-1', 'inv-2', RunEventPhase::Run, 'run.started', 'second-start', [], $first->eventId,
        ));

        $replay = (new RunReplayer($store))->replay('run-1');

        self::assertSame(RunStatus::Running, $replay->status);
        self::assertNull($replay->text);
    }

    public function test_store_factory_defaults_to_jsonl_and_rejects_unknown_drivers(): void
    {
        self::assertInstanceOf(
            JsonlRunStateStore::class,
            RunStateStoreFactory::make('jsonl', $this->directory),
        );

        $this->expectException(\InvalidArgumentException::class);
        RunStateStoreFactory::make('unknown', $this->directory);
    }
}
