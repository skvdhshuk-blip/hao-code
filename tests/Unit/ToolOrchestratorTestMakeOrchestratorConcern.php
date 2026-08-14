<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait ToolOrchestratorTestMakeOrchestratorConcern
{

    private function makeOrchestrator(
        ?ToolRegistry $registry = null,
        ?PermissionChecker $checker = null,
        ?HookExecutor $hooks = null,
    ): ToolOrchestrator {
        $registry ??= new ToolRegistry;
        $checker ??= $this->allowAllChecker();
        $hooks ??= $this->noopHooks();
        return new ToolOrchestrator($registry, $checker, $hooks);
    }

    private function allowAllChecker(): PermissionChecker
    {
        $c = $this->createMock(PermissionChecker::class);
        $c->method('check')->willReturn(PermissionDecision::allow());
        return $c;
    }

    private function noopHooks(): HookExecutor
    {
        $h = $this->createMock(HookExecutor::class);
        $h->method('execute')->willReturn(new HookResult(true));
        $h->method('hasHooksFor')->willReturn(false);
        return $h;
    }

    private function makeTool(string $name, callable $call, bool $readOnly = false): BaseTool
    {
        return new class($name, $call, $readOnly) extends BaseTool {
            public function __construct(
                private string $n,
                private $fn,
                private bool $ro,
            ) {}
            public function name(): string { return $this->n; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ($this->fn)($input, $ctx); }
            public function isReadOnly(array $input): bool { return $this->ro; }
        };
    }

    private function makeRequiredPathTool(callable $call): BaseTool
    {
        return new class($call) extends BaseTool {
            public function __construct(private $fn) {}
            public function name(): string { return 'PathTool'; }
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
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ($this->fn)($input, $context);
            }
        };
    }

    private function context(): ToolUseContext
    {
        return new ToolUseContext('/tmp', 'test');
    }

    /** @param array<int, array<string, mixed>> $results */
    private function resultById(array $results, string $id): array
    {
        foreach ($results as $result) {
            if (($result['tool_use_id'] ?? null) === $id) {
                return $result;
            }
        }

        $this->fail("Missing tool result {$id}.");
    }

    public function test_unknown_tool_returns_error(): void
    {
        $o = $this->makeOrchestrator();
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'NoSuchTool', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Unknown tool', $result['content']);
    }

    public function test_disabled_tool_returns_error_even_when_registered(): void
    {
        $disabledTool = new class extends BaseTool {
            public function name(): string { return 'DisabledTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isEnabled(): bool { return false; }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success('should not execute'); }
        };

        $registry = new ToolRegistry;
        $registry->register($disabledTool);

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'DisabledTool', 'input' => []], $this->context());

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Unknown tool', $result['content']);
    }

    public function test_schema_validation_failure_returns_error(): void
    {
        $registry = new ToolRegistry;
        // Use a ToolInputSchema that throws InvalidArgumentException without Laravel Validator
        $tool = new class extends BaseTool {
            public function name(): string { return 'Strict'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                $schema = new class extends ToolInputSchema {
                    public function __construct() {}
                    public function validate(array $input): array
                    {
                        throw new \InvalidArgumentException('Validation failed: required_field is required');
                    }
                    public function toJsonSchema(): array { return ['type' => 'object']; }
                };
                return $schema;
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success('ok'); }
        };
        $registry->register($tool);

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Strict', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('InputValidationError', $result['content']);
    }

    public function test_semantic_validation_failure_returns_error(): void
    {
        $registry = new ToolRegistry;
        $tool = new class extends BaseTool {
            public function name(): string { return 'Semantic'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success('ok'); }
            public function validateInput(array $input, ToolUseContext $ctx): ?string { return 'file must exist'; }
        };
        $registry->register($tool);

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Semantic', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('file must exist', $result['content']);
    }

    public function test_pre_tool_use_hook_blocking_returns_error(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn($i) => ToolResult::success('hi')));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(false, null, 'blocked by policy'));

        $o = $this->makeOrchestrator($registry, null, $hooks);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Echo', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Blocked by hook', $result['content']);
    }

    public function test_pre_aborted_context_skips_hooks_permissions_tool_and_start_callback(): void
    {
        $registry = new ToolRegistry;
        $executed = false;
        $registry->register($this->makeTool('AbortSensitive', function () use (&$executed) {
            $executed = true;

            return ToolResult::success('ran');
        }));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->expects($this->never())->method('execute');
        $checker = $this->createMock(PermissionChecker::class);
        $checker->expects($this->never())->method('check');
        $started = false;
        $context = new ToolUseContext(
            '/tmp',
            'abort-test',
            shouldAbort: static fn (): bool => true,
        );

        $result = $this->makeOrchestrator($registry, $checker, $hooks)->executeToolBlock(
            ['id' => 'abort-1', 'name' => 'AbortSensitive', 'input' => []],
            $context,
            static function () use (&$started): void {
                $started = true;
            },
        );

        $this->assertTrue($result['is_error']);
        $this->assertSame('Tool execution aborted', $result['content']);
        $this->assertFalse($executed);
        $this->assertFalse($started);
    }

    public function test_pre_aborted_batch_does_not_fork_or_emit_start_callbacks(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'SafeAbortSensitive',
            static fn (): ToolResult => ToolResult::success('must not run'),
            true,
        ));
        $started = 0;
        $completed = [];
        $context = new ToolUseContext(
            '/tmp',
            'abort-batch',
            shouldAbort: static fn (): bool => true,
        );

        $results = $this->makeOrchestrator($registry)->executeTools(
            [
                ['id' => 'abort-1', 'name' => 'SafeAbortSensitive', 'input' => []],
                ['id' => 'abort-2', 'name' => 'SafeAbortSensitive', 'input' => []],
            ],
            $context,
            static function () use (&$started): void {
                $started++;
            },
            static function (string $toolName, ToolResult $result) use (&$completed): void {
                $completed[] = [$toolName, $result];
            },
        );

        $this->assertSame(0, $started);
        $this->assertSame(
            ['Tool execution aborted', 'Tool execution aborted'],
            array_column($results, 'content'),
        );
        $this->assertCount(2, $completed);
        $this->assertSame(['SafeAbortSensitive', 'SafeAbortSensitive'], array_column($completed, 0));
        $this->assertSame(
            [ToolOutcome::Aborted, ToolOutcome::Aborted],
            array_map(static fn (array $entry): ToolOutcome => $entry[1]->outcome(), $completed),
        );
    }

    public function test_parallel_children_are_terminated_when_parent_context_is_aborted(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for parallel cancellation.');
        }

        $marker = sys_get_temp_dir().'/haocode-parallel-abort-'.bin2hex(random_bytes(8));
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'HangingRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => ['marker' => ['type' => 'string']],
                ]);
            }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $ctx): ToolResult
            {
                file_put_contents((string) $input['marker'], 'started', LOCK_EX);
                usleep(1_500_000);

                return ToolResult::success('finished');
            }
        });

        $context = new ToolUseContext(
            '/tmp',
            'parallel-abort',
            shouldAbort: static fn (): bool => is_file($marker),
        );
        $startedAt = microtime(true);

        try {
            $results = $this->makeOrchestrator($registry)->executeTools(
                [
                    ['id' => 'abort-1', 'name' => 'HangingRead', 'input' => ['marker' => $marker]],
                    ['id' => 'abort-2', 'name' => 'HangingRead', 'input' => ['marker' => $marker]],
                ],
                $context,
            );
        } finally {
            @unlink($marker);
        }

        $elapsed = microtime(true) - $startedAt;
        $this->assertLessThan(1.0, $elapsed, 'Parent must not wait for the full child workload after cancellation.');
        $this->assertSame(['Tool execution aborted', 'Tool execution aborted'], array_column($results, 'content'));
        $this->assertSame([true, true], array_column($results, 'is_error'));
    }

    public function test_parallel_child_timeout_terminates_process_tree_and_returns_timeout_result(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX forked-tool timeout coverage is unavailable.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'haocode-parallel-timeout-');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $registry = new ToolRegistry;
        $registry->register(new class($marker) extends BaseTool {
            public function __construct(private readonly string $marker) {}
            public function name(): string { return 'HangingRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object']);
            }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                $process = proc_open(
                    ['sh', '-c', 'sleep 1; printf leaked > '.escapeshellarg($this->marker)],
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

                return ToolResult::success('late');
            }
        });

        try {
            $orchestrator = new ToolOrchestrator(
                toolRegistry: $registry,
                permissionChecker: $this->allowAllChecker(),
                hookExecutor: $this->noopHooks(),
                parallelToolTimeoutSeconds: 0.25,
            );
            $startedAt = microtime(true);
            $results = $orchestrator->executeTools([
                ['id' => 'timeout-1', 'name' => 'HangingRead', 'input' => []],
                ['id' => 'timeout-2', 'name' => 'HangingRead', 'input' => []],
            ], new ToolUseContext('/tmp', 'parallel-timeout'));

            $this->assertLessThan(2.0, microtime(true) - $startedAt);
            $this->assertSame(['Tool execution timed out.', 'Tool execution timed out.'], array_column($results, 'content'));
            $this->assertSame([true, true], array_column($results, 'is_error'));

            usleep(1_200_000);
            $this->assertFileDoesNotExist($marker, 'Timed-out parallel descendants must not outlive the parent worker.');
        } finally {
            @unlink($marker);
        }
    }

    public function test_parallel_start_callback_exception_cleans_started_child_tree(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX forked-tool cleanup coverage is unavailable.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'haocode-parallel-callback-');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'CallbackSensitiveRead',
            function () use ($marker): ToolResult {
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

                return ToolResult::success('late');
            },
            readOnly: true,
        ));

        try {
            $this->expectException(\RuntimeException::class);
            $this->makeOrchestrator($registry)->executeTools(
                [
                    ['id' => 'callback-1', 'name' => 'CallbackSensitiveRead', 'input' => []],
                    ['id' => 'callback-2', 'name' => 'CallbackSensitiveRead', 'input' => []],
                ],
                $this->context(),
                static function (): void {
                    throw new \RuntimeException('start callback failed');
                },
            );
        } finally {
            usleep(1_200_000);
            $this->assertFileDoesNotExist($marker, 'A start callback failure must not leave a forked child running.');
            @unlink($marker);
        }
    }
}
