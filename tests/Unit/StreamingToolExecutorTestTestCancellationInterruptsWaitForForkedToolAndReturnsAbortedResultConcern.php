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

trait StreamingToolExecutorTestTestCancellationInterruptsWaitForForkedToolAndReturnsAbortedResultConcern
{

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
