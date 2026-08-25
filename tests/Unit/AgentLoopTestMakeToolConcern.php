<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Agent\ToolCall;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

trait AgentLoopTestMakeToolConcern
{

    private function makeTool(string $name, callable $call): BaseTool
    {
        return new class($name, $call) extends BaseTool {
            public function __construct(private string $n, private $fn) {}
            public function name(): string { return $this->n; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ($this->fn)($input, $ctx); }
        };
    }

    private function makeSafeSleepTool(int $microseconds = 500000): BaseTool
    {
        return new class($microseconds) extends BaseTool {
            public function __construct(private readonly int $microseconds) {}

            public function name(): string
            {
                return 'SafeSleepTool';
            }

            public function description(): string
            {
                return 'A safe tool that sleeps briefly.';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => [],
                ]);
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                usleep($this->microseconds);

                return ToolResult::success('done');
            }
        };
    }

    private function makeEarlyExecutionLoop(QueryEngine $queryEngine, BaseTool $tool): AgentLoop
    {
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register($tool);

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(\HaoCode\Services\Permissions\PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $toolOrchestrator = new ToolOrchestrator(
            toolRegistry: $toolRegistry,
            permissionChecker: $permissionChecker,
            hookExecutor: $hookExecutor,
        );

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        return new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );
    }

    private function makeLoop(
        QueryEngine $queryEngine,
        ?ToolRegistry $registry = null,
        ?ContextCompactor $compactor = null,
        ?SessionManager $sessionManager = null,
        ?ContextBuilder $contextBuilder = null,
        ?HookExecutor $hookExecutor = null,
        ?CancellationToken $cancellationToken = null,
    ): AgentLoop
    {
        if ($contextBuilder === null) {
            $contextBuilder = $this->createMock(ContextBuilder::class);
            $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        }

        $sessionManager ??= $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');

        $permissionChecker = $this->createMock(PermissionChecker::class);

        if ($hookExecutor === null) {
            $hookExecutor = $this->createMock(HookExecutor::class);
            $hookExecutor->method('execute')->willReturn(new HookResult(true));
        }

        $compactor ??= $this->createMock(ContextCompactor::class);
        if (!$compactor instanceof \PHPUnit\Framework\MockObject\MockObject) {
            // real object, do nothing
        } else {
            $compactor->method('shouldAutoCompact')->willReturn(false);
        }

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);

        return new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new CostTracker(999.0, 9999.0),
            toolRegistry: $registry ?? new ToolRegistry,
            hookExecutor: $hookExecutor,
            cancellationToken: $cancellationToken,
        );
    }

    private function makePlainEndTurnProcessor(string $text): StreamProcessor
    {
        return $this->makePlainTextProcessor($text);
    }

    public function test_simple_end_turn_returns_accumulated_text(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('Hello there'));

        $loop = $this->makeLoop($qe);
        $result = $loop->run('hi');
        $this->assertSame('Hello there', $result);
    }

    public function test_session_reuses_byte_stable_system_prompt_and_appends_initial_context_once(): void
    {
        $captured = [];
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (array $systemPrompt, array $messages) use (&$captured): StreamProcessor {
                $captured[] = compact('systemPrompt', 'messages');

                return $this->makePlainTextProcessor('done');
            });

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->expects($this->once())
            ->method('buildSystemPrompt')
            ->willReturn([['type' => 'text', 'text' => 'stable-prefix']]);
        $contextBuilder->expects($this->once())
            ->method('buildTurnContext')
            ->willReturn("# Git Status\n M app/Foo.php");

        $loop = $this->makeLoop($queryEngine, contextBuilder: $contextBuilder);
        $loop->run('first request');
        $loop->run('second request');

        $this->assertSame($captured[0]['systemPrompt'], $captured[1]['systemPrompt']);
        $this->assertSame('stable-prefix', $captured[0]['systemPrompt'][0]['text']);
        $this->assertSame(
            $captured[0]['messages'],
            array_slice($captured[1]['messages'], 0, count($captured[0]['messages'])),
            'The second request must preserve the complete first request history as a byte-stable prefix.',
        );
        $this->assertStringContainsString('# Initial workspace context', $captured[0]['messages'][0]['content'][0]['text']);
        $this->assertSame('first request', $captured[0]['messages'][0]['content'][1]['text']);
        $this->assertSame('second request', $captured[1]['messages'][2]['content']);
    }

    public function test_resumed_session_skips_first_turn_context_and_session_start_side_effects(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (array $systemPrompt, array $messages): StreamProcessor {
                $this->assertSame([], $systemPrompt);
                $this->assertSame([
                    ['role' => 'user', 'content' => 'continue the loaded session'],
                ], $messages);

                return $this->makePlainTextProcessor('continued');
            });
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $contextBuilder->expects($this->never())->method('buildTurnContext');
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('resumed-session');
        $sessionManager->method('isPersistenceEnabled')->willReturn(false);
        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $event): HookResult {
                $this->assertSame('Stop', $event);

                return new HookResult(true);
            });

        $loop = $this->makeLoop(
            $queryEngine,
            sessionManager: $sessionManager,
            contextBuilder: $contextBuilder,
            hookExecutor: $hookExecutor,
        );
        $loop->markSessionResumed();

        $this->assertSame('continued', $loop->run('continue the loaded session'));
    }

    public function test_resumed_durable_session_rebinds_tool_result_storage(): void
    {
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->once())
            ->method('setToolResultStorage')
            ->with($this->isInstanceOf(ToolResultStorage::class));
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('canonical-session');
        $sessionManager->method('isPersistenceEnabled')->willReturn(true);
        $sessionManager->method('getSessionPath')->willReturn(
            sys_get_temp_dir().'/haocode-agent-loop-sessions',
        );

        $loop = new AgentLoop(
            queryEngine: $this->createMock(QueryEngine::class),
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $this->createMock(ContextBuilder::class),
            messageHistory: new MessageHistory,
            permissionChecker: $this->createMock(PermissionChecker::class),
            sessionManager: $sessionManager,
            contextCompactor: $this->createMock(ContextCompactor::class),
            costTracker: new CostTracker,
            toolRegistry: new ToolRegistry,
        );

        $loop->markSessionResumed();
    }

    public function test_external_event_pump_runs_before_each_agent_turn(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainEndTurnProcessor('done'));
        $pumps = 0;
        $loop = $this->makeLoop($qe);
        $loop->setEventPump(function () use (&$pumps): void {
            $pumps++;
        });

        $this->assertSame('done', $loop->run('hi'));
        $this->assertSame(1, $pumps);
    }

    public function test_aggregate_budget_compaction_revokes_only_replaced_read_receipts(): void
    {
        $root = sys_get_temp_dir().'/haocode-aggregate-read-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $oldSessionPath = SdkRuntime::config('haocode.session_path');
        SdkRuntime::config(['haocode.session_path' => $root.'/sessions']);
        $context = new ToolUseContext($root, 'aggregate-read');
        $toolCalls = [];
        $before = [];

        try {
            for ($index = 1; $index <= 4; $index++) {
                $id = "read-{$index}";
                $relativePath = "file-{$index}.txt";
                $path = $root.'/'.$relativePath;
                $content = str_repeat((string) $index, 35_000);
                file_put_contents($path, $content);
                $context->recordFileRead($path, $content);
                $toolCalls[] = new ToolCall($id, 'Read', ['file_path' => $relativePath]);
                $before[] = [
                    'tool_use_id' => $id,
                    'content' => $content,
                    'is_error' => false,
                ];
            }

            $storage = new ToolResultStorage(
                sys_get_temp_dir().'/haocode-agent-loop-tool-results',
                'aggregate-read',
            );
            $after = $storage->enforceMessageBudget($before);
            \HaoCode\Services\Agent\ReadReceiptVisibility::invalidate(
                $toolCalls,
                $before,
                $after,
                $context,
            );

            $afterById = [];
            foreach ($after as $result) {
                $afterById[$result['tool_use_id']] = $result['content'];
            }
            $compactedIds = [];
            foreach ($before as $result) {
                if ($afterById[$result['tool_use_id']] !== $result['content']) {
                    $compactedIds[] = $result['tool_use_id'];
                }
            }

            $this->assertNotEmpty($compactedIds);
            foreach ($toolCalls as $toolCall) {
                $this->assertSame(
                    ! in_array($toolCall->id, $compactedIds, true),
                    $context->wasFileRead($root.'/'.$toolCall->input['file_path']),
                );
            }
        } finally {
            SdkRuntime::config(['haocode.session_path' => $oldSessionPath]);
            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($root);
            }
        }
    }

    public function test_preflight_budget_rejects_oversized_first_request_before_sampling(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->never())->method('query');

        $loop = $this->makeLoop($queryEngine);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe context budget');

        $loop->run(str_repeat('x', 700_000));
    }

    public function test_simple_end_turn_records_final_assistant_turn(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('Hello there'));

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->expects($this->once())
            ->method('recordTurn')
            ->with(
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello there']]],
                [],
            );

        $loop = $this->makeLoop($qe, sessionManager: $sessionManager);
        $loop->run('hi');
    }

    public function test_durable_multimodal_input_is_persisted_without_placeholder_replacement(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->method('query')->willReturn($this->makePlainTextProcessor('done'));
        $content = [
            ['type' => 'text', 'text' => 'Inspect this image'],
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => 'aGVsbG8=',
                ],
            ],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->expects($this->once())
            ->method('recordEntry')
            ->with([
                'type' => 'user_message',
                'content' => $content,
            ]);

        $loop = $this->makeLoop($queryEngine, sessionManager: $sessionManager);

        $this->assertSame('done', $loop->run($content));
    }

    public function test_failed_user_message_persistence_does_not_mutate_in_memory_history(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->never())->method('query');

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry')->willThrowException(
            new \RuntimeException('disk full'),
        );

        $loop = $this->makeLoop($queryEngine, sessionManager: $sessionManager);

        try {
            $loop->run('must persist first');
            $this->fail('Expected user transcript persistence failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame([], $loop->getMessageHistory()->getMessages());
    }

    public function test_failed_assistant_persistence_poison_closes_the_loop_for_future_turns(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturn($this->makePlainTextProcessor('model completed'));

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordTurn')->willThrowException(
            new \RuntimeException('disk full'),
        );

        $loop = $this->makeLoop($queryEngine, sessionManager: $sessionManager);

        try {
            $loop->run('first turn');
            $this->fail('Expected assistant transcript persistence failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('may have completed', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot continue');

        $loop->run('second turn');
    }
}
