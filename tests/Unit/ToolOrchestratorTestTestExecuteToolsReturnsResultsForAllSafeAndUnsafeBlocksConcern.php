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

trait ToolOrchestratorTestTestExecuteToolsReturnsResultsForAllSafeAndUnsafeBlocksConcern
{

    public function test_execute_tools_returns_results_for_all_safe_and_unsafe_blocks(): void
    {
        // Covers both the pcntl-fork path and the no-pcntl fallback path.
        // Previously this was skipped when pcntl was unavailable, which hid an
        // index-loss bug in the fallback branch (safe results re-indexed from 0
        // collided with unsafe results and overwrote each other).

        $registry = new ToolRegistry;

        // Safe (read-only + concurrency-safe) tool
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'SafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('safe:' . ($input['label'] ?? ''));
            }
        });

        // Unsafe (write) tool
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'UnsafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return false; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('unsafe:' . ($input['label'] ?? ''));
            }
        });

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::allow());
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true));

        $o = new ToolOrchestrator($registry, $checker, $hooks);

        // Two safe (parallel) + one unsafe (sequential) = 3 results total
        $results = $o->executeTools(
            toolUseBlocks: [
                ['id' => 'id_s1', 'name' => 'SafeTool', 'input' => ['label' => 'A']],
                ['id' => 'id_s2', 'name' => 'SafeTool', 'input' => ['label' => 'B']],
                ['id' => 'id_u1', 'name' => 'UnsafeTool', 'input' => ['label' => 'C']],
            ],
            context: new ToolUseContext('/tmp', 'test'),
        );

        // All 3 results must be present — a missing result means the fork-result
        // accumulation was broken (e.g., $results reset inside executeInParallel).
        $this->assertCount(3, $results, 'All safe+unsafe tool results must be returned');
        $contents = array_column($results, 'content');
        $this->assertContains('safe:A', $contents);
        $this->assertContains('safe:B', $contents);
        $this->assertContains('unsafe:C', $contents);
    }

    public function test_execute_tools_runs_read_only_tools_after_stateful_tools_as_a_later_phase(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'haocode-tool-barrier-');
        $this->assertNotFalse($stateFile);
        @unlink($stateFile);

        $registry = new ToolRegistry;
        $registry->register(new class($stateFile) extends BaseTool {
            public function __construct(private readonly string $stateFile) {}
            public function name(): string { return 'WriteState'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
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
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success(
                    is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : 'missing',
                );
            }
        });

        try {
            $results = $this->makeOrchestrator($registry)->executeTools([
                ['id' => 'write-state', 'name' => 'WriteState', 'input' => []],
                ['id' => 'read-state', 'name' => 'ReadState', 'input' => []],
            ], $this->context());
        } finally {
            @unlink($stateFile);
        }

        $this->assertSame(['written', 'current'], array_column($results, 'content'));
    }

    public function test_execute_tools_does_not_parallelize_tools_with_pre_tool_use_hooks(): void
    {
        $parentPid = getmypid();
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'SafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success((string) getmypid());
            }
        });
        $checker = $this->allowAllChecker();
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true));
        $hooks->method('hasHooksFor')->willReturnCallback(
            static fn (string $event, ?string $toolName = null): bool =>
                $event === 'PreToolUse' && $toolName === 'SafeTool',
        );

        $orchestrator = new ToolOrchestrator($registry, $checker, $hooks);
        $results = $orchestrator->executeTools([
            ['id' => 'id_s1', 'name' => 'SafeTool', 'input' => []],
            ['id' => 'id_s2', 'name' => 'SafeTool', 'input' => []],
        ], new ToolUseContext('/tmp', 'test'));

        $this->assertSame((string) $parentPid, $results[0]['content']);
        $this->assertSame((string) $parentPid, $results[1]['content']);
    }

    public function test_execute_tools_classifies_using_backfilled_input(): void
    {
        $parentPid = getmypid();
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'ContextSensitiveRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function backfillObservableInput(array $input, ToolUseContext $context): array
            {
                // The effective invocation is not safe after context
                // normalization, even though the raw model payload is empty.
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
                return ToolResult::success((string) getmypid());
            }
        });

        $results = $this->makeOrchestrator($registry)->executeTools([
            ['id' => 'context-1', 'name' => 'ContextSensitiveRead', 'input' => []],
            ['id' => 'context-2', 'name' => 'ContextSensitiveRead', 'input' => []],
        ], new ToolUseContext('/tmp', 'context-sensitive-classification'));

        $this->assertSame([(string) $parentPid, (string) $parentPid], array_column($results, 'content'));
    }

    public function test_parallel_classification_validates_and_preserves_normalized_callback_input(): void
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
                return ToolResult::success($input['path']);
            }
        });

        $this->makeOrchestrator($registry)->executeTools(
            [
                ['id' => 'normalized-1', 'name' => 'NormalizedPathRead', 'input' => ['path' => 'one.txt']],
                ['id' => 'normalized-2', 'name' => 'NormalizedPathRead', 'input' => ['path' => 'two.txt']],
            ],
            new ToolUseContext('/tmp/normalized-callback', 'normalized-callback'),
            onToolStart: static function (string $toolName, array $input) use (&$startedInputs): void {
                $startedInputs[] = $input;
            },
        );

        $this->assertSame([
            ['path' => '/tmp/normalized-callback/one.txt'],
            ['path' => '/tmp/normalized-callback/two.txt'],
        ], $startedInputs);
    }

    public function test_parallel_classification_fails_closed_for_semantically_invalid_input(): void
    {
        $started = 0;
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

        $results = $this->makeOrchestrator($registry)->executeTools(
            [
                ['id' => 'invalid-1', 'name' => 'InvalidRead', 'input' => []],
                ['id' => 'invalid-2', 'name' => 'InvalidRead', 'input' => []],
            ],
            new ToolUseContext('/tmp', 'invalid-classification'),
            onToolStart: static function () use (&$started): void {
                $started++;
            },
        );

        $this->assertSame(0, $started);
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['is_error']);
            $this->assertStringContainsString('semantic input is invalid', $result['content']);
        }
    }

    public function test_execute_tools_does_not_parallelize_when_permission_prompt_may_run(): void
    {
        $parentPid = getmypid();
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'PromptingRead'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success((string) getmypid());
            }
        });

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::ask('approval required'));
        $orchestrator = new ToolOrchestrator($registry, $checker, $this->noopHooks());
        $orchestrator->setPermissionPromptHandler(static fn (): bool => true);

        $results = $orchestrator->executeTools([
            ['id' => 'prompt-1', 'name' => 'PromptingRead', 'input' => []],
            ['id' => 'prompt-2', 'name' => 'PromptingRead', 'input' => []],
        ], new ToolUseContext('/tmp', 'prompting-parallel'));

        $this->assertSame([(string) $parentPid, (string) $parentPid], array_column($results, 'content'));
    }

    public function test_execute_tools_preserves_original_call_order_for_interleaved_blocks(): void
    {
        // Covers both the pcntl-fork path and the no-pcntl fallback path.
        // The interleaved [safe, unsafe, safe] case is exactly the one that
        // exposed the fallback index-loss bug.

        // Registers safe and unsafe tools (same as the previous test)
        $registry = new ToolRegistry;
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'SafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('safe:' . ($input['label'] ?? ''));
            }
        });
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'UnsafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function isReadOnly(array $input): bool { return false; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('unsafe:' . ($input['label'] ?? ''));
            }
        });

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::allow());
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true));

        $o = new ToolOrchestrator($registry, $checker, $hooks);

        // Interleaved: [safe A, unsafe B, safe C] — results must come back in A, B, C order,
        // not A, C, B (which is what happened before the fix because safe blocks
        // were re-indexed 0,1 and unsafe blocks were appended after them).
        $results = $o->executeTools(
            toolUseBlocks: [
                ['id' => 'id_s1', 'name' => 'SafeTool',   'input' => ['label' => 'A']],
                ['id' => 'id_u1', 'name' => 'UnsafeTool', 'input' => ['label' => 'B']],
                ['id' => 'id_s2', 'name' => 'SafeTool',   'input' => ['label' => 'C']],
            ],
            context: new ToolUseContext('/tmp', 'test'),
        );

        $this->assertCount(3, $results);
        // Position 0 = first block (safe A), position 2 = third block (safe C)
        $this->assertSame('safe:A',   $results[0]['content'], 'First result must be safe:A');
        $this->assertSame('unsafe:B', $results[1]['content'], 'Second result must be unsafe:B');
        $this->assertSame('safe:C',   $results[2]['content'], 'Third result must be safe:C');
    }

    public function test_same_batch_read_does_not_authorize_same_batch_write(): void
    {
        $root = sys_get_temp_dir().'/haocode-same-batch-read-write-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $file = $root.'/config.php';
        file_put_contents($file, "old\n");

        $registry = new ToolRegistry;
        $registry->register(new FileReadTool);
        $registry->register(new FileWriteTool);
        $context = new ToolUseContext($root, 'same-batch-read-write');

        try {
            foreach ([
                'read then write' => [
                    ['id' => 'read-1', 'name' => 'Read', 'input' => ['file_path' => 'config.php']],
                    ['id' => 'write-1', 'name' => 'Write', 'input' => ['file_path' => 'config.php', 'content' => "new\n"]],
                ],
                'write then read' => [
                    ['id' => 'write-1', 'name' => 'Write', 'input' => ['file_path' => 'config.php', 'content' => "new\n"]],
                    ['id' => 'read-1', 'name' => 'Read', 'input' => ['file_path' => 'config.php']],
                ],
            ] as $case => $blocks) {
                file_put_contents($file, "old\n");
                $context->resetReadState();

                $results = $this->makeOrchestrator($registry)->executeTools($blocks, $context);
                $writeResult = $this->resultById($results, 'write-1');

                $this->assertTrue($writeResult['is_error'] ?? false, $case);
                $this->assertStringContainsString('Read tool first', (string) ($writeResult['content'] ?? ''), $case);
                $this->assertSame("old\n", file_get_contents($file), $case);
                $this->assertTrue($context->wasFileRead($file), $case);
            }
        } finally {
            @unlink($file);
            @rmdir($root);
        }
    }

    public function test_prior_batch_read_authorizes_later_batch_write(): void
    {
        $root = sys_get_temp_dir().'/haocode-prior-batch-read-write-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $file = $root.'/config.php';
        file_put_contents($file, "old\n");

        $registry = new ToolRegistry;
        $registry->register(new FileReadTool);
        $registry->register(new FileWriteTool);
        $orchestrator = $this->makeOrchestrator($registry);
        $context = new ToolUseContext($root, 'prior-batch-read-write');

        try {
            $readResults = $orchestrator->executeTools([
                ['id' => 'read-1', 'name' => 'Read', 'input' => ['file_path' => 'config.php']],
            ], $context);
            $this->assertFalse($readResults[0]['is_error'] ?? false);
            $this->assertTrue($context->wasFileRead($file));

            $writeResults = $orchestrator->executeTools([
                ['id' => 'write-1', 'name' => 'Write', 'input' => ['file_path' => 'config.php', 'content' => "new\n"]],
            ], $context);

            $this->assertFalse($writeResults[0]['is_error'] ?? false);
            $this->assertSame("new\n", file_get_contents($file));
        } finally {
            @unlink($file);
            @rmdir($root);
        }
    }

    public function test_hard_deny_is_not_overridden_by_permission_prompt_handler(): void
    {
        // A PermissionDecision::deny() (needsPrompt=false) must NEVER call the
        // permission prompt handler — even if one is registered. Before the fix,
        // the orchestrator only checked $decision->allowed, so a permissive handler
        // could override a deny rule, making deny rules ineffective.
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Write', fn($i) => ToolResult::success('should not run')));

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::deny('plan mode: writes forbidden'));

        $handlerCalled = false;
        $o = $this->makeOrchestrator($registry, $checker);
        $o->setPermissionPromptHandler(function () use (&$handlerCalled) {
            $handlerCalled = true;
            return true; // would approve if called
        });

        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Write', 'input' => []], $this->context());

        $this->assertTrue($result['is_error'], 'Deny decision must produce an error result');
        $this->assertStringContainsString('Permission denied', $result['content']);
        $this->assertFalse($handlerCalled, 'Permission prompt handler must NOT be called for hard-deny decisions');
    }
}
