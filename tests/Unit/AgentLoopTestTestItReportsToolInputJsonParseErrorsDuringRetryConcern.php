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

trait AgentLoopTestTestItReportsToolInputJsonParseErrorsDuringRetryConcern
{

    public function test_it_reports_tool_input_json_parse_errors_during_retry(): void
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
                    return $this->makeInvalidJsonToolUseProcessor(
                        'Write',
                        'toolu_bad_json',
                        ':{"file_path":"/tmp/demo.txt"}',
                    );
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
                return ToolInputSchema::make(['type' => 'object'], []);
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

        $result = $agent->run('继续创建文件');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $this->assertStringContainsString(
            'Tool input JSON could not be parsed',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Raw input: :{"file_path":"/tmp/demo.txt"}',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Split the file into smaller writes or create it in smaller Bash heredoc chunks.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Do not use Agent or Skill as a fallback for ordinary file creation or editing.',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Prefer a tiny initial Write followed by Edit chunks for long files.',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'If a large multiline payload keeps breaking tool JSON',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Do not use Agent or Skill as a fallback',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_malformed_retry_strips_narration_text_from_assistant_history(): void
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
                    $processor = new StreamProcessor;
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
                        'message' => ['id' => 'msg_bad_json_with_text', 'usage' => []],
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
                        'index' => 0,
                        'content_block' => ['type' => 'text', 'text' => ''],
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
                        'index' => 0,
                        'delta' => ['type' => 'text_delta', 'text' => '我使用Bash来创建文件，避免JSON编码问题。'],
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', [
                        'index' => 0,
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
                        'index' => 1,
                        'content_block' => ['type' => 'tool_use', 'id' => 'toolu_bad_bash_text', 'name' => 'Bash'],
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
                        'index' => 1,
                        'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"cat <<EOF'],
                    ]));
                    $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
                        'delta' => ['stop_reason' => 'tool_use'],
                    ]));

                    return $processor;
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
                return ToolInputSchema::make(['type' => 'object'], []);
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

        $result = $agent->run('继续');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $assistantBlocks = $retryMessages[1]['content'];
        $this->assertCount(1, $assistantBlocks);
        $this->assertSame('tool_use', $assistantBlocks[0]['type']);
    }

    public function test_team_create_malformed_retry_requires_compact_complete_input(): void
    {
        $reflection = new \ReflectionClass(AgentLoop::class);
        $agent = $reflection->newInstanceWithoutConstructor();
        $instruction = $reflection->getMethod('buildMalformedToolRetryInstruction')->invoke(
            $agent,
            [[
                'id' => 'toolu_team',
                'name' => 'TeamCreate',
                'error' => 'Tool input JSON could not be parsed: Control character error.',
            ]],
            2,
        );

        $this->assertStringContainsString('name, task, and members', $instruction);
        $this->assertStringContainsString('omit member prompts', $instruction);
        $this->assertStringContainsString('Do not include literal newlines', $instruction);
    }

    public function test_malformed_retry_categories_keep_json_and_schema_budgets_separate(): void
    {
        $reflection = new \ReflectionClass(AgentLoop::class);
        $agent = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('malformedFailureSignature');

        $json = $method->invoke($agent, [[
            'id' => 'toolu_team',
            'name' => 'TeamCreate',
            'error' => 'Tool input JSON could not be parsed: Control character error.',
        ]]);
        $schema = $method->invoke($agent, [[
            'id' => 'toolu_team',
            'name' => 'TeamCreate',
            'error' => 'Tool input validation failed: The members field is required.',
        ]]);

        $this->assertSame('TeamCreate:json', $json);
        $this->assertSame('TeamCreate:schema', $schema);
    }

    public function test_it_only_replays_failed_tool_calls_during_malformed_retry(): void
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
                    return $this->makeMultiToolUseProcessor([
                        ['id' => 'toolu_read_ok', 'name' => 'Read', 'input' => ['file_path' => '/tmp/example.txt']],
                        ['id' => 'toolu_write_bad', 'name' => 'Write', 'input' => []],
                    ]);
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

        $result = $agent->run('继续创建文件');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $assistantBlocks = array_values(array_filter(
            $retryMessages[1]['content'],
            fn (array $block): bool => ($block['type'] ?? null) === 'tool_use',
        ));
        $this->assertCount(1, $assistantBlocks);
        $this->assertSame('toolu_write_bad', $assistantBlocks[0]['id']);
        $this->assertSame('{}', json_encode($assistantBlocks[0]['input']));
        $this->assertSame('toolu_write_bad', $retryMessages[2]['content'][0]['tool_use_id']);
    }
}
