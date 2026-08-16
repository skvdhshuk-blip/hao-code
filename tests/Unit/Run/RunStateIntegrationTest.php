<?php

declare(strict_types=1);

namespace Tests\Unit\Run;

use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Run\DurableToolExecutionCoordinator;
use HaoCode\Services\Run\JsonlRunStateStore;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Run\RunReplayer;
use HaoCode\Services\Run\SqliteRunStateStore;
use HaoCode\Services\Run\ToolExecutionState;
use HaoCode\Services\Run\RunStatus;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;

final class RunStateIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/haocode-run-integration-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_query_engine_records_provider_neutral_model_events_for_offline_replay(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $journal = new RunJournal($store, $store, static fn (): string => 'run-1');
        $journal->beginInvocation('inv-1');
        $provider = new class implements LlmProvider {
            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                yield new StreamEvent('message_start', [
                    'message' => ['id' => 'msg-1', 'model' => 'fixture-model', 'usage' => ['input_tokens' => 3]],
                ]);
                yield new StreamEvent('content_block_start', [
                    'index' => 0,
                    'content_block' => ['type' => 'text', 'text' => 'done'],
                ]);
                yield new StreamEvent('content_block_stop', ['index' => 0]);
                yield new StreamEvent('message_delta', [
                    'delta' => ['stop_reason' => 'end_turn'],
                    'usage' => ['output_tokens' => 1],
                ]);
                yield new StreamEvent('message_stop');
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };
        $spans = [];
        $tracer = $this->capturingTracer($spans);

        $processor = (new QueryEngine(
            $provider,
            new ToolRegistry(),
            tracer: $tracer,
            runJournal: $journal,
        ))->query([], [['role' => 'user', 'content' => 'go']]);
        $events = iterator_to_array($store->read('run-1'));
        $replay = (new RunReplayer($store))->replay('run-1');

        self::assertSame('done', $processor->getAccumulatedText());
        self::assertSame(['model.requested', 'model.completed'], array_column(
            array_map(static fn ($event): array => $event->toArray(), $events),
            'type',
        ));
        self::assertSame('done', $replay->text);
        self::assertSame(3, $replay->usage['input_tokens']);
        self::assertSame(1, $replay->usage['output_tokens']);
        self::assertSame(2, $store->latestCheckpoint('run-1')?->eventSequence);
        self::assertSame('run-1', $spans[0]['attributes']['haocode.run_id']);
        self::assertSame('inv-1', $spans[0]['attributes']['haocode.invocation_id']);
        self::assertSame($events[0]->eventId, $spans[0]['attributes']['haocode.event_id']);
    }

    public function test_durable_tool_claim_precedes_hooks_and_committed_result_is_not_reexecuted(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is unavailable.');
        }
        $store = new SqliteRunStateStore($this->directory.'/state.sqlite');
        $journal = new RunJournal($store, $store, static fn (): string => 'run-1');
        $journal->beginInvocation('inv-1');
        $coordinator = new DurableToolExecutionCoordinator($store, $journal, 'worker-a');
        $calls = 0;
        $tool = new class($calls) extends BaseTool {
            public function __construct(private int &$calls) {}
            public function name(): string { return 'Mutate'; }
            public function description(): string { return 'Mutates once'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object'], []);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                $this->calls++;

                return ToolResult::success('committed');
            }
        };
        $registry = new ToolRegistry();
        $registry->register($tool);
        $permission = $this->createMock(PermissionChecker::class);
        $permission->method('check')->willReturn(\HaoCode\Services\Permissions\PermissionDecision::allow());
        $hookCalls = 0;
        $key = 'tool_'.hash('sha256', "run-1\0inv-1\0call-1");
        $activeKey = $key;
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(
            function () use (&$hookCalls, $store, &$activeKey): HookResult {
                $hookCalls++;
                self::assertSame(ToolExecutionState::Started, $store->getToolExecution($activeKey)?->state);

                return new HookResult(true);
            },
        );
        $spans = [];
        $orchestrator = new ToolOrchestrator(
            $registry,
            $permission,
            $hooks,
            tracer: $this->capturingTracer($spans),
            runJournal: $journal,
            durableToolCoordinator: $coordinator,
        );
        $orchestrator->configureHumanInterrupts(['Mutate' => true], false);
        $block = ['id' => 'call-1', 'name' => 'Mutate', 'input' => []];
        $context = new ToolUseContext($this->directory, 'run-1');

        $review = $orchestrator->prepareHumanReview([$block], $context);
        self::assertArrayHasKey(0, $review['actions']);
        self::assertSame(ToolExecutionState::Interrupted, $store->getToolExecution($key)?->state);
        $first = $orchestrator->executePreparedToolBlock($review['prepared'][0], $context);
        $retry = $orchestrator->executePreparedToolBlock($review['prepared'][0], $context);

        self::assertSame('committed', $first['content']);
        self::assertSame($first, $retry);
        self::assertSame(1, $calls);
        self::assertSame(2, $hookCalls, 'Only the first execution may run pre/post hooks.');
        self::assertSame(ToolExecutionState::Completed, $store->getToolExecution($key)?->state);
        self::assertSame('completed', $store->latestCheckpoint('run-1')?->stateDelta['state']);
        self::assertSame('run-1', $spans[0]['attributes']['haocode.run_id']);
        self::assertSame('inv-1', $spans[0]['attributes']['haocode.invocation_id']);
        self::assertArrayHasKey('haocode.event_id', $spans[0]['attributes']);
        self::assertContains(
            $spans[0]['attributes']['haocode.event_id'],
            array_map(static fn ($event): string => $event->eventId, iterator_to_array($store->read('run-1'))),
        );
        self::assertArrayNotHasKey('haocode.event_id', $spans[1]['attributes']);

        $rejected = ['id' => 'call-2', 'name' => 'Mutate', 'input' => []];
        $activeKey = 'tool_'.hash('sha256', "run-1\0inv-1\0call-2");
        $review = $orchestrator->prepareHumanReview([$rejected], $context);
        $orchestrator->settlePreparedToolBlock(
            $review['prepared'][0],
            ToolExecutionState::Cancelled,
            ['tool_use_id' => 'call-2', 'content' => 'Rejected by human', 'is_error' => true],
        );
        self::assertSame(ToolExecutionState::Cancelled, $store->getToolExecution($activeKey)?->state);
        self::assertSame(1, $calls);

        $disabled = ['id' => 'call-disabled', 'name' => 'Mutate', 'input' => []];
        $activeKey = 'tool_'.hash('sha256', "run-1\0inv-1\0call-disabled");
        $review = $orchestrator->prepareHumanReview([$disabled], $context);
        $disabledTool = new class extends BaseTool {
            public function name(): string { return 'Mutate'; }
            public function description(): string { return 'Disabled'; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isEnabled(): bool { return false; }
            public function call(array $input, ToolUseContext $context): ToolResult { return ToolResult::success('unused'); }
        };
        $registry->replace($disabledTool);
        $orchestrator->settlePreparedToolBlock(
            $review['prepared'][0],
            ToolExecutionState::Cancelled,
            ['tool_use_id' => 'call-disabled', 'content' => 'Rejected by human', 'is_error' => true],
        );
        self::assertSame(ToolExecutionState::Cancelled, $store->getToolExecution($activeKey)?->state);
        $registry->replace($tool);

        $edited = ['id' => 'call-3', 'name' => 'Mutate', 'input' => ['value' => 'before']];
        $activeKey = 'tool_'.hash('sha256', "run-1\0inv-1\0call-3");
        $review = $orchestrator->prepareHumanReview([$edited], $context);
        $editedBlock = $review['prepared'][0];
        $editedBlock['input'] = ['value' => 'after'];
        $review = $orchestrator->prepareHumanReview([$editedBlock], $context, true);
        $editedResult = $orchestrator->executePreparedToolBlock($review['prepared'][0], $context);
        self::assertSame('committed', $editedResult['content']);
        self::assertSame(ToolExecutionState::Completed, $store->getToolExecution($activeKey)?->state);
        self::assertSame(2, $calls);
    }

    public function test_agent_loop_records_run_lifecycle_without_changing_public_result(): void
    {
        $store = new JsonlRunStateStore($this->directory);
        $journal = new RunJournal($store, $store, static fn (): string => 'run-1');
        $query = $this->createMock(QueryEngine::class);
        $query->method('query')->willReturn($this->textProcessor('answer'));
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('getAdvertisedAllowedTools')->willReturn(null);
        $orchestrator->method('hasHumanInterruptsConfigured')->willReturn(false);
        $context = $this->createMock(ContextBuilder::class);
        $context->method('buildSystemPrompt')->willReturn([]);
        $context->method('buildTurnContext')->willReturn('');
        $context->method('getTelemetrySystemPrompt')->willReturn([]);
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);
        $compactor->method('shouldMicroCompact')->willReturn(false);
        $permission = $this->createMock(PermissionChecker::class);
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true));
        $spans = [];
        $loop = new AgentLoop(
            $query,
            $orchestrator,
            $context,
            new MessageHistory(),
            $permission,
            new SessionManager(persistenceEnabled: false),
            $compactor,
            new CostTracker(),
            new ToolRegistry(),
            $hooks,
            tracer: $this->capturingTracer($spans),
            runJournal: $journal,
        );

        $result = $loop->run('question');
        $events = iterator_to_array($store->read('run-1'));
        $replay = (new RunReplayer($store))->replay('run-1');

        self::assertSame('answer', $result);
        self::assertSame(
            ['run.started', 'run.input_recorded', 'run.completed'],
            array_map(static fn ($event): string => $event->type, $events),
        );
        self::assertSame(RunStatus::Completed, $replay->status);
        self::assertSame('answer', $replay->text);
        self::assertSame('question', $replay->messages[0]['content']);
        self::assertSame('run-1', $spans[0]['attributes']['haocode.run_id']);
        self::assertSame($events[0]->invocationId, $spans[0]['attributes']['haocode.invocation_id']);
        self::assertSame($events[1]->eventId, $spans[0]['attributes']['haocode.event_id']);
    }

    /** @param array<int, array{name: string, kind: string, attributes: array<string, mixed>}> $spans */
    private function capturingTracer(array &$spans): PhoenixTracer
    {
        $scope = $this->createStub(ScopeInterface::class);
        $span = $this->createStub(SpanInterface::class);
        $span->method('activate')->willReturn($scope);
        $tracer = $this->createMock(PhoenixTracer::class);
        $tracer->method('startSpan')->willReturnCallback(
            static function (string $name, string $kind, array $attributes) use (&$spans, $span): SpanInterface {
                $spans[] = compact('name', 'kind', 'attributes');

                return $span;
            },
        );
        $tracer->method('setAttribute')->willReturnCallback(
            static function (?SpanInterface $target, string $key, mixed $value) use (&$spans): void {
                $index = array_key_last($spans);
                if ($index !== null) {
                    $spans[$index]['attributes'][$key] = $value;
                }
            },
        );

        return $tracer;
    }

    private function textProcessor(string $text): StreamProcessor
    {
        $processor = new StreamProcessor();
        $processor->processEvent(new StreamEvent('message_start', [
            'message' => ['id' => 'msg-1', 'model' => 'fixture', 'usage' => ['input_tokens' => 1]],
        ]));
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => $text],
        ]));
        $processor->processEvent(new StreamEvent('content_block_stop', ['index' => 0]));
        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 1],
        ]));
        $processor->processEvent(new StreamEvent('message_stop'));

        return $processor;
    }
}
