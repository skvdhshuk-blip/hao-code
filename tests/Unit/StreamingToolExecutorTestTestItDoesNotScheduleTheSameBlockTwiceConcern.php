<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\StreamingToolExecutor;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait StreamingToolExecutorTestTestItDoesNotScheduleTheSameBlockTwiceConcern
{

    public function test_it_does_not_schedule_the_same_block_twice(): void
    {
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'TestTool';
            }

            public function description(): string
            {
                return 'Test tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => [],
                ]);
            }

            public function call(array $input, ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                return \HaoCode\Tools\ToolResult::success('ok');
            }
        });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn([
                'tool_use_id' => 'toolu_123',
                'content' => 'ok',
                'is_error' => false,
            ]);

        $executor = new StreamingToolExecutor($toolOrchestrator, $toolRegistry);
        $executor->setContext(new ToolUseContext('/tmp', 'test-session'), null, null);

        $block = [
            'id' => 'toolu_123',
            'name' => 'TestTool',
            'input' => [],
        ];

        $executor->onToolBlockReady($block, 5);
        $executor->onToolBlockReady($block, 5);

        $results = $executor->collectResults();

        $this->assertCount(1, $results);
        $this->assertSame('toolu_123', $results[0]['tool_use_id']);
    }

    public function test_early_execution_count_returns_count_of_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );

        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock('a'), 0);
        $executor->onToolBlockReady($this->makeBlock('b'), 1);

        $this->assertSame(2, $executor->earlyExecutionCount());
    }

    public function test_pre_tool_use_hook_disables_early_execution(): void
    {
        $expectedResult = ['tool_use_id' => 'toolu_1', 'content' => 'done', 'is_error' => false];
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->with('MockTool')->willReturn(true);
        $orchestrator->expects($this->once())->method('executeToolBlock')->willReturn($expectedResult);

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);

        $this->assertSame(0, $executor->earlyExecutionCount());
        $this->assertSame([$expectedResult], $executor->collectResults());
    }

    public function test_early_classification_uses_backfilled_input(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'ContextSensitiveRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function backfillObservableInput(array $input, ToolUseContext $context): array
            {
                $input['unsafe_after_backfill'] = true;

                return $input;
            }
            public function isReadOnly(array $input): bool
            {
                return ! ($input['unsafe_after_backfill'] ?? false);
            }
            public function isConcurrencySafe(array $input): bool
            {
                return $this->isReadOnly($input);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $expectedResult = ['tool_use_id' => 'context-1', 'content' => 'queued', 'is_error' => false];
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn($expectedResult);

        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(new ToolUseContext('/tmp', 'context-sensitive-stream'), null, null);
        $executor->onToolBlockReady([
            'id' => 'context-1',
            'name' => 'ContextSensitiveRead',
            'input' => [],
        ], 0);

        $this->assertSame(0, $executor->earlyExecutionCount());
        $this->assertSame([$expectedResult], $executor->collectResults());
    }

    public function test_early_execution_validates_and_passes_normalized_input_to_start_callback(): void
    {
        $startedInputs = [];
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'NormalizedPathRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(
                    ['type' => 'object'],
                    ['path' => ['required', 'string']],
                );
            }
            public function backfillObservableInput(array $input, ToolUseContext $context): array
            {
                if (! str_starts_with($input['path'], '/')) {
                    $input['path'] = rtrim($context->workingDirectory, '/').'/'.$input['path'];
                }

                return $input;
            }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('mayRunPermissionPrompts')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete): array {
                $onStart?->__invoke($block['name'], $block['input']);
                $result = ToolResult::success('ok');
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(
            new ToolUseContext('/tmp/stream-normalized', 'stream-normalized'),
            static function (string $toolName, array $input) use (&$startedInputs): void {
                $startedInputs[] = $input;
            },
            null,
        );
        $executor->onToolBlockReady([
            'id' => 'normalized-stream-1',
            'name' => 'NormalizedPathRead',
            'input' => ['path' => 'relative.txt'],
        ], 0);

        $executor->collectResults();

        $this->assertSame([['path' => '/tmp/stream-normalized/relative.txt']], $startedInputs);
    }

    public function test_early_execution_requires_successful_semantic_validation(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'InvalidRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return 'semantic input is invalid';
            }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('must not execute');
            }
        });

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('mayRunPermissionPrompts')->willReturn(false);
        $orchestrator->expects($this->once())->method('executeToolBlock')->willReturn([
            'tool_use_id' => 'invalid-stream-1',
            'content' => 'queued',
            'is_error' => false,
        ]);

        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(new ToolUseContext('/tmp', 'invalid-stream'), null, null);
        $executor->onToolBlockReady([
            'id' => 'invalid-stream-1',
            'name' => 'InvalidRead',
            'input' => [],
        ], 0);

        $this->assertSame(0, $executor->earlyExecutionCount());
        $this->assertSame('queued', $executor->collectResults()[0]['content']);
    }

    public function test_early_execution_is_disabled_when_permission_prompt_may_run(): void
    {
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('mayRunPermissionPrompts')->willReturn(true);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn([
                'tool_use_id' => 'toolu_1',
                'content' => 'queued',
                'is_error' => false,
            ]);

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'prompting-stream'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);

        $this->assertSame(0, $executor->earlyExecutionCount());
        $this->assertSame([
            ['tool_use_id' => 'toolu_1', 'content' => 'queued', 'is_error' => false],
        ], $executor->collectResults());
    }

    public function test_early_execution_has_worker_limit(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for early tool execution.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(static function (array $block): array {
            sleep(2);

            return ['tool_use_id' => $block['id'], 'content' => 'ok', 'is_error' => false];
        });

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        for ($i = 0; $i < 12; $i++) {
            $executor->onToolBlockReady($this->makeBlock('toolu_'.$i), $i);
        }

        $this->assertSame(8, $executor->earlyExecutionCount());
        $executor->cleanup(notifyCompletion: false);
    }

    public function test_early_execution_count_zero_when_no_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );
        $this->assertSame(0, $executor->earlyExecutionCount());
    }

    public function test_read_only_concurrency_safe_tool_registers_early_execution(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        $executor->onToolBlockReady($this->makeBlock(), 0);

        // Should register as an early execution (forked or queued depending on pcntl availability)
        $this->assertTrue($executor->hasEarlyExecutions() || $executor->collectResults() !== []);
    }

    public function test_cleanup_prevents_queued_execution(): void
    {
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->never())->method('executeToolBlock');

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup();
        $executor->collectResults();
    }

    public function test_cleanup_emits_terminal_callback_for_started_early_tool(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for early tool execution.');
        }

        $completed = [];
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = [$name, $result];
            },
        );

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup();

        $this->assertCount(1, $completed);
        $this->assertSame('MockTool', $completed[0][0]);
        $this->assertTrue($completed[0][1]->isError);
        $this->assertSame('Tool execution aborted', $completed[0][1]->output);
    }

    public function test_silent_cleanup_reaps_started_tool_without_completion_callback(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for early tool execution.');
        }

        $completed = [];
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = [$name, $result];
            },
        );

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup(notifyCompletion: false);

        $this->assertSame([], $completed);
        $this->assertSame(0, $executor->earlyExecutionCount());
    }

    public function test_cleanup_terminates_early_child_process_tree(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('pcntl and POSIX process probing are required for process-tree cleanup.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'haocode_stream_tree_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(static function (array $block) use ($marker): array {
            $command = 'sleep 1; printf leaked > '.escapeshellarg($marker);
            $process = proc_open(['sh', '-c', $command], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
                2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            ], $pipes, sys_get_temp_dir());
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            sleep(5);
            if (is_resource($process)) {
                @proc_close($process);
            }

            return ['tool_use_id' => $block['id'], 'content' => 'late', 'is_error' => false];
        });

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);

        usleep(200_000);
        $executor->cleanup(notifyCompletion: false);
        usleep(1_200_000);

        $this->assertFileDoesNotExist($marker, 'Cleanup must terminate external descendants of the forked tool process');
    }
}
