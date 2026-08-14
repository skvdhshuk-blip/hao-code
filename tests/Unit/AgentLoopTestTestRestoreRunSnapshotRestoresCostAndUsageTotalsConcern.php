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

trait AgentLoopTestTestRestoreRunSnapshotRestoresCostAndUsageTotalsConcern
{

    public function test_restore_run_snapshot_restores_cost_and_usage_totals(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $loop = $this->makeLoop($queryEngine);

        $loop->restoreRunSnapshot([
            'estimated_cost_usd' => 0.8,
            'total_input_tokens' => 120,
            'total_output_tokens' => 34,
            'total_cache_creation_tokens' => 12,
            'total_cache_read_tokens' => 56,
            'last_turn_input_tokens' => 78,
        ]);

        $this->assertEqualsWithDelta(0.8, $loop->getEstimatedCost(), 0.0001);
        $this->assertSame(120, $loop->getTotalInputTokens());
        $this->assertSame(34, $loop->getTotalOutputTokens());
        $this->assertSame(12, $loop->getCacheCreationTokens());
        $this->assertSame(56, $loop->getCacheReadTokens());
        $this->assertSame(78, $loop->getLastTurnInputTokens());
    }

    public function test_incomplete_plain_text_response_is_retried_before_returning(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncompletePlainTextProcessor('已拿到部分结果，请继续补全剩余内容。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(4, $messages);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('已拿到部分结果，请继续补全剩余内容。', $messages[1]['content'][0]['text']);
        $this->assertSame('user', $messages[2]['role']);
        $this->assertStringContainsString('Continue exactly from where you left off.', $messages[2]['content']);
    }

    public function test_incomplete_response_retry_does_not_repeat_turn_start_callback(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncompletePlainTextProcessor('部分结果'),
                $this->makePlainTextProcessor('已完成'),
            );

        $turns = [];
        $loop = $this->makeLoop($qe);
        $loop->run('继续', onTurnStart: function (int $turn) use (&$turns): void {
            $turns[] = $turn;
        });

        $this->assertSame([1], $turns);
        $this->assertSame(1, $loop->getLastRunTurns());
    }

    public function test_empty_final_response_is_retried_before_returning(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor(''),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);

        $this->assertSame('已完成', $loop->run('继续'));
        $this->assertSame(1, $loop->getLastRunTurns());
    }

    public function test_repeated_empty_final_response_throws_instead_of_returning_empty_success(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor(''),
                $this->makePlainTextProcessor(''),
                $this->makePlainTextProcessor(''),
            );

        $loop = $this->makeLoop($qe);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty final response');

        $loop->run('继续');
    }

    public function test_incomplete_progress_note_is_not_added_to_history_before_retry(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncompletePlainTextProcessor('继续创建前端项目。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Do not narrate progress or announce the next step.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    public function test_narration_only_end_turn_is_retried_before_returning(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor('让我尝试用Python来创建文件。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Take the next concrete action immediately.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    public function test_narration_only_end_turn_is_retried_instead_of_returned(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor('让我先改用 Bash 来创建文件。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Do not narrate progress or announce the next step.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    public function test_abort_sets_is_aborted_flag(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));
        $loop = $this->makeLoop($qe);
        $this->assertFalse($loop->isAborted());
        $loop->abort();
        $this->assertTrue($loop->isAborted());
    }

    public function test_run_resets_aborted_at_start(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $loop->abort();
        // run() should reset aborted and complete normally
        $result = $loop->run('hi');
        $this->assertSame('ok', $result);
    }

    public function test_run_returns_aborted_when_query_is_interrupted_mid_turn(): void
    {
        $processor = $this->createMock(StreamProcessor::class);
        $capturedShouldAbort = null;

        $qe = $this->createMock(QueryEngine::class);
        $loop = null;
        $qe->method('query')->willReturnCallback(function (
            array $systemPrompt,
            array $messages,
            ?callable $onTextDelta = null,
            ?callable $onToolBlockComplete = null,
            ?callable $onThinkingDelta = null,
            ?callable $shouldAbort = null,
        ) use (&$loop, $processor, &$capturedShouldAbort) {
            $capturedShouldAbort = $shouldAbort;
            $loop->abort();

            return $processor;
        });

        $loop = $this->makeLoop($qe);

        $result = $loop->run('please stop');

        $this->assertSame('(aborted)', $result);
        $this->assertNotNull($capturedShouldAbort);
        $this->assertTrue($capturedShouldAbort());
    }

    public function test_run_stops_when_parent_cancellation_token_is_cancelled_during_query(): void
    {
        $parentToken = new CancellationToken;

        try {
            $qe = $this->createMock(QueryEngine::class);
            $qe->expects($this->once())
                ->method('query')
                ->willReturnCallback(function () use ($parentToken): StreamProcessor {
                    $parentToken->cancel();

                    return $this->makePlainTextProcessor('response that must be discarded');
                });

            $hookExecutor = $this->createMock(HookExecutor::class);
            $hookExecutor->expects($this->once())
                ->method('execute')
                ->with('SessionStart', ['session_id' => 'test-session'])
                ->willReturn(new HookResult(true));

            $loop = $this->makeLoop(
                $qe,
                hookExecutor: $hookExecutor,
                cancellationToken: $parentToken->fork(),
            );

            $this->assertSame('(aborted)', $loop->run('please stop'));
            $this->assertTrue($loop->isAborted());
            $this->assertCount(1, $loop->getMessageHistory()->getMessages());
        } finally {
            $parentToken->close();
        }
    }

    public function test_event_pump_abort_prevents_the_next_model_request(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->never())->method('query');

        $loop = $this->makeLoop($qe);
        $loop->appendEventPump(function () use ($loop): void {
            $loop->abort();
        });

        $this->assertSame('(aborted)', $loop->run('stop before model request'));
    }

    public function test_is_aborted_starts_false(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertFalse($loop->isAborted());
    }

    public function test_input_tokens_accumulated_from_processor(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 42, 'output_tokens' => 7]],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'done'],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($processor);

        $loop = $this->makeLoop($qe);
        $loop->run('hello');
        $this->assertSame(42, $loop->getTotalInputTokens());
        $this->assertSame(7, $loop->getTotalOutputTokens());
    }

    public function test_on_turn_start_callback_receives_turn_number(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));

        $turns = [];
        $loop = $this->makeLoop($qe);
        $loop->run('go', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $this->assertSame([1], $turns);
    }

    public function test_cost_limit_stops_loop_and_returns_message(): void
    {
        // Use a CostTracker with an extremely low stop threshold (0.0 = always stop)
        $costTracker = new \HaoCode\Services\Cost\CostTracker(0.0, 0.0);

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnCallback(function () {
            $p = new StreamProcessor;
            $p->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
                'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
            ]));
            $p->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
                'delta' => ['stop_reason' => 'end_turn'],
            ]));
            return $p;
        });

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $sessionManager = $this->createMock(\HaoCode\Services\Session\SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('s');
        $compactor = $this->createMock(\HaoCode\Services\Compact\ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);
        $permissionChecker = $this->createMock(\HaoCode\Services\Permissions\PermissionChecker::class);
        $hookExecutor = $this->createMock(\HaoCode\Services\Hooks\HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new \HaoCode\Services\Hooks\HookResult(true));
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: $costTracker,
            toolRegistry: new \HaoCode\Tools\ToolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $loop->run('hi');
        $this->assertStringContainsString('Cost limit reached', $result);
    }

    public function test_max_turns_exceeded_returns_finalization_response(): void
    {
        // Need a tool-use loop that never terminates on its own to hit max turns.
        // We'll simulate this by having every response be a tool_use stop_reason
        // but we have no tool registered → results are errors → loop continues.
        // Instead, mock the stream processor to always return tool_use but never text.
        // The simplest approach: set maxTurns very low via reflection.
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('response'));

        $loop = $this->makeLoop($qe);

        // Override maxTurns to 1 via reflection
        $ref = new \ReflectionProperty(AgentLoop::class, 'maxTurns');
        $ref->setAccessible(true);
        $ref->setValue($loop, 0); // maxTurns=0 → while(0 < 0) is false immediately

        $result = $loop->run('hi');
        $this->assertSame('response', $result);
    }

    public function test_max_turn_finalization_emits_stop_hook(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->once())
            ->method('query')
            ->willReturn($this->makePlainTextProcessor('response'));

        $hookExecutor = $this->createMock(HookExecutor::class);
        $events = [];
        $hookExecutor->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $event, array $context) use (&$events): HookResult {
                $events[] = [$event, $context];

                return new HookResult(true);
            });

        $loop = $this->makeLoop($qe, hookExecutor: $hookExecutor);
        $ref = new \ReflectionProperty(AgentLoop::class, 'maxTurns');
        $ref->setAccessible(true);
        $ref->setValue($loop, 0);

        $this->assertSame('response', $loop->run('hi'));
        $this->assertSame([
            ['SessionStart', ['session_id' => 'test-session']],
            ['Stop', ['session_id' => 'test-session', 'turn' => 0]],
        ], $events);
    }

    public function test_max_turn_finalization_honors_abort_during_final_request(): void
    {
        $loop = null;
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->once())
            ->method('query')
            ->willReturnCallback(function () use (&$loop): StreamProcessor {
                $loop->abort();

                return $this->makePlainTextProcessor('response that must be discarded');
            });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->expects($this->once())
            ->method('execute')
            ->with('SessionStart', ['session_id' => 'test-session'])
            ->willReturn(new HookResult(true));

        $loop = $this->makeLoop($qe, hookExecutor: $hookExecutor);
        $ref = new \ReflectionProperty(AgentLoop::class, 'maxTurns');
        $ref->setAccessible(true);
        $ref->setValue($loop, 0);

        $this->assertSame('(aborted)', $loop->run('hi'));
        $this->assertCount(1, $loop->getMessageHistory()->getMessages());
    }

    public function test_set_max_turns_rejects_zero_or_negative_values(): void
    {
        $loop = $this->makeLoop($this->createMock(QueryEngine::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxTurns must be >= 1');

        $loop->setMaxTurns(0);
    }
}
