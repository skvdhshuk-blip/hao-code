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

trait AgentLoopTestTestItRetriesTheTurnWhenTheModelReturnsPlaceholderFileReferencesConcern
{

    public function test_it_retries_the_turn_when_the_model_returns_placeholder_file_references(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeValidToolUseProcessor('Read', 'toolu_placeholder', ['file_path' => ':0']),
                $this->makePlainTextProcessor('已恢复'),
            );

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

            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return ($input['file_path'] ?? null) === ':0'
                    ? 'file_path must include an actual path, not only a line reference like ":12".'
                    : null;
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

        $result = $agent->run('继续修复');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
    }

    public function test_it_retries_the_turn_when_the_model_returns_colon_prefixed_bash_garbage(): void
    {
        $retryMessages = [];

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
            ) use (&$retryMessages) {
                static $queryCount = 0;
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeValidToolUseProcessor('Bash', 'toolu_bad_bash', ['command' => ': > /dev/null 2>&1']);
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
                return 'Bash';
            }

            public function description(): string
            {
                return 'Test bash tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['command']) || ! is_string($input['command']) || $input['command'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The command field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return str_starts_with(ltrim((string) ($input['command'] ?? '')), ':')
                    ? 'command must not start with ":"; that is a shell no-op or malformed placeholder prefix. Run the real command directly.'
                    : null;
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

        $result = $agent->run('继续执行真实命令');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertStringContainsString(
            'do not send shell no-ops or probes such as ": > /dev/null 2>&1" or "true".',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'Never send ":" placeholders or no-op probes like ": > /dev/null 2>&1" or "true"',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Keep Bash commands short and concrete; avoid giant multiline file-generation commands.',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_it_cleans_up_streaming_tools_when_querying_throws_after_tool_start(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for this test.');
        }

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta,
                ?callable $onToolBlockComplete,
            ): never {
                if ($onToolBlockComplete !== null) {
                    $onToolBlockComplete([
                        'id' => 'toolu_cleanup',
                        'name' => 'SafeSleepTool',
                        'input' => [],
                    ], 0);
                }

                throw new \RuntimeException('stream failed after tool start');
            });

        $agent = $this->makeEarlyExecutionLoop($queryEngine, $this->makeSafeSleepTool());

        // Snapshot existing haocode_stream_ IPC files so we can assert that
        // the forked run cleans up after itself even on stream failure.
        $beforeStreamFiles = glob(sys_get_temp_dir() . '/haocode_stream_*') ?: [];

        try {
            $agent->run('请探索这个仓库');
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('stream failed after tool start', $e->getMessage());
            $afterStreamFiles = glob(sys_get_temp_dir() . '/haocode_stream_*') ?: [];
            $this->assertSame(
                $beforeStreamFiles,
                $afterStreamFiles,
                'Streaming IPC temp files must be cleaned up after a stream failure.',
            );
        }
    }

    public function test_it_cleans_up_streaming_tools_when_a_suspended_fiber_is_abandoned(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork and posix_kill are required for this test.');
        }

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta,
                ?callable $onToolBlockComplete,
            ): never {
                $onToolBlockComplete?->__invoke([
                    'id' => 'toolu_fiber_cleanup',
                    'name' => 'SafeSleepTool',
                    'input' => [],
                ], 0);

                \Fiber::suspend('tool-started');

                throw new \RuntimeException('Suspended test fiber resumed unexpectedly.');
            });

        $agent = $this->makeEarlyExecutionLoop($queryEngine, $this->makeSafeSleepTool(5000000));
        $beforeStreamFiles = glob(sys_get_temp_dir().'/haocode_stream_*') ?: [];
        sort($beforeStreamFiles);

        $fiber = new \Fiber(static fn (): string => $agent->run('请探索这个仓库'));
        $this->assertSame('tool-started', $fiber->start());

        $duringStreamFiles = glob(sys_get_temp_dir().'/haocode_stream_*') ?: [];
        $ownedStreamFiles = array_values(array_diff($duringStreamFiles, $beforeStreamFiles));
        $this->assertCount(1, $ownedStreamFiles, 'The suspended query must own one streaming IPC file.');

        unset($fiber);
        gc_collect_cycles();

        foreach ($ownedStreamFiles as $ownedStreamFile) {
            $this->assertFileDoesNotExist(
                $ownedStreamFile,
                'Abandoning the fiber must reap its tool process and remove its IPC file.',
            );
        }
    }

    public function test_tool_use_turn_does_not_produce_consecutive_user_messages(): void
    {
        // Turn 1: model calls an unsafe (queued) tool
        $toolUseProcessor = $this->makeValidToolUseProcessor('Echo', 'toolu_echo_1', []);
        // Turn 2: model responds with end_turn text
        $endTurnProcessor = $this->makePlainTextProcessor('done');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($toolUseProcessor, $endTurnProcessor);

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

        $history = new MessageHistory;

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $history,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new \HaoCode\Services\Cost\CostTracker,
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
        );

        $loop->run('call Echo tool please');

        $messages = $history->getMessagesForApi();

        // Ensure no two consecutive messages have the same role
        for ($i = 1; $i < count($messages); $i++) {
            $this->assertNotSame(
                $messages[$i - 1]['role'],
                $messages[$i]['role'],
                "Consecutive messages at positions " . ($i - 1) . " and {$i} have role '{$messages[$i]['role']}'"
            );
        }
    }
}
