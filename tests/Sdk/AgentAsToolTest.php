<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\AgentAsTool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RunOptions;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;
use Tests\TestCase;

class AgentAsToolTest extends TestCase
{
    public function test_agent_as_tool_is_not_read_only_or_concurrency_safe(): void
    {
        $agent = new Agent(name: 'child', allowedTools: []);
        $tool = $agent->asTool('ChildAgent', 'Delegates to child');

        $this->assertInstanceOf(AgentAsTool::class, $tool);
        $this->assertFalse($tool->isReadOnly([]));
        $this->assertFalse($tool->isConcurrencySafe([]));
    }

    public function test_agent_as_tool_is_denied_in_plan_mode(): void
    {
        $tool = (new Agent(name: 'child', allowedTools: []))->asTool('ChildAgent', 'child');
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::Plan);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn([]);
        $checker = new PermissionChecker($settings, new DenialTracker);
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'agent-as-tool-plan',
        );

        $this->assertFalse($checker->check($tool, ['task' => 'hi'], $context)->allowed);
    }

    public function test_run_options_inherit_agent_ephemeral_when_unspecified(): void
    {
        $agent = new Agent(name: 'durable', ephemeral: false, allowedTools: []);
        $options = new RunOptions();

        $this->assertNull($options->ephemeral);
        $this->assertFalse($options->effectiveEphemeral($agent));

        $config = $options->toConfig($agent);
        $this->assertFalse($config->ephemeral);
    }

    public function test_run_options_can_override_agent_ephemeral(): void
    {
        $agent = new Agent(name: 'durable', ephemeral: false, allowedTools: []);
        $options = new RunOptions(ephemeral: true);

        $this->assertTrue($options->effectiveEphemeral($agent));
        $this->assertTrue($options->toConfig($agent)->ephemeral);
    }

    public function test_invalid_hitl_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hitlMode');
        new HaoCodeConfig(hitlMode: 'aks');
    }

    public function test_agent_as_tool_appends_abort_pump_without_clobbering_existing(): void
    {
        $mcpCalls = 0;
        $abortCalls = 0;

        $loop = $this->createMock(\HaoCode\Services\Agent\AgentLoop::class);
        $session = $this->createMock(\HaoCode\Services\Session\SessionManager::class);
        $session->method('getSessionId')->willReturn('child-sess');
        $loop->method('getSessionManager')->willReturn($session);
        $loop->method('getLocalInputTokens')->willReturn(1);
        $loop->method('getLocalOutputTokens')->willReturn(1);
        $loop->method('getLocalEstimatedCost')->willReturn(0.0);
        $loop->method('setWorkingDirectory');
        $loop->method('run')->willReturn('ok');

        // Real AgentLoop is needed to verify appendEventPump composition.
        $realLoop = new \HaoCode\Services\Agent\AgentLoop(
            queryEngine: $this->createMock(\HaoCode\Services\Agent\QueryEngine::class),
            toolOrchestrator: $this->createMock(\HaoCode\Services\Agent\ToolOrchestrator::class),
            contextBuilder: $this->createMock(\HaoCode\Services\Agent\ContextBuilder::class),
            messageHistory: new \HaoCode\Services\Agent\MessageHistory,
            permissionChecker: $this->createMock(\HaoCode\Services\Permissions\PermissionChecker::class),
            sessionManager: new \HaoCode\Services\Session\SessionManager(persistenceEnabled: false),
            contextCompactor: $this->createMock(\HaoCode\Services\Compact\ContextCompactor::class),
            costTracker: new \HaoCode\Services\Cost\CostTracker,
            toolRegistry: new \HaoCode\Tools\ToolRegistry,
        );

        // Simulate MCP poll installed by SdkRunFactory.
        $realLoop->setEventPump(static function () use (&$mcpCalls): void {
            $mcpCalls++;
        });
        $realLoop->appendEventPump(static function () use (&$abortCalls): void {
            $abortCalls++;
        });

        $pump = new \ReflectionProperty($realLoop, 'eventPump');
        $pump->setAccessible(true);
        $composed = $pump->getValue($realLoop);
        $this->assertInstanceOf(\Closure::class, $composed);
        $composed();
        $this->assertSame(1, $mcpCalls, 'MCP poll pump must still run after append');
        $this->assertSame(1, $abortCalls, 'parent abort pump must run after append');

        // AgentAsTool must call appendEventPump (not setEventPump) when shouldAbort is set.
        $loop->expects($this->once())->method('appendEventPump');
        $loop->expects($this->never())->method('setEventPump');

        $factory = $this->createMock(\HaoCode\Services\Agent\AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        \HaoCode\Support\Runtime\SdkRuntime::app()->instance(
            \HaoCode\Services\Agent\AgentLoopFactory::class,
            $factory,
        );

        $tool = (new Agent(
            name: 'child',
            apiKey: 'test-key',
            allowedTools: [],
            ephemeral: true,
        ))->asTool('Child', 'child agent');

        $aborted = false;
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'parent-sess',
            shouldAbort: static function () use (&$aborted): bool {
                return $aborted;
            },
        );

        $result = $tool->call(['task' => 'do work'], $context);
        $this->assertFalse($result->isError, $result->output);
    }

    public function test_agent_as_tool_uses_parent_working_directory(): void
    {
        $parentCwd = sys_get_temp_dir().'/haocode-agent-as-tool-cwd-'.bin2hex(random_bytes(4));
        mkdir($parentCwd, 0777, true);

        $loop = $this->createMock(\HaoCode\Services\Agent\AgentLoop::class);
        $session = $this->createMock(\HaoCode\Services\Session\SessionManager::class);
        $session->method('getSessionId')->willReturn('child-sess');
        $loop->method('getSessionManager')->willReturn($session);
        $loop->method('getLocalInputTokens')->willReturn(1);
        $loop->method('getLocalOutputTokens')->willReturn(1);
        $loop->method('getLocalEstimatedCost')->willReturn(0.0);
        $loop->expects($this->once())
            ->method('setWorkingDirectory')
            ->with($parentCwd);
        $loop->method('run')->willReturn('child-ok');

        $factory = $this->createMock(\HaoCode\Services\Agent\AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        \HaoCode\Support\Runtime\SdkRuntime::app()->instance(
            \HaoCode\Services\Agent\AgentLoopFactory::class,
            $factory,
        );

        $tool = (new Agent(
            name: 'child',
            apiKey: 'test-key',
            allowedTools: [],
            ephemeral: true,
        ))->asTool('Child', 'child agent');

        $context = new ToolUseContext(
            workingDirectory: $parentCwd,
            sessionId: 'parent-sess',
        );

        $result = $tool->call(['task' => 'do work'], $context);
        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('child-ok', $result->output);

        @rmdir($parentCwd);
    }
}
