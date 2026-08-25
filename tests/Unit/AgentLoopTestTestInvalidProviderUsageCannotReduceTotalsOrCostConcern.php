<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentFinalResponseCoordinator;
use HaoCode\Services\Agent\AgentTranscriptLifecycle;
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

trait AgentLoopTestTestInvalidProviderUsageCannotReduceTotalsOrCostConcern
{

    public function test_model_text_that_equals_legacy_abort_marker_is_a_normal_completion(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->method('query')->willReturn($this->makePlainTextProcessor('(aborted)'));

        $outcome = $this->makeLoop($queryEngine)->runOutcome('return the marker literally');

        $this->assertSame('(aborted)', $outcome->text);
        $this->assertSame(
            \HaoCode\Contracts\RunTerminationReason::Normal,
            $outcome->terminationReason,
        );
        $this->assertSame(\HaoCode\Services\Run\RunStatus::Completed, $outcome->status);
    }

    public function test_invalid_provider_usage_cannot_reduce_totals_or_cost(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->method('query')->willReturnOnConsecutiveCalls(
            $this->makeProcessorWithUsage([
                'input_tokens' => 12,
                'context_input_tokens' => -1,
                'output_tokens' => -4,
                'cache_creation_input_tokens' => 'invalid',
                'cache_read_input_tokens' => -8,
            ], 'first'),
            $this->makeProcessorWithUsage([
                'input_tokens' => -12,
                'context_input_tokens' => ['invalid'],
                'output_tokens' => '-3',
                'cache_creation_input_tokens' => -2,
                'cache_read_input_tokens' => 'not-a-number',
            ], 'second'),
        );
        $loop = $this->makeLoop($queryEngine);

        $loop->run('first request');
        $costAfterFirst = $loop->getEstimatedCost();
        $this->assertSame(12, $loop->getTotalInputTokens());
        $this->assertSame(12, $loop->getLastTurnInputTokens());
        $this->assertSame(0, $loop->getTotalOutputTokens());
        $this->assertSame(0, $loop->getCacheCreationTokens());
        $this->assertSame(0, $loop->getCacheReadTokens());
        $this->assertGreaterThan(0.0, $costAfterFirst);

        $loop->run('second request');

        $this->assertSame(12, $loop->getTotalInputTokens());
        $this->assertSame(0, $loop->getLastTurnInputTokens());
        $this->assertSame($costAfterFirst, $loop->getEstimatedCost());
    }

    public function test_on_turn_start_receives_turn_number_one_for_single_turn(): void
    {
        // onTurnStart receives the turn number within a single run() call.
        // For a simple end_turn response (no tool use), there is exactly 1 turn.
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));

        $turns = [];
        $loop = $this->makeLoop($qe);
        $loop->run('first', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $loop->run('second', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $loop->run('third', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });

        // Each run() resets turnCount to 0, so each call starts at turn 1
        $this->assertSame([1, 1, 1], $turns);
    }

    public function test_on_turn_start_receives_incrementing_turn_numbers_within_multi_turn_run(): void
    {
        // When tool_use responses cause multiple turns within one run(),
        // onTurnStart should receive incrementing numbers.
        $turn1Processor = $this->makeValidToolUseProcessor('Echo', 'toolu_1', []);
        $turn2Processor = $this->makePlainTextProcessor('final answer');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($turn1Processor, $turn2Processor);

        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('echoed')));

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(\HaoCode\Services\Permissions\PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $orchestrator = new \HaoCode\Services\Agent\ToolOrchestrator($registry, $permissionChecker, $hookExecutor);

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');

        $compactor = $this->createMock(\HaoCode\Services\Compact\ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new \HaoCode\Services\Cost\CostTracker,
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
        );

        $turns = [];
        $loop->run('call Echo then answer', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });

        $this->assertSame([1, 2], $turns);
    }

    public function test_turn_limit_finalization_disables_tools_and_returns_an_answer(): void
    {
        $toolsOverride = null;
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
                ?array $override = null,
            ) use (&$toolsOverride): StreamProcessor {
                $toolsOverride = $override;

                return $this->makePlainTextProcessor('evidence-backed final answer');
            });

        $history = new MessageHistory;
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('getTelemetrySystemPrompt')->willReturn([]);
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('getSessionId')->willReturn('turn-limit-test');
        $transcript = new AgentTranscriptLifecycle(
            $sessions,
            $this->createMock(ToolOrchestrator::class),
        );
        $outcome = (new AgentFinalResponseCoordinator)->finalize(
            systemPrompt: [],
            onTextDelta: null,
            onThinkingDelta: null,
            reason: null,
            maxTurns: 50,
            maxInputTokens: 100000,
            lastRunTurns: 50,
            compactor: $this->createMock(ContextCompactor::class),
            history: $history,
            queryEngine: $qe,
            contextBuilder: $contextBuilder,
            costTracker: new CostTracker,
            transcript: $transcript,
            hooks: null,
            sessions: $sessions,
            isCancelled: static fn (): bool => false,
            normalizeUsage: static fn (array $usage): array => $usage,
            recordUsage: static function (array $usage): void {},
        );

        $this->assertSame([], $toolsOverride);
        $this->assertSame('evidence-backed final answer', $outcome->text);
        $this->assertSame(\HaoCode\Contracts\RunTerminationReason::TurnLimit, $outcome->terminationReason);
    }

    private function makePlainTextProcessor(string $text): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_2', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'text',
                'text' => '',
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'text_delta',
                'text' => $text,
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', [
            'index' => 0,
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        return $processor;
    }

    private function makeThinkingOnlyProcessor(string $thinking): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_thinking_only', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => $thinking],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', [
            'index' => 0,
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        return $processor;
    }

    private function makeIncompletePlainTextProcessor(string $text): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_incomplete', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'text',
                'text' => '',
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'text_delta',
                'text' => $text,
            ],
        ]));

        return $processor;
    }
}
