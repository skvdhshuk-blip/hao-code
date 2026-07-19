<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolOrchestratorTest extends TestCase
{
    // ─── helpers ──────────────────────────────────────────────────────────

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

    private function context(): ToolUseContext
    {
        return new ToolUseContext('/tmp', 'test');
    }

    // ─── unknown tool ─────────────────────────────────────────────────────

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

    // ─── schema validation ────────────────────────────────────────────────

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

    // ─── semantic validation ──────────────────────────────────────────────

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

    // ─── PreToolUse hook blocking ─────────────────────────────────────────

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

    // ─── PreToolUse hook modifying input ──────────────────────────────────

    public function test_pre_tool_use_hook_can_modify_input(): void
    {
        $registry = new ToolRegistry;
        $received = [];
        $registry->register($this->makeTool('Echo', function ($input) use (&$received) {
            $received = $input;
            return ToolResult::success('ok');
        }));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(function (string $event, array $data) {
            if ($event === 'PreToolUse') {
                return new HookResult(true, ['injected' => 'value']);
            }
            return new HookResult(true);
        });

        $o = $this->makeOrchestrator($registry, null, $hooks);
        $o->executeToolBlock(['id' => 'id1', 'name' => 'Echo', 'input' => []], $this->context());
        $this->assertSame('value', $received['injected']);
    }

    // ─── permission denied ────────────────────────────────────────────────

    public function test_permission_denied_without_handler_returns_error(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Write', fn($i) => ToolResult::success('ok')));

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::deny('plan mode'));

        $o = $this->makeOrchestrator($registry, $checker);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Write', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Permission denied', $result['content']);
    }

    public function test_permission_handler_returning_false_denies(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Write', fn($i) => ToolResult::success('ok')));

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::ask());

        $o = $this->makeOrchestrator($registry, $checker);
        $o->setPermissionPromptHandler(fn() => false);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Write', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('denied by user', $result['content']);
    }

    public function test_permission_handler_returning_true_allows(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Write', fn($i) => ToolResult::success('written')));

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::ask());

        $o = $this->makeOrchestrator($registry, $checker);
        $o->setPermissionPromptHandler(fn() => true);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Write', 'input' => []], $this->context());
        $this->assertFalse($result['is_error']);
        $this->assertStringContainsString('written', $result['content']);
    }

    // ─── success path ─────────────────────────────────────────────────────

    public function test_successful_tool_execution_returns_output(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('hello output')));

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Echo', 'input' => []], $this->context());
        $this->assertFalse($result['is_error']);
        $this->assertStringContainsString('hello output', $result['content']);
        $this->assertSame('id1', $result['tool_use_id']);
    }

    // ─── PostToolUse hook appending output ────────────────────────────────

    public function test_post_tool_use_hook_output_appended(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('base output')));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(function (string $event) {
            if ($event === 'PostToolUse') {
                return new HookResult(true, null, 'hook appended');
            }
            return new HookResult(true);
        });

        $o = $this->makeOrchestrator($registry, null, $hooks);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Echo', 'input' => []], $this->context());
        $this->assertStringContainsString('base output', $result['content']);
        $this->assertStringContainsString('hook appended', $result['content']);
    }

    // ─── tool throws exception ────────────────────────────────────────────

    public function test_tool_exception_returns_error(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Crash', function () {
            throw new \RuntimeException('boom');
        }));

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Crash', 'input' => []], $this->context());
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('boom', $result['content']);
    }

    // ─── output truncation ────────────────────────────────────────────────

    public function test_output_truncated_when_exceeds_max_size(): void
    {
        $registry = new ToolRegistry;
        $bigOutput = str_repeat('x', 55_000);
        $tool = new class($bigOutput) extends BaseTool {
            public function __construct(private string $out) {}
            public function name(): string { return 'Big'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success($this->out); }
            public function maxResultSizeChars(): int { return 50_000; }
        };
        $registry->register($tool);

        $o = $this->makeOrchestrator($registry);
        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Big', 'input' => []], $this->context());
        $this->assertStringContainsString('truncated', $result['content']);
    }

    public function test_global_hard_cap_applies_when_tool_disables_its_own_limit(): void
    {
        $registry = new ToolRegistry;
        $tool = new class extends BaseTool {
            public function name(): string { return 'Unlimited'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success(str_repeat('x', 50_000)); }
            public function maxResultSizeChars(): int { return PHP_INT_MAX; }
        };
        $registry->register($tool);

        $orchestrator = $this->makeOrchestrator($registry);
        $result = $orchestrator->executeToolBlock(
            ['id' => 'id1', 'name' => 'Unlimited', 'input' => []],
            $this->context(),
        );

        $this->assertStringContainsString('truncated', $result['content']);
        $this->assertLessThan(3000, mb_strlen($result['content']));
    }

    // ─── onStart / onComplete callbacks ──────────────────────────────────

    public function test_on_start_and_complete_callbacks_called(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('ok')));

        $startCalled = false;
        $completeCalled = false;

        $o = $this->makeOrchestrator($registry);
        $o->executeToolBlock(
            ['id' => 'id1', 'name' => 'Echo', 'input' => []],
            $this->context(),
            onStart: function () use (&$startCalled) { $startCalled = true; },
            onComplete: function () use (&$completeCalled) { $completeCalled = true; },
        );

        $this->assertTrue($startCalled);
        $this->assertTrue($completeCalled);
    }

    // ─── mixed safe+unsafe parallel execution ─────────────────────────────

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
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
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
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
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
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('safe:' . ($input['label'] ?? ''));
            }
        });
        $registry->register(new class extends BaseTool {
            public function name(): string { return 'UnsafeTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
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

    // ─── deny vs ask distinction ──────────────────────────────────────────

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

    public function test_human_review_batches_gated_actions_and_keeps_hook_modified_input(): void
    {
        $registry = new ToolRegistry;
        $executed = [];
        $registry->register($this->makeTool('Write', function (array $input) use (&$executed) {
            $executed[] = $input;
            return ToolResult::success('written');
        }));
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('read'), true));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(static function (string $event, array $data = []): HookResult {
            if ($event === 'PreToolUse' && ($data['tool'] ?? null) === 'Write') {
                return new HookResult(true, ['path' => '/normalized']);
            }
            return new HookResult(true);
        });
        $orchestrator = $this->makeOrchestrator($registry, null, $hooks);
        $orchestrator->configureHumanInterrupts([
            'Write' => ['allowedDecisions' => ['approve', 'edit', 'reject'], 'description' => 'Review write'],
        ], false);

        $review = $orchestrator->prepareHumanReview([
            ['id' => 'write-1', 'name' => 'Write', 'input' => ['path' => 'raw']],
            ['id' => 'read-1', 'name' => 'Read', 'input' => []],
        ], $this->context());

        $this->assertCount(1, $review['actions']);
        $this->assertSame('/normalized', $review['actions'][0]->input['path']);
        $this->assertSame('Review write', $review['actions'][0]->description);
        $this->assertSame([], $executed, 'Preparation must not execute a gated tool.');
        $read = $orchestrator->executePreparedToolBlock($review['prepared'][1], $this->context());
        $this->assertSame('read', $read['content']);
    }

    public function test_human_review_never_turns_hard_deny_into_an_action(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Bash', fn () => ToolResult::success('must not run')));
        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::deny('policy deny'));
        $orchestrator = $this->makeOrchestrator($registry, $checker);
        $orchestrator->configureHumanInterrupts(['Bash' => true], false);

        $review = $orchestrator->prepareHumanReview([
            ['id' => 'bash-1', 'name' => 'Bash', 'input' => ['command' => 'rm -rf /']],
        ], $this->context());

        $this->assertSame([], $review['actions']);
        $this->assertTrue($review['results'][0]['is_error']);
        $this->assertStringContainsString('policy deny', $review['results'][0]['content']);
    }

    public function test_permission_ask_becomes_human_action_without_callback(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Bash', fn () => ToolResult::success('ok')));
        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::ask('dangerous command'));
        $orchestrator = $this->makeOrchestrator($registry, $checker);
        $orchestrator->enablePermissionInterrupts(true);

        $review = $orchestrator->prepareHumanReview([
            ['id' => 'bash-1', 'name' => 'Bash', 'input' => ['command' => 'php script.php']],
        ], $this->context());

        $this->assertSame('dangerous command', $review['actions'][0]->description);
    }

    public function test_ask_decision_does_call_permission_prompt_handler(): void
    {
        // PermissionDecision::ask() (needsPrompt=true) should still prompt the user
        // when a permission handler is set.
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Write', fn($i) => ToolResult::success('written')));

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturn(PermissionDecision::ask('requires approval'));

        $handlerCalled = false;
        $o = $this->makeOrchestrator($registry, $checker);
        $o->setPermissionPromptHandler(function () use (&$handlerCalled) {
            $handlerCalled = true;
            return true; // user approves
        });

        $result = $o->executeToolBlock(['id' => 'id1', 'name' => 'Write', 'input' => []], $this->context());

        $this->assertFalse($result['is_error'], 'ask+approve must allow the tool to run');
        $this->assertTrue($handlerCalled, 'Permission prompt handler must be called for ask decisions');
    }

    // ─── parallel_tool_completion (existing test) ─────────────────────────

    public function test_parallel_tool_completion_preserves_error_state(): void
    {
        // Covers both the pcntl-fork path and the no-pcntl fallback path.

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'SafeErrorTool';
            }

            public function description(): string
            {
                return 'A concurrency-safe tool that returns an error result.';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => 'string'],
                    ],
                ]);
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::error('failed: ' . ($input['label'] ?? 'unknown'));
            }
        });

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $orchestrator = new ToolOrchestrator($toolRegistry, $permissionChecker, $hookExecutor);

        $completedResults = [];
        $results = $orchestrator->executeTools(
            toolUseBlocks: [
                ['id' => 'toolu_1', 'name' => 'SafeErrorTool', 'input' => ['label' => 'one']],
                ['id' => 'toolu_2', 'name' => 'SafeErrorTool', 'input' => ['label' => 'two']],
            ],
            context: new ToolUseContext('/tmp', 'test-session'),
            onToolComplete: function (string $toolName, ToolResult $result) use (&$completedResults): void {
                $completedResults[] = [$toolName, $result];
            },
        );

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['is_error']);
        $this->assertTrue($results[1]['is_error']);
        $this->assertCount(2, $completedResults);
        $this->assertSame('SafeErrorTool', $completedResults[0][0]);
        $this->assertTrue($completedResults[0][1]->isError);
        $this->assertTrue($completedResults[1][1]->isError);
    }

    // ─── repeated Read hint ───────────────────────────────────────────────

    public function test_read_hint_appears_only_after_threshold(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('file body'), true));
        $o = $this->makeOrchestrator($registry);

        $outputs = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = $o->executeToolBlock(
                ['id' => "r{$i}", 'name' => 'Read', 'input' => ['file_path' => '/tmp/rules.json']],
                $this->context(),
            );
            $outputs[] = $r['content'];
        }

        // First three Reads return clean output.
        $this->assertSame('file body', $outputs[0]);
        $this->assertSame('file body', $outputs[1]);
        $this->assertSame('file body', $outputs[2]);
        // Fourth and fifth Reads get a hint appended.
        $this->assertStringContainsString('read /tmp/rules.json 4 times', $outputs[3]);
        $this->assertStringContainsString('read /tmp/rules.json 5 times', $outputs[4]);
    }

    public function test_write_resets_read_counter_for_same_file(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('body')));
        $registry->register($this->makeTool('Write', fn () => ToolResult::success('written')));
        $o = $this->makeOrchestrator($registry);

        // Push Read count up to threshold.
        for ($i = 0; $i < 4; $i++) {
            $o->executeToolBlock(
                ['id' => "r{$i}", 'name' => 'Read', 'input' => ['file_path' => '/tmp/x']],
                $this->context(),
            );
        }

        // Write on the same path should reset the counter.
        $o->executeToolBlock(
            ['id' => 'w1', 'name' => 'Write', 'input' => ['file_path' => '/tmp/x', 'content' => 'new']],
            $this->context(),
        );

        // Next Read is effectively the first post-mutation Read — no hint.
        $after = $o->executeToolBlock(
            ['id' => 'r5', 'name' => 'Read', 'input' => ['file_path' => '/tmp/x']],
            $this->context(),
        );

        $this->assertSame('body', $after['content']);
    }

    public function test_hint_keys_by_file_path_not_global(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('body')));
        $o = $this->makeOrchestrator($registry);

        // Read file A three times — no hint yet.
        for ($i = 0; $i < 3; $i++) {
            $o->executeToolBlock(
                ['id' => "a{$i}", 'name' => 'Read', 'input' => ['file_path' => '/tmp/a']],
                $this->context(),
            );
        }

        // First read of file B must be clean — no cross-file leakage.
        $firstB = $o->executeToolBlock(
            ['id' => 'b1', 'name' => 'Read', 'input' => ['file_path' => '/tmp/b']],
            $this->context(),
        );
        $this->assertSame('body', $firstB['content']);
    }

    public function test_hint_is_not_appended_on_error_results(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Read', fn () => ToolResult::error('permission denied')));
        $o = $this->makeOrchestrator($registry);

        for ($i = 0; $i < 5; $i++) {
            $r = $o->executeToolBlock(
                ['id' => "r{$i}", 'name' => 'Read', 'input' => ['file_path' => '/tmp/guarded']],
                $this->context(),
            );
            // Errors must not grow a hint suffix.
            $this->assertStringNotContainsString('[hint]', $r['content']);
        }
    }

    public function test_skill_scope_blocks_disallowed_sibling_tool(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Skill', fn () => ToolResult::success('loaded', [
            'allowed_tools' => ['Read'],
            'model_override' => 'skill-model',
            'context' => 'inline',
        ])));
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('read')));
        $registry->register($this->makeTool('Bash', fn () => ToolResult::success('must not run')));
        $orchestrator = $this->makeOrchestrator($registry);

        $results = $orchestrator->executeTools([
            ['id' => 'skill-1', 'name' => 'Skill', 'input' => []],
            ['id' => 'bash-1', 'name' => 'Bash', 'input' => []],
        ], $this->context());

        $this->assertFalse($results[0]['is_error']);
        $this->assertTrue($results[1]['is_error']);
        $this->assertStringContainsString('active skill scope', $results[1]['content']);
        $this->assertSame(['Read'], $orchestrator->getActiveSkillAllowedTools());
        $this->assertSame('skill-model', $orchestrator->getActiveSkillModelOverride());
    }

    public function test_multiple_skill_scopes_intersect_allowed_tools(): void
    {
        $registry = new ToolRegistry;
        $skillResults = [
            ToolResult::success('one', ['allowed_tools' => ['Read', 'Grep']]),
            ToolResult::success('two', ['allowed_tools' => ['Read', 'Bash']]),
        ];
        $registry->register($this->makeTool('Skill', function () use (&$skillResults) {
            return array_shift($skillResults);
        }));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->executeToolBlock(['id' => 's1', 'name' => 'Skill', 'input' => []], $this->context());
        $orchestrator->executeToolBlock(['id' => 's2', 'name' => 'Skill', 'input' => []], $this->context());

        $this->assertSame(['Read'], $orchestrator->getActiveSkillAllowedTools());
    }

    public function test_forked_skill_does_not_restrict_parent_tool_scope(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Skill', fn () => ToolResult::success('child result', [
            'allowed_tools' => ['Read'],
            'model_override' => 'child-model',
            'context' => 'fork',
        ])));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->executeToolBlock(['id' => 'fork-1', 'name' => 'Skill', 'input' => []], $this->context());

        $this->assertNull($orchestrator->getActiveSkillAllowedTools());
        $this->assertNull($orchestrator->getActiveSkillModelOverride());
        $this->assertSame('fork', $orchestrator->getActiveSkillContext());
    }
}
