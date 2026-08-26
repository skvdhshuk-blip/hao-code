<?php

namespace Tests\Unit;

use HaoCode\Sdk\SdkTool;
use HaoCode\Services\Agent\StreamingToolExecutor;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

trait StreamingToolExecutorTestSdkToolDefaultsConcern
{
    public function test_read_only_sdk_tool_without_concurrency_opt_in_stays_sequential(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class extends SdkTool {
            public function name(): string { return 'SdkLookup'; }
            public function description(): string { return 'lookup'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { return 'ok'; }
            public function isReadOnly(array $input): bool { return true; }
        });

        $expected = ['tool_use_id' => 'sdk-lookup', 'content' => 'ok', 'is_error' => false];
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn($expected);

        $executor = new StreamingToolExecutor($orchestrator, $registry);
        $executor->setContext(new ToolUseContext('/tmp', 'sdk-tool-sequential-default'), null, null);
        $executor->onToolBlockReady([
            'id' => 'sdk-lookup',
            'name' => 'SdkLookup',
            'input' => [],
        ], 0);

        $this->assertFalse($executor->hasEarlyExecutions());
        $this->assertSame([$expected], $executor->collectResults());
    }
}
