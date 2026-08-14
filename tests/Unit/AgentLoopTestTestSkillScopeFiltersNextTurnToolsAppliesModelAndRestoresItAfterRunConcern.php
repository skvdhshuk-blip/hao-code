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

trait AgentLoopTestTestSkillScopeFiltersNextTurnToolsAppliesModelAndRestoresItAfterRunConcern
{

    public function test_skill_scope_filters_next_turn_tools_applies_model_and_restores_it_after_run(): void
    {
        $settings = new \HaoCode\Services\Settings\SettingsManager('/tmp');
        $settings->set('model', 'parent-model');
        $settings->set('permission_mode', 'bypass_permissions');
        $skillLoader = new \HaoCode\Tools\Skill\SkillLoader('/tmp');
        $skills = new \ReflectionProperty($skillLoader, 'skills');
        $skills->setValue($skillLoader, []);
        $skillLoader->registerSkillDefinition(new \HaoCode\Tools\Skill\SkillDefinition(
            name: 'scoped',
            description: 'Scoped skill',
            whenToUse: null,
            prompt: 'Use Read only.',
            allowedTools: ['Read'],
            model: 'skill-model',
        ));
        $runContext = new \HaoCode\Services\Agent\AgentRunContext(
            '/tmp',
            '/tmp',
            $settings,
            $skillLoader,
            new \HaoCode\Services\Agent\CancellationToken,
        );

        $registry = new ToolRegistry;
        $registry->register(new \HaoCode\Tools\Skill\SkillTool($skillLoader));
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('read')));
        $registry->register($this->makeTool('Bash', fn () => ToolResult::success('bash')));

        $permissionChecker = new \HaoCode\Services\Permissions\PermissionChecker(
            $settings,
            new \HaoCode\Services\Permissions\DenialTracker,
        );
        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));
        $orchestrator = new ToolOrchestrator($registry, $permissionChecker, $hookExecutor);
        $queryCount = 0;
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))->method('query')->willReturnCallback(
            function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
                ?array $toolsOverride = null,
            ) use (&$queryCount, $settings): StreamProcessor {
                $queryCount++;
                $names = array_column($toolsOverride ?? [], 'name');
                if ($queryCount === 1) {
                    $this->assertContains('Bash', $names);

                    return $this->makeValidToolUseProcessor('Skill', 'skill-scope-1', ['skill' => 'scoped']);
                }

                $this->assertSame('skill-model', $settings->getModel());
                $this->assertContains('Skill', $names);
                $this->assertContains('Read', $names);
                $this->assertNotContains('Bash', $names);

                return $this->makePlainTextProcessor('done');
            },
        );

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('skill-scope-session');
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);

        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new CostTracker(999.0, 9999.0),
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
            runContext: $runContext,
        );

        $this->assertSame('done', $loop->run('use scoped'));
        $this->assertSame('parent-model', $settings->getModel());
    }

    public function test_resume_restores_inline_skill_model_for_first_request_and_cleans_up_scope(): void
    {
        $settings = new \HaoCode\Services\Settings\SettingsManager('/tmp');
        $settings->set('model', 'parent-model');
        $settings->set('permission_mode', 'bypass_permissions');
        $runContext = new \HaoCode\Services\Agent\AgentRunContext(
            '/tmp',
            '/tmp',
            $settings,
            new \HaoCode\Tools\Skill\SkillLoader('/tmp'),
            new \HaoCode\Services\Agent\CancellationToken,
        );

        $registry = new ToolRegistry;
        $permissionChecker = new \HaoCode\Services\Permissions\PermissionChecker(
            $settings,
            new \HaoCode\Services\Permissions\DenialTracker,
        );
        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));
        $orchestrator = new ToolOrchestrator($registry, $permissionChecker, $hookExecutor);

        $interrupt = new \HaoCode\Sdk\HumanInterrupt(
            id: 'resume-skill-scope',
            sessionId: 'resume-skill-session',
            actions: [],
            createdAt: '2026-01-01T00:00:00+00:00',
        );
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('resume-skill-session');
        $sessionManager->method('getInterruptState')->willReturn([
            'type' => 'interrupt_pending',
            'interrupt' => $interrupt->toArray(),
        ]);
        $sessionManager->method('claimInterrupt')->willReturn([
            'interrupt' => $interrupt->toArray(),
            'checkpoint' => ['blocks' => [], 'results' => []],
        ]);
        $sessionManager->expects($this->once())
            ->method('resolveInterrupt')
            ->with('resume-skill-scope', []);

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())->method('query')->willReturnCallback(
            function () use ($settings): StreamProcessor {
                $this->assertSame('skill-model', $settings->getModel());

                return $this->makePlainTextProcessor('resumed');
            },
        );
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);

        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new CostTracker(999.0, 9999.0),
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
            runContext: $runContext,
        );
        $loop->restoreRunSnapshot([
            'active_skill_allowed_tools' => ['Read'],
            'active_skill_model_override' => 'skill-model',
            'active_skill_context' => 'inline',
        ]);

        $this->assertSame('resumed', $loop->resumeInterrupt('resume-skill-scope', []));
        $this->assertSame('parent-model', $settings->getModel());
        $this->assertNull($orchestrator->getActiveSkillAllowedTools());
        $this->assertNull($orchestrator->getActiveSkillModelOverride());
    }

    private function makeValidToolUseProcessor(string $toolName, string $toolId, array $input): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_tool', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => json_encode($input),
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', [
            'index' => 0,
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    /**
     * @param array<int, array{id: string, name: string, input: array}> $blocks
     */
    private function makeMultiToolUseProcessor(array $blocks): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_multi_tool', 'usage' => []],
        ]));

        foreach ($blocks as $index => $block) {
            $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
                'index' => $index,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                ],
            ]));
            $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
                'index' => $index,
                'delta' => [
                    'type' => 'input_json_delta',
                    'partial_json' => json_encode($block['input']),
                ],
            ]));
            $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', [
                'index' => $index,
            ]));
        }

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeMalformedToolUseProcessor(): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_bad',
                'name' => 'Read',
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '[]',
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeInvalidJsonToolUseProcessor(string $toolName, string $toolId, string $rawInput): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_bad_json', 'usage' => []],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => $rawInput,
            ],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeProcessorWithTokens(int $inputTokens, string $text): StreamProcessor
    {
        return $this->makeProcessorWithUsage([
            'input_tokens' => $inputTokens,
            'output_tokens' => 1,
        ], $text);
    }

    /** @param array<string, mixed> $usage */
    private function makeProcessorWithUsage(array $usage, string $text): StreamProcessor
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_x', 'usage' => $usage],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('content_block_stop', ['index' => 0]));
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));
        return $processor;
    }

    public function test_total_input_tokens_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0, $loop->getTotalInputTokens());
    }

    public function test_total_output_tokens_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0, $loop->getTotalOutputTokens());
    }

    public function test_get_message_history_returns_history_instance(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $history = $loop->getMessageHistory();
        $this->assertInstanceOf(MessageHistory::class, $history);
    }

    public function test_run_adds_user_and_assistant_messages_to_history(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('response text'));
        $loop = $this->makeLoop($qe);
        $loop->run('test user input');

        $history = $loop->getMessageHistory();
        $messages = $history->getMessagesForApi();
        $this->assertGreaterThanOrEqual(2, count($messages));

        $roles = array_column($messages, 'role');
        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
    }

    public function test_get_estimated_cost_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0.0, $loop->getEstimatedCost());
    }

    public function test_cache_tokens_tracked_from_processor_usage(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_1',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 10,
                    'cache_creation_input_tokens' => 50,
                    'cache_read_input_tokens' => 25,
                ],
            ],
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
        $this->assertSame(50, $loop->getCacheCreationTokens());
        $this->assertSame(25, $loop->getCacheReadTokens());
    }

    public function test_context_input_tokens_drive_metrics_when_cache_usage_is_normalized(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \HaoCode\Services\Api\StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_cache',
                'usage' => [
                    'input_tokens' => 100,
                    'context_input_tokens' => 1000,
                    'output_tokens' => 10,
                    'cache_read_input_tokens' => 900,
                ],
            ],
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

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->method('query')->willReturn($processor);

        $loop = $this->makeLoop($queryEngine);
        $loop->run('hello');

        $this->assertSame(1000, $loop->getLastTurnInputTokens());
        $this->assertSame(1000, $loop->getTotalInputTokens());
        $this->assertSame(900, $loop->getCacheReadTokens());
    }
}
