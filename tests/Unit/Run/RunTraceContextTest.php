<?php

declare(strict_types=1);

namespace Tests\Unit\Run;

use HaoCode\Services\Run\JsonlRunStateStore;
use HaoCode\Services\Run\RunEventPhase;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Telemetry\RunTraceContext;
use PHPUnit\Framework\TestCase;

final class RunTraceContextTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/haocode-run-trace-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_attributes_reuse_canonical_run_invocation_and_event_identities(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $journal = new RunJournal($store, $store, static fn (): string => 'run-1');
        $journal->beginInvocation('inv-1');
        $event = $journal->record(
            RunEventPhase::Model,
            'model.requested',
            [],
            'model-requested',
        );

        self::assertSame([
            'haocode.run_id' => 'run-1',
            'haocode.invocation_id' => 'inv-1',
            'haocode.event_id' => $event->eventId,
        ], RunTraceContext::attributes($journal, $event->eventId));
        self::assertSame([], RunTraceContext::attributes(null, $event->eventId));
    }

    public function test_attributes_do_not_create_an_invocation(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $journal = new RunJournal($store, $store, static fn (): string => 'run-2');

        self::assertSame(['haocode.run_id' => 'run-2'], RunTraceContext::attributes($journal));
        self::assertNull($journal->invocationId());
    }
}
