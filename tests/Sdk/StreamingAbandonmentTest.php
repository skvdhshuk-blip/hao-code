<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Runner;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Tests\TestCase;

class StreamingAbandonmentTest extends TestCase
{
    public function test_runner_abandonment_reaps_early_tool_without_force_closed_fiber_suspend(): void
    {
        $this->requireForkSupport();

        [$loop, $factory] = $this->makeEarlyExecutionRun();
        SdkRuntime::app()->instance(AgentLoopFactory::class, $factory);
        $beforeStreamFiles = $this->streamFiles();

        $messages = Runner::stream(
            new Agent(
                name: 'streaming-cleanup-runner',
                apiKey: 'test-key',
                allowedTools: ['SafeSleepTool'],
                tools: [$this->makeSafeSleepTool()],
            ),
            'start a safe tool',
        );
        $messages->rewind();
        $this->assertSame('turn', $messages->current()->type);
        $messages->next();
        $this->assertSame('tool_start', $messages->current()->type);
        $ownedStreamFiles = array_values(array_diff($this->streamFiles(), $beforeStreamFiles));
        $this->assertCount(1, $ownedStreamFiles);

        unset($messages);
        gc_collect_cycles();

        foreach ($ownedStreamFiles as $ownedStreamFile) {
            $this->assertFileDoesNotExist($ownedStreamFile);
        }
        $this->assertTrue($loop->isAborted());
    }

    public function test_conversation_abandonment_reaps_early_tool_without_force_closed_fiber_suspend(): void
    {
        $this->requireForkSupport();

        [$loop, $factory] = $this->makeEarlyExecutionRun();
        $conversation = new Conversation(
            new HaoCodeConfig(
                apiKey: 'test-key',
                allowedTools: ['SafeSleepTool'],
                tools: [$this->makeSafeSleepTool()],
                ephemeral: true,
            ),
            $factory,
        );
        $beforeStreamFiles = $this->streamFiles();

        $messages = $conversation->stream('start a safe tool');
        $messages->rewind();
        $this->assertSame('turn', $messages->current()->type);
        $messages->next();
        $this->assertSame('tool_start', $messages->current()->type);
        $ownedStreamFiles = array_values(array_diff($this->streamFiles(), $beforeStreamFiles));
        $this->assertCount(1, $ownedStreamFiles);

        unset($messages);
        gc_collect_cycles();

        foreach ($ownedStreamFiles as $ownedStreamFile) {
            $this->assertFileDoesNotExist($ownedStreamFile);
        }
        $this->assertTrue($loop->isAborted());
        $conversation->close();
    }

    public function test_runner_stream_exception_emits_terminal_tool_result_before_error(): void
    {
        $this->requireForkSupport();

        [$loop, $factory] = $this->makeEarlyExecutionRun();
        SdkRuntime::app()->instance(AgentLoopFactory::class, $factory);
        $beforeStreamFiles = $this->streamFiles();

        $messages = [];
        $ownedStreamFiles = [];
        foreach (Runner::stream(
            new Agent(
                name: 'streaming-error-runner',
                apiKey: 'test-key',
                allowedTools: ['SafeSleepTool'],
                tools: [$this->makeSafeSleepTool()],
            ),
            'start a safe tool, then fail',
        ) as $message) {
            $messages[] = $message;
            if ($message->type === 'tool_start') {
                $ownedStreamFiles = array_values(array_diff($this->streamFiles(), $beforeStreamFiles));
            }
        }

        $this->assertCount(1, $ownedStreamFiles);
        $this->assertSame(
            ['turn', 'tool_start', 'tool_result', 'error'],
            array_map(static fn ($message): string => $message->type, $messages),
        );
        $this->assertSame('SafeSleepTool', $messages[2]->toolName);
        $this->assertSame('Tool execution aborted', $messages[2]->toolOutput);
        $this->assertTrue($messages[2]->toolIsError);
        $this->assertSame('stream failed after tool start', $messages[3]->error);
        foreach ($ownedStreamFiles as $ownedStreamFile) {
            $this->assertFileDoesNotExist($ownedStreamFile);
        }
        $this->assertFalse($loop->isAborted());
    }

    public function test_abandoning_after_first_of_two_terminal_events_leaves_no_tool_processes(): void
    {
        $this->requireForkSupport();

        [$loop, $factory] = $this->makeEarlyExecutionRun(toolCount: 2);
        SdkRuntime::app()->instance(AgentLoopFactory::class, $factory);
        $beforeStreamFiles = $this->streamFiles();
        $messages = Runner::stream(
            new Agent(
                name: 'multi-tool-cleanup-runner',
                apiKey: 'test-key',
                allowedTools: ['SafeSleepTool'],
                tools: [$this->makeSafeSleepTool()],
            ),
            'start two safe tools, then fail',
        );

        $ownedStreamFiles = [];
        try {
            $messages->rewind();
            $this->assertSame('turn', $messages->current()->type);

            $messages->next();
            $this->assertSame('tool_start', $messages->current()->type);
            $messages->next();
            $this->assertSame('tool_start', $messages->current()->type);
            $ownedStreamFiles = array_values(array_diff($this->streamFiles(), $beforeStreamFiles));
            $this->assertCount(2, $ownedStreamFiles);

            $messages->next();
            $this->assertSame('tool_result', $messages->current()->type);
            foreach ($ownedStreamFiles as $ownedStreamFile) {
                $this->assertFileDoesNotExist($ownedStreamFile);
            }
        } finally {
            unset($messages);
            gc_collect_cycles();
        }

        foreach ($ownedStreamFiles as $ownedStreamFile) {
            $this->assertFileDoesNotExist($ownedStreamFile);
        }
        $this->assertTrue($loop->isAborted());
    }

    /**
     * @return array{AgentLoop, AgentLoopFactory&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeEarlyExecutionRun(int $toolCount = 1): array
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta,
                ?callable $onToolBlockComplete,
            ) use ($toolCount): never {
                for ($index = 0; $index < $toolCount; $index++) {
                    $onToolBlockComplete?->__invoke([
                        'id' => 'toolu_sdk_fiber_cleanup_'.$index,
                        'name' => 'SafeSleepTool',
                        'input' => [],
                    ], $index);
                }

                throw new \RuntimeException('stream failed after tool start');
            });

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register($this->makeSafeSleepTool());

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(PermissionDecision::allow());

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

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $loop = new AgentLoop(
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

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);

        return [$loop, $factory];
    }

    private function makeSafeSleepTool(): BaseTool
    {
        return new class extends BaseTool {
            public function name(): string
            {
                return 'SafeSleepTool';
            }

            public function description(): string
            {
                return 'A safe tool that sleeps until the abandoned stream kills it.';
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
                sleep(5);

                return ToolResult::success('done');
            }
        };
    }

    /**
     * @return list<string>
     */
    private function streamFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/haocode_stream_*') ?: [];
        sort($files);

        return array_values($files);
    }

    private function requireForkSupport(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork and posix_kill are required for this test.');
        }
    }
}
