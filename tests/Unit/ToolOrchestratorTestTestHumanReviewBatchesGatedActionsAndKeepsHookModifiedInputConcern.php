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

trait ToolOrchestratorTestTestHumanReviewBatchesGatedActionsAndKeepsHookModifiedInputConcern
{

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

    public function test_parallel_tool_completion_preserves_metadata_and_outcome(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for parallel tool IPC.');
        }

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'SafeAbortTool',
            static fn (array $input): ToolResult => ToolResult::aborted(
                'cancelled: '.($input['label'] ?? ''),
                ['pid' => 42],
            ),
            true,
        ));
        $completed = [];

        $this->makeOrchestrator($registry)->executeTools(
            toolUseBlocks: [
                ['id' => 'toolu_1', 'name' => 'SafeAbortTool', 'input' => ['label' => 'one']],
                ['id' => 'toolu_2', 'name' => 'SafeAbortTool', 'input' => ['label' => 'two']],
            ],
            context: new ToolUseContext('/tmp', 'test-session'),
            onToolComplete: static function (string $toolName, ToolResult $result) use (&$completed): void {
                $completed[] = [$toolName, $result];
            },
        );

        $this->assertCount(2, $completed);
        $this->assertSame(['pid' => 42], $completed[0][1]->metadata);
        $this->assertSame(ToolOutcome::Aborted, $completed[0][1]->outcome());
        $this->assertSame(['pid' => 42], $completed[1][1]->metadata);
        $this->assertSame(ToolOutcome::Aborted, $completed[1][1]->outcome());
    }

    public function test_parallel_completion_callback_is_not_delayed_until_the_batch_settles(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for parallel completion lifecycle coverage.');
        }

        $markerPrefix = sys_get_temp_dir().'/haocode-parallel-completion-'.bin2hex(random_bytes(8));
        $slowMarker = $markerPrefix.'-slow';
        $fastMarker = $markerPrefix.'-fast';

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'CompletionProbeRead',
            static function (array $input, ToolUseContext $context): ToolResult {
                if (isset($input['wait_for_marker'])) {
                    $deadline = microtime(true) + 1.0;
                    while (! is_file((string) $input['wait_for_marker']) && microtime(true) < $deadline) {
                        usleep(1_000);
                    }
                }
                $delay = (int) ($input['delay_us'] ?? 0);
                if ($delay > 0) {
                    usleep($delay);
                }
                if (isset($input['marker'])) {
                    file_put_contents((string) $input['marker'], 'finished', LOCK_EX);
                }

                return ToolResult::success((string) $input['label']);
            },
            true,
        ));

        $fastObservedSlowFinished = null;
        try {
            $results = $this->makeOrchestrator($registry)->executeTools(
                toolUseBlocks: [
                    [
                        'id' => 'slow-1',
                        'name' => 'CompletionProbeRead',
                        'input' => [
                            'label' => 'slow',
                            'wait_for_marker' => $fastMarker,
                            'delay_us' => 500_000,
                            'marker' => $slowMarker,
                        ],
                    ],
                    [
                        'id' => 'fast-1',
                        'name' => 'CompletionProbeRead',
                        'input' => ['label' => 'fast', 'marker' => $fastMarker],
                    ],
                ],
                context: new ToolUseContext('/tmp', 'parallel-completion-lifecycle'),
                onToolComplete: static function (string $_toolName, ToolResult $result) use (&$fastObservedSlowFinished, $slowMarker): void {
                    if ($result->output === 'fast') {
                        $fastObservedSlowFinished = is_file($slowMarker);
                    }
                },
            );
        } finally {
            @unlink($slowMarker);
            @unlink($fastMarker);
        }

        $this->assertSame(['slow', 'fast'], array_column($results, 'content'));
        $this->assertNotNull($fastObservedSlowFinished);
        $this->assertFalse(
            $fastObservedSlowFinished,
            'The fast tool completion must be observed before the slow child finishes; callback order itself is intentionally unspecified.',
        );
    }

    public function test_parallel_tool_rejects_oversized_ipc_payloads(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for parallel tool IPC.');
        }

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'LargeMetadataTool',
            static fn (): ToolResult => ToolResult::success('ok', ['blob' => str_repeat('x', 1_100_000)]),
            true,
        ));
        $completed = [];

        $results = $this->makeOrchestrator($registry)->executeTools(
            toolUseBlocks: [
                ['id' => 'large-meta-1', 'name' => 'LargeMetadataTool', 'input' => []],
                ['id' => 'large-meta-2', 'name' => 'LargeMetadataTool', 'input' => []],
            ],
            context: new ToolUseContext('/tmp', 'test-session'),
            onToolComplete: static function (string $toolName, ToolResult $result) use (&$completed): void {
                $completed[] = [$toolName, $result];
            },
        );

        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool result exceeded IPC size limit.', $results[0]['content']);
        $this->assertTrue($results[1]['is_error']);
        $this->assertSame('Tool result exceeded IPC size limit.', $results[1]['content']);
        $this->assertCount(2, $completed);
        $this->assertTrue($completed[0][1]->isError);
        $this->assertSame('Tool result exceeded IPC size limit.', $completed[0][1]->output);
        $this->assertTrue($completed[1][1]->isError);
        $this->assertSame('Tool result exceeded IPC size limit.', $completed[1][1]->output);
    }

    public function test_parallel_tool_bounds_oversized_tool_use_ids_in_fallback(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for parallel tool IPC.');
        }

        $registry = new ToolRegistry;
        $registry->register($this->makeTool(
            'LargeIdTool',
            static fn (): ToolResult => ToolResult::success('ok'),
            true,
        ));

        $id = str_repeat('x', 1_100_000);
        $completed = [];
        $results = $this->makeOrchestrator($registry)->executeTools(
            toolUseBlocks: [
                ['id' => $id, 'name' => 'LargeIdTool', 'input' => []],
                ['id' => 'small-id', 'name' => 'LargeIdTool', 'input' => []],
            ],
            context: new ToolUseContext('/tmp', 'test-session'),
            onToolComplete: static function (string $toolName, ToolResult $result) use (&$completed): void {
                $completed[] = [$toolName, $result];
            },
        );

        $this->assertTrue($results[0]['is_error']);
        $this->assertSame('Tool result exceeded IPC size limit.', $results[0]['content']);
        $this->assertLessThanOrEqual(4_096, strlen($results[0]['tool_use_id']));
        $this->assertFalse($results[1]['is_error']);
        $this->assertSame('small-id', $results[1]['tool_use_id']);
        $this->assertCount(2, $completed);
        $this->assertSame('Tool result exceeded IPC size limit.', $completed[0][1]->output);
        $this->assertSame('ok', $completed[1][1]->output);
    }

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
}
