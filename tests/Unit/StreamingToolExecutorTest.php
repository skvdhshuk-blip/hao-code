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

class StreamingToolExecutorTest extends TestCase
{
    // ─── helpers ──────────────────────────────────────────────────────────

    private function makeRegistry(bool $readOnly = false, bool $concurrencySafe = false): ToolRegistry
    {
        $registry = new ToolRegistry;
        $tool = new class($readOnly, $concurrencySafe) extends BaseTool {
            public function __construct(private bool $ro, private bool $cs) {}
            public function name(): string { return 'MockTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success('ok'); }
            public function isReadOnly(array $input): bool { return $this->ro; }
            public function isConcurrencySafe(array $input): bool { return $this->cs; }
        };
        $registry->register($tool);
        return $registry;
    }

    private function makeBlock(string $id = 'toolu_1'): array
    {
        return ['id' => $id, 'name' => 'MockTool', 'input' => []];
    }

    private function makeOrchestratorWithResult(array $result): ToolOrchestrator
    {
        $mock = $this->createMock(ToolOrchestrator::class);
        $mock->method('executeToolBlock')->willReturn($result);
        return $mock;
    }

    // ─── no context set — ignores blocks ──────────────────────────────────

    public function test_without_context_blocks_are_ignored(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $results = $executor->collectResults();
        $this->assertEmpty($results);
    }

    // ─── unsafe tools queued for sequential execution ─────────────────────

    public function test_unsafe_tool_queued_and_executed_in_collect(): void
    {
        $expectedResult = ['tool_use_id' => 'toolu_1', 'content' => 'done', 'is_error' => false];

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn($expectedResult);

        // Non-read-only tool → always queued
        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry(readOnly: false));
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $results = $executor->collectResults();

        $this->assertCount(1, $results);
        $this->assertSame('done', $results[0]['content']);
    }

    public function test_read_only_streaming_tool_after_stateful_tool_waits_for_the_barrier(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for streaming early-execution coverage.');
        }

        $stateFile = tempnam(sys_get_temp_dir(), 'haocode-stream-barrier-');
        $this->assertNotFalse($stateFile);
        @unlink($stateFile);

        $registry = new ToolRegistry;
        $registry->register(new class($stateFile) extends BaseTool {
            public function __construct(private readonly string $stateFile) {}
            public function name(): string { return 'WriteState'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isReadOnly(array $input): bool { return false; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                file_put_contents($this->stateFile, 'current');

                return ToolResult::success('written');
            }
        });
        $registry->register(new class($stateFile) extends BaseTool {
            public function __construct(private readonly string $stateFile) {}
            public function name(): string { return 'ReadState'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success(
                    is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : 'missing',
                );
            }
        });

        $permissions = $this->createMock(PermissionChecker::class);
        $permissions->method('check')->willReturn(PermissionDecision::allow());
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(allowed: true));
        $orchestrator = new ToolOrchestrator($registry, $permissions, $hooks);
        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(new ToolUseContext('/tmp', 'streaming-stateful-barrier'), null, null);

        try {
            $executor->onToolBlockReady([
                'id' => 'write-state',
                'name' => 'WriteState',
                'input' => [],
            ], 0);
            $executor->onToolBlockReady([
                'id' => 'read-state',
                'name' => 'ReadState',
                'input' => [],
            ], 1);
            $results = $executor->collectResults();
        } finally {
            $executor->cleanup(notifyCompletion: false);
            @unlink($stateFile);
        }

        $this->assertSame(['written', 'current'], array_column($results, 'content'));
    }

    public function test_streaming_barrier_defers_later_validation_until_after_stateful_tool(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'haocode-stream-validation-');
        $this->assertNotFalse($stateFile);
        @unlink($stateFile);
        $validationStates = new \stdClass;
        $validationStates->observed = [];

        $registry = new ToolRegistry;
        $registry->register(new class($stateFile) extends BaseTool {
            public function __construct(private readonly string $stateFile) {}
            public function name(): string { return 'WriteState'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isReadOnly(array $input): bool { return false; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                file_put_contents($this->stateFile, 'ready');

                return ToolResult::success('written');
            }
        });
        $registry->register(new class($stateFile, $validationStates) extends BaseTool {
            public function __construct(
                private readonly string $stateFile,
                private readonly \stdClass $validationStates,
            ) {}
            public function name(): string { return 'StateSensitiveRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                $this->validationStates->observed[] = is_file($this->stateFile);

                return null;
            }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('read');
            }
        });

        $permissions = $this->createMock(PermissionChecker::class);
        $permissions->method('check')->willReturn(PermissionDecision::allow());
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(allowed: true));
        $orchestrator = new ToolOrchestrator($registry, $permissions, $hooks);
        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(new ToolUseContext('/tmp', 'streaming-validation-barrier'), null, null);

        try {
            $executor->onToolBlockReady([
                'id' => 'write-state',
                'name' => 'WriteState',
                'input' => [],
            ], 0);
            $executor->onToolBlockReady([
                'id' => 'read-state',
                'name' => 'StateSensitiveRead',
                'input' => [],
            ], 1);
            $results = $executor->collectResults();
        } finally {
            $executor->cleanup(notifyCompletion: false);
            @unlink($stateFile);
        }

        $this->assertSame(['written', 'read'], array_column($results, 'content'));
        $this->assertSame([true], $validationStates->observed);
    }

    // ─── results sorted by block index ────────────────────────────────────

    public function test_results_sorted_by_original_block_index(): void
    {
        $results = [
            ['tool_use_id' => 'a', 'content' => 'first', 'is_error' => false],
            ['tool_use_id' => 'b', 'content' => 'second', 'is_error' => false],
        ];

        $call = 0;
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            function () use ($results, &$call) {
                return $results[$call++];
            }
        );

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        // Register block index 3 first, then index 1
        $executor->onToolBlockReady(['id' => 'b', 'name' => 'MockTool', 'input' => []], 3);
        $executor->onToolBlockReady(['id' => 'a', 'name' => 'MockTool', 'input' => []], 1);
        $out = $executor->collectResults();

        // After ksort: index 1 (2nd executed = 'second') comes before index 3 (1st executed = 'first')
        $this->assertCount(2, $out);
        $this->assertSame('second', $out[0]['content']); // original block index 1
        $this->assertSame('first', $out[1]['content']);  // original block index 3
    }

    // ─── hasEarlyExecutions / earlyExecutionCount ─────────────────────────

    public function test_has_early_executions_false_when_no_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );
        $this->assertFalse($executor->hasEarlyExecutions());
    }

    // ─── cleanup resets state ─────────────────────────────────────────────

    public function test_cleanup_empties_queued_blocks(): void
    {
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->never())->method('executeToolBlock');

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup();

        $results = $executor->collectResults();
        $this->assertEmpty($results);
    }

    public function test_start_callback_exception_reaps_early_child_and_ipc_file(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX forked-tool cleanup coverage is unavailable.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'haocode-stream-callback-');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            function (array $block) use ($marker): array {
                $process = proc_open(
                    ['sh', '-c', 'sleep 1; printf leaked > '.escapeshellarg($marker)],
                    [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', '/dev/null', 'w'],
                        2 => ['file', '/dev/null', 'w'],
                    ],
                    $pipes,
                    sys_get_temp_dir(),
                );
                foreach ($pipes ?? [] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                usleep(5_000_000);
                if (is_resource($process)) {
                    @proc_close($process);
                }

                return ['tool_use_id' => $block['id'], 'content' => 'late', 'is_error' => false];
            },
        );

        try {
            $executor = new StreamingToolExecutor(
                $orchestrator,
                $this->makeRegistry(readOnly: true, concurrencySafe: true),
            );
            $executor->setContext(new ToolUseContext('/tmp', 'test'), static function (): void {
                throw new \RuntimeException('start callback failed');
            }, null);

            $this->expectException(\RuntimeException::class);
            $executor->onToolBlockReady($this->makeBlock(), 0);
        } finally {
            usleep(1_200_000);
            $this->assertFileDoesNotExist($marker, 'A streaming start callback failure must reap the early child.');
            @unlink($marker);
        }
    }

    // ─── on_complete callback passed through for queued blocks ───────────

    public function test_on_complete_passed_to_orchestrator_for_queued_block(): void
    {
        $completed = [];
        $onComplete = function (string $name, ToolResult $result) use (&$completed): void {
            $completed[] = [$name, $result];
        };

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturnCallback(
                static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $callback): array {
                    $result = ToolResult::success('ok', ['captured' => true]);
                    $callback?->__invoke($block['name'], $result);

                    return $result->toApiFormat($block['id']);
                },
            );

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, $onComplete);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->collectResults();

        $this->assertCount(1, $completed);
        $this->assertSame('MockTool', $completed[0][0]);
        $this->assertSame(['captured' => true], $completed[0][1]->metadata);
    }

    public function test_failed_queued_bash_skips_later_sibling_before_side_effect(): void
    {
        $sentinel = sys_get_temp_dir().'/haocode_sibling_'.bin2hex(random_bytes(8));
        $calls = 0;

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete) use (&$calls, $sentinel): array {
                $calls++;
                if ($block['name'] === 'Bash') {
                    $result = ToolResult::error('Command exited with code 1', ['exitCode' => 1]);
                } else {
                    file_put_contents($sentinel, 'executed');
                    $result = ToolResult::success('side effect executed');
                }
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $executor = new StreamingToolExecutor(
            $orchestrator,
            new ToolRegistry(),
            disableEarlyExecution: true,
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady([
            'id' => 'bash-1',
            'name' => 'Bash',
            'input' => ['command' => 'exit 1'],
        ], 0);
        $executor->onToolBlockReady([
            'id' => 'write-1',
            'name' => 'Write',
            'input' => ['file_path' => $sentinel, 'content' => 'executed'],
        ], 1);

        try {
            $results = $executor->collectResults();
            $sentinelExists = file_exists($sentinel);
        } finally {
            @unlink($sentinel);
        }

        $this->assertSame(1, $calls);
        $this->assertFalse($sentinelExists);
        $this->assertTrue($results[1]['is_error']);
        $this->assertStringContainsString('sibling Bash command', $results[1]['content']);
    }

    public function test_real_bash_failure_skips_later_bash_before_side_effect(): void
    {
        $sentinel = sys_get_temp_dir().'/haocode_real_sibling_'.bin2hex(random_bytes(8));
        $registry = new ToolRegistry();
        $registry->register(new BashTool());

        $permissions = $this->createMock(PermissionChecker::class);
        $permissions->method('check')->willReturn(PermissionDecision::allow());
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(allowed: true));
        $orchestrator = new ToolOrchestrator($registry, $permissions, $hooks);

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $registry,
            disableEarlyExecution: true,
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'real-sibling-'.bin2hex(random_bytes(8))),
            null,
            null,
        );
        $executor->onToolBlockReady([
            'id' => 'real-bash-failure',
            'name' => 'Bash',
            'input' => ['command' => 'exit 7'],
        ], 0);
        $executor->onToolBlockReady([
            'id' => 'real-bash-side-effect',
            'name' => 'Bash',
            'input' => ['command' => 'touch '.escapeshellarg($sentinel)],
        ], 1);

        try {
            $results = $executor->collectResults();
            $sentinelExists = file_exists($sentinel);
        } finally {
            @unlink($sentinel);
        }

        $this->assertTrue($results[0]['is_error']);
        $this->assertTrue($results[1]['is_error']);
        $this->assertStringContainsString('sibling Bash command', $results[1]['content']);
        $this->assertFalse($sentinelExists);
    }

    // ─── existing test ─────────────────────────────────────────────────────

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

    public function test_cancellation_interrupts_wait_for_forked_tool_and_returns_aborted_result(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl is required for process cancellation.');
        }

        $token = new CancellationToken();
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(function (): array {
            sleep(3);

            return ['tool_use_id' => 'toolu_1', 'content' => 'late', 'is_error' => false];
        });

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
            $token,
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($token): void {
            $token->cancel();
        });
        pcntl_alarm(1);
        $startedAt = microtime(true);

        try {
            $results = $executor->collectResults();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            $token->close();
        }

        $this->assertLessThan(2.5, microtime(true) - $startedAt);
        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool execution aborted', $results[0]['content']);
    }

    public function test_timeout_interrupts_wait_for_forked_tool_and_returns_timeout_result(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for forked-tool timeout coverage.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(function (): array {
            sleep(3);

            return ['tool_use_id' => 'toolu_1', 'content' => 'late', 'is_error' => false];
        });
        $completed = [];

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
            earlyToolTimeoutSeconds: 0.25,
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            static function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = [$name, $result];
            },
        );
        $executor->onToolBlockReady($this->makeBlock(), 0);

        $startedAt = microtime(true);
        $results = $executor->collectResults();

        $this->assertLessThan(2.0, microtime(true) - $startedAt);
        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool execution timed out.', $results[0]['content']);
        $this->assertCount(1, $completed);
        $this->assertTrue($completed[0][1]->metadata['timedOut'] ?? false);
    }

    public function test_early_completion_callback_is_not_blocked_by_an_earlier_slow_tool(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for early tool execution.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete): array {
                if ($block['id'] === 'slow') {
                    usleep(600_000);
                }

                $result = ToolResult::success($block['id']);
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $completed = [];
        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            static function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = $result->output;
            },
        );
        $executor->onToolBlockReady($this->makeBlock('slow'), 0);
        $executor->onToolBlockReady($this->makeBlock('fast'), 1);

        // Give the fast child a chance to exit while the earlier child remains running.
        usleep(150_000);
        $results = $executor->collectResults();

        $this->assertSame(['fast', 'slow'], $completed);
        $this->assertSame(['slow', 'fast'], array_column($results, 'content'));
    }

    public function test_early_completion_callback_preserves_metadata_and_outcome_across_ipc(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for early tool execution.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete): array {
                $result = ToolResult::aborted('cancelled in child', ['pid' => 42]);
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $completed = [];
        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            static function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = [$name, $result];
            },
        );
        $executor->onToolBlockReady($this->makeBlock(), 0);

        $executor->collectResults();

        $this->assertCount(1, $completed);
        $this->assertSame(['pid' => 42], $completed[0][1]->metadata);
        $this->assertSame(ToolOutcome::Aborted, $completed[0][1]->outcome());
    }

    public function test_early_execution_rejects_oversized_ipc_payloads(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for early tool execution.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete): array {
                $result = ToolResult::success('ok', ['blob' => str_repeat('x', 1_100_000)]);
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $completed = [];
        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(
            new ToolUseContext('/tmp', 'test'),
            null,
            static function (string $name, ToolResult $result) use (&$completed): void {
                $completed[] = [$name, $result];
            },
        );
        $executor->onToolBlockReady($this->makeBlock(), 0);

        $results = $executor->collectResults();

        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool result exceeded IPC size limit.', $results[0]['content']);
        $this->assertCount(1, $completed);
        $this->assertTrue($completed[0][1]->isError);
        $this->assertSame('Tool result exceeded IPC size limit.', $completed[0][1]->output);
    }

    public function test_early_execution_bounds_oversized_tool_use_ids_in_fallback(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for early tool execution.');
        }

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('mayRunToolHooks')->willReturn(false);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            static function (array $block, ToolUseContext $context, ?callable $onStart, ?callable $onComplete): array {
                $result = ToolResult::success('ok');
                $onComplete?->__invoke($block['name'], $result);

                return $result->toApiFormat($block['id']);
            },
        );

        $executor = new StreamingToolExecutor(
            $orchestrator,
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(str_repeat('x', 1_100_000)), 0);

        $results = $executor->collectResults();

        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool result exceeded IPC size limit.', $results[0]['content']);
        $this->assertLessThanOrEqual(4_096, strlen($results[0]['tool_use_id']));
    }
}
