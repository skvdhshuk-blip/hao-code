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

trait AgentLoopTestTestRepeatedIdenticalToolErrorsTriggerOneNoToolFinalizationConcern
{

    public function test_repeated_identical_tool_errors_trigger_one_no_tool_finalization(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(4))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeValidToolUseProcessor('AlwaysFails', 'toolu_fail_1', ['value' => 'same']),
                $this->makeValidToolUseProcessor('AlwaysFails', 'toolu_fail_2', ['value' => 'same']),
                $this->makeValidToolUseProcessor('AlwaysFails', 'toolu_fail_3', ['value' => 'same']),
                $this->makePlainTextProcessor('best final answer'),
            );

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'AlwaysFails',
            static fn (): ToolResult => ToolResult::error('disk full'),
        ));

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(
            \HaoCode\Services\Permissions\PermissionDecision::allow(),
        );
        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);
        $compactor->method('shouldMicroCompact')->willReturn(false);

        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: new ToolOrchestrator($registry, $permissionChecker, $hookExecutor),
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new CostTracker(999.0, 9999.0),
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
        );

        $this->assertSame('best final answer', $loop->run('try it'));
        $this->assertSame(3, $loop->getLastRunTurns());
    }

    public function test_auto_compact_does_not_fire_every_turn_after_first_compact(): void
    {
        // Simulate a session where:
        //   Turn 1: 170k input tokens → above threshold → compact fires
        //   Turn 2: 10k input tokens  → below threshold → compact must NOT fire
        //
        // Bug: if shouldAutoCompact() is called with totalInputTokens (cumulative),
        // turn 2 total = 180k → compact fires again on every subsequent turn.
        // Fix: use lastTurnInputTokens so turn 2 checks 10k (below threshold).

        $compactCallCount = 0;

        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturnCallback(
            function (int $tokens) use (&$compactCallCount): bool {
                // AUTO_COMPACT_THRESHOLD = 167_000
                if ($tokens > 167_000) {
                    $compactCallCount++;
                    return true;
                }
                return false;
            }
        );
        $compactor->method('compact')->willReturn('compacted');

        $turn1Processor = $this->makeProcessorWithTokens(170_000, 'response1');
        $turn2Processor = $this->makeProcessorWithTokens(10_000, 'response2');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($turn1Processor, $turn2Processor);

        $loop = $this->makeLoop($qe, compactor: $compactor);
        $loop->run('first message');
        $loop->run('second message');

        $this->assertSame(1, $compactCallCount,
            'Auto-compact should fire only once (on turn 1 at 170k tokens), not on turn 2 (which had 10k tokens after compaction). ' .
            'If it fired twice, shouldAutoCompact is using the cumulative totalInputTokens instead of lastTurnInputTokens.');
    }

    public function test_it_retries_the_turn_when_the_model_returns_malformed_tool_input(): void
    {
        $retryMessages = [];
        $queryCount = 0;
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeMalformedToolUseProcessor();
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('最终回答');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $costTracker = new CostTracker;

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Test read tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: $costTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('这个代码库是干嘛的');

        $this->assertSame('最终回答', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame(['user', 'assistant', 'user'], array_column($retryMessages, 'role'));
        $this->assertSame(
            '{}',
            json_encode($retryMessages[1]['content'][0]['input']),
        );
        $this->assertIsArray($retryMessages[2]['content']);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertTrue($retryMessages[2]['content'][0]['is_error']);
        $this->assertStringContainsString(
            'Tool input validation failed. This tool call was not executed.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Tool input validation failed: The file_path field is required.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'Retry with corrected tool input only. Do not repeat the same malformed call.',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_it_adds_write_specific_recovery_feedback_before_retrying(): void
    {
        $retryMessages = [];
        $queryCount = 0;

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeValidToolUseProcessor('Write', 'toolu_bad_write', []);
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'Test write tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        if (! isset($input['content']) || ! is_string($input['content'])) {
                            throw new \InvalidArgumentException('Tool input validation failed: The content field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('创建 package.json');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame(['user', 'assistant', 'user'], array_column($retryMessages, 'role'));
        $this->assertIsArray($retryMessages[2]['content']);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertTrue($retryMessages[2]['content'][0]['is_error']);
        $this->assertStringContainsString(
            'Tool input validation failed. This tool call was not executed.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'For Write: include an absolute file_path',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'do not prefix JSON or file contents with stray ":" placeholder text.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'For Write: send a valid JSON object with both absolute file_path and full content strings.',
            $retryMessages[2]['content'][1]['text'],
        );
    }
}
