<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use Illuminate\Contracts\Container\Container;
use HaoCode\Tools\ToolRegistry;
use Tests\TestCase;

class AgentLoopFactoryTest extends TestCase
{
    public function test_it_creates_isolated_agent_loops(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $toolRegistry = new ToolRegistry();
        $hookExecutor = $this->createMock(HookExecutor::class);

        $container = $this->createMock(Container::class);
        $container->method('make')
            ->willReturnCallback(function (string $abstract) use (
                $queryEngine,
                $toolOrchestrator,
                $contextBuilder,
                $permissionChecker,
                $toolRegistry,
                $hookExecutor,
            ) {
                return match ($abstract) {
                    QueryEngine::class => $queryEngine,
                    ToolOrchestrator::class => $toolOrchestrator,
                    ContextBuilder::class => $contextBuilder,
                    PermissionChecker::class => $permissionChecker,
                    ToolRegistry::class => $toolRegistry,
                    HookExecutor::class => $hookExecutor,
                    \HaoCode\Services\Telemetry\PhoenixTracer::class => \HaoCode\Services\Telemetry\PhoenixTracer::fromConfig(['enabled' => false]),
                    \HaoCode\Services\Settings\SettingsManager::class => new \HaoCode\Services\Settings\SettingsManager(),
                    default => throw new \RuntimeException("Unexpected container resolution: {$abstract}"),
                };
            });

        $factory = new AgentLoopFactory(
            container: $container,
        );

        $first = $factory->createIsolated();
        $second = $factory->createIsolated();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->getMessageHistory(), $second->getMessageHistory());
        $this->assertNotSame($first->getSessionManager(), $second->getSessionManager());
        $this->assertNotSame($first->getCostTracker(), $second->getCostTracker());
    }
}
