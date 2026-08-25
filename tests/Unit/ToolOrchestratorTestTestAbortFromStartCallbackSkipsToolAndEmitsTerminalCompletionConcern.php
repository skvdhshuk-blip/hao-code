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

trait ToolOrchestratorTestTestAbortFromStartCallbackSkipsToolAndEmitsTerminalCompletionConcern
{

    public function test_abort_from_start_callback_skips_tool_and_emits_terminal_completion(): void
    {
        $registry = new ToolRegistry;
        $executed = false;
        $registry->register($this->makeTool(
            'StartAbortSensitive',
            static function () use (&$executed): ToolResult {
                $executed = true;

                return ToolResult::success('must not run');
            },
        ));
        $aborted = false;
        $completed = [];
        $context = new ToolUseContext(
            '/tmp',
            'start-abort',
            shouldAbort: static function () use (&$aborted): bool {
                return $aborted;
            },
        );

        $result = $this->makeOrchestrator($registry)->executeToolBlock(
            ['id' => 'start-abort-1', 'name' => 'StartAbortSensitive', 'input' => []],
            $context,
            static function () use (&$aborted): void {
                $aborted = true;
            },
            static function (string $name, ToolResult $toolResult) use (&$completed): void {
                $completed[] = [$name, $toolResult];
            },
        );

        $this->assertFalse($executed);
        $this->assertTrue($result['is_error']);
        $this->assertSame('Tool execution aborted', $result['content']);
        $this->assertCount(1, $completed);
        $this->assertSame('StartAbortSensitive', $completed[0][0]);
        $this->assertSame(ToolOutcome::Aborted, $completed[0][1]->outcome());
    }

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

    public function test_hook_modified_input_is_revalidated_before_tool_execution(): void
    {
        $registry = new ToolRegistry;
        $executed = false;
        $registry->register($this->makeRequiredPathTool(function () use (&$executed) {
            $executed = true;
            return ToolResult::success('should not run');
        }));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(static function (string $event): HookResult {
            return $event === 'PreToolUse'
                ? new HookResult(true, [])
                : new HookResult(true);
        });

        $orchestrator = $this->makeOrchestrator($registry, null, $hooks);
        $result = $orchestrator->executeToolBlock(
            ['id' => 'path-1', 'name' => 'PathTool', 'input' => ['path' => 'original.txt']],
            $this->context(),
        );

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('InputValidationError', $result['content']);
        $this->assertStringContainsString('path', $result['content']);
        $this->assertFalse($executed);
    }

    public function test_hook_modified_input_runs_semantic_validation_again(): void
    {
        $registry = new ToolRegistry;
        $state = new \stdClass;
        $state->executed = false;
        $registry->register(new class($state) extends BaseTool {
            public function __construct(private \stdClass $state) {}
            public function name(): string { return 'SemanticHookTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => ['mode' => ['type' => 'string']],
                    'required' => ['mode'],
                ]);
            }
            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return $input['mode'] === 'invalid' ? 'mode is invalid' : null;
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                $this->state->executed = true;
                return ToolResult::success('should not run');
            }
        });

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true, ['mode' => 'invalid']));

        $orchestrator = $this->makeOrchestrator($registry, null, $hooks);
        $result = $orchestrator->executeToolBlock(
            ['id' => 'semantic-1', 'name' => 'SemanticHookTool', 'input' => ['mode' => 'valid']],
            $this->context(),
        );

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Validation: mode is invalid', $result['content']);
        $this->assertFalse($state->executed);
    }

    public function test_hook_modified_relative_path_is_normalized_before_permission_check(): void
    {
        $registry = new ToolRegistry;
        $executedInput = null;
        $registry->register($this->makeRequiredPathTool(function (array $input) use (&$executedInput) {
            $executedInput = $input;
            return ToolResult::success('ok');
        }));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturnCallback(static function (string $event): HookResult {
            return $event === 'PreToolUse'
                ? new HookResult(true, ['path' => 'hook-relative.txt'])
                : new HookResult(true);
        });

        $permissionInput = null;
        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturnCallback(
            static function ($tool, array $input) use (&$permissionInput): PermissionDecision {
                $permissionInput = $input;
                return PermissionDecision::allow();
            },
        );

        $orchestrator = $this->makeOrchestrator($registry, $checker, $hooks);
        $result = $orchestrator->executeToolBlock(
            ['id' => 'path-1', 'name' => 'PathTool', 'input' => ['path' => 'original.txt']],
            $this->context(),
        );

        $this->assertFalse($result['is_error']);
        $this->assertSame('/tmp/hook-relative.txt', $permissionInput['path']);
        $this->assertSame('/tmp/hook-relative.txt', $executedInput['path']);
    }

    public function test_human_review_revalidates_hook_modified_input(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeRequiredPathTool(fn () => ToolResult::success('should not run')));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true, []));

        $orchestrator = $this->makeOrchestrator($registry, null, $hooks);
        $orchestrator->configureHumanInterrupts(['PathTool' => true], false);
        $review = $orchestrator->prepareHumanReview([
            ['id' => 'path-1', 'name' => 'PathTool', 'input' => ['path' => 'original.txt']],
        ], $this->context());

        $this->assertSame([], $review['actions']);
        $this->assertTrue($review['results'][0]['is_error']);
        $this->assertStringContainsString('InputValidationError', $review['results'][0]['content']);
    }

    public function test_human_review_normalizes_hook_modified_path_before_permission_check(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeRequiredPathTool(fn () => ToolResult::success('ok')));

        $hooks = $this->createMock(HookExecutor::class);
        $hooks->method('execute')->willReturn(new HookResult(true, ['path' => 'review-relative.txt']));

        $permissionInput = null;
        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('check')->willReturnCallback(
            static function ($tool, array $input) use (&$permissionInput): PermissionDecision {
                $permissionInput = $input;
                return PermissionDecision::allow();
            },
        );

        $orchestrator = $this->makeOrchestrator($registry, $checker, $hooks);
        $orchestrator->configureHumanInterrupts(['PathTool' => true], false);
        $review = $orchestrator->prepareHumanReview([
            ['id' => 'path-1', 'name' => 'PathTool', 'input' => ['path' => 'original.txt']],
        ], $this->context());

        $this->assertSame('/tmp/review-relative.txt', $permissionInput['path']);
        $this->assertSame('/tmp/review-relative.txt', $review['actions'][0]->input['path']);
    }

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

    public function test_output_truncated_when_exceeds_max_size(): void
    {
        $registry = new ToolRegistry;
        $bigOutput = str_repeat('x', 55_000);
        $tool = new class($bigOutput) extends BaseTool {
            public function __construct(private string $out) {}
            public function name(): string { return 'Big'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
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
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
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

    public function test_compacted_large_read_revokes_complete_read_receipt(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-large-read-');
        $content = str_repeat('x', 50_000);
        file_put_contents($file, $content);
        $registry = new ToolRegistry;
        $registry->register(new class($content) extends BaseTool {
            public function __construct(private readonly string $content) {}
            public function name(): string { return 'Read'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(
                    ['type' => 'object'],
                    ['file_path' => ['required', 'string']],
                );
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                $context->recordFileRead($input['file_path'], $this->content, 1, null, false);

                return ToolResult::success($this->content);
            }
            public function maxResultSizeChars(): int { return PHP_INT_MAX; }
        });
        $context = new ToolUseContext(dirname($file), 'large-read');

        try {
            $result = $this->makeOrchestrator($registry)->executeToolBlock(
                ['id' => 'large-read', 'name' => 'Read', 'input' => ['file_path' => $file]],
                $context,
            );

            $this->assertStringContainsString('truncated', $result['content']);
            $this->assertFalse($context->wasFileRead($file));
            $this->assertFalse($context->getFileRevision($file)?->complete);
        } finally {
            @unlink($file);
        }
    }

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
}
