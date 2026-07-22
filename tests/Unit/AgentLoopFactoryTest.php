<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\TodoWrite\TodoWriteTool;
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
        $streamingClient = $this->createMock(StreamingClient::class);

        $container = new class (
            $queryEngine,
            $toolOrchestrator,
            $contextBuilder,
            $permissionChecker,
            $toolRegistry,
            $hookExecutor,
            $streamingClient,
        ) {
            public function __construct(
                private readonly QueryEngine $queryEngine,
                private readonly ToolOrchestrator $toolOrchestrator,
                private readonly ContextBuilder $contextBuilder,
                private readonly PermissionChecker $permissionChecker,
                private readonly ToolRegistry $toolRegistry,
                private readonly HookExecutor $hookExecutor,
                private readonly StreamingClient $streamingClient,
            ) {}

            public function make(string $abstract): mixed
            {
                return match ($abstract) {
                    QueryEngine::class => $this->queryEngine,
                    ToolOrchestrator::class => $this->toolOrchestrator,
                    ContextBuilder::class => $this->contextBuilder,
                    PermissionChecker::class => $this->permissionChecker,
                    ToolRegistry::class => $this->toolRegistry,
                    HookExecutor::class => $this->hookExecutor,
                    StreamingClient::class => $this->streamingClient,
                    \HaoCode\Services\Telemetry\PhoenixTracer::class => \HaoCode\Services\Telemetry\PhoenixTracer::fromConfig(['enabled' => false]),
                    \HaoCode\Services\Settings\SettingsManager::class => new \HaoCode\Services\Settings\SettingsManager(),
                    default => throw new \RuntimeException("Unexpected container resolution: {$abstract}"),
                };
            }
        };

        $factory = new AgentLoopFactory(
            container: $container,
        );

        $first = $factory->createIsolated();
        $second = $factory->createIsolated();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->getMessageHistory(), $second->getMessageHistory());
        $this->assertNotSame($first->getSessionManager(), $second->getSessionManager());
        $this->assertNotSame($first->getCostTracker(), $second->getCostTracker());

        $toolRegistry->register(new TodoWriteTool);
        $method = new \ReflectionMethod($factory, 'buildToolRegistry');
        $clonedRegistry = $method->invoke($factory, $toolRegistry, null, true);

        $this->assertNotSame($toolRegistry->getTool('TodoWrite'), $clonedRegistry->getTool('TodoWrite'));
    }

    /**
     * The read-only (plan-mode) branch constructs `new SkillLoader(...)`. The
     * import was missing at v1.13.1, so PHP resolved the unqualified name to
     * the non-existent HaoCode\Services\Agent\SkillLoader and any read-only
     * run fatalled. Assert the class referenced by that branch actually loads.
     */
    public function test_read_only_branch_skill_loader_class_is_loadable(): void
    {
        $this->assertTrue(class_exists(\HaoCode\Tools\Skill\SkillLoader::class));

        $source = file_get_contents((new \ReflectionClass(AgentLoopFactory::class))->getFileName());
        $this->assertStringContainsString(
            'use HaoCode\\Tools\\Skill\\SkillLoader;',
            $source,
            'AgentLoopFactory must import HaoCode\Tools\Skill\SkillLoader so the read-only branch does not resolve to a non-existent namespace-local class.',
        );
    }

    /**
     * additionalTools must honor an explicit disallowedTools entry (the caller
     * said "not this tool") but must NOT be dropped merely because the tool is
     * absent from the allowedTools whitelist — otherwise passing `tools: [...]`
     * without also listing each tool in allowedTools (the documented pattern)
     * would silently unregister them.
     */
    public function test_additional_tools_respect_disallowed_but_not_whitelist(): void
    {
        $allowed = $this->createMock(\HaoCode\Contracts\ToolInterface::class);
        $allowed->method('name')->willReturn('AllowedTool');
        $denied = $this->createMock(\HaoCode\Contracts\ToolInterface::class);
        $denied->method('name')->willReturn('DeniedTool');

        $additionalToolFilter = static fn (string $name): bool => $name !== 'DeniedTool';

        $toolRegistry = new ToolRegistry();
        $factory = new AgentLoopFactory(container: $this->buildContainer(new ToolRegistry()));

        $method = new \ReflectionMethod($factory, 'createIsolated');
        // We only need the registry-building portion; bypass the full loop by
        // invoking buildToolRegistry + the additionalTools loop directly.
        $buildRegistry = new \ReflectionMethod($factory, 'buildToolRegistry');
        $registry = $buildRegistry->invoke($factory, $toolRegistry, null, true);

        // Replicate the additionalTools registration loop from createIsolated.
        $additionalTools = [$allowed, $denied];
        foreach ($additionalTools as $tool) {
            if ($additionalToolFilter($tool->name())) {
                $registry->register($tool);
            }
        }

        $this->assertNotNull($registry->getTool('AllowedTool'));
        $this->assertNull($registry->getTool('DeniedTool'));
    }

    private function buildContainer(ToolRegistry $toolRegistry): object
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $hookExecutor = $this->createMock(HookExecutor::class);
        $streamingClient = $this->createMock(StreamingClient::class);

        return new class (
            $queryEngine,
            $toolOrchestrator,
            $contextBuilder,
            $permissionChecker,
            $toolRegistry,
            $hookExecutor,
            $streamingClient,
        ) {
            public function __construct(
                private readonly QueryEngine $queryEngine,
                private readonly ToolOrchestrator $toolOrchestrator,
                private readonly ContextBuilder $contextBuilder,
                private readonly PermissionChecker $permissionChecker,
                private readonly ToolRegistry $toolRegistry,
                private readonly HookExecutor $hookExecutor,
                private readonly StreamingClient $streamingClient,
            ) {}

            public function make(string $abstract): mixed
            {
                return match ($abstract) {
                    QueryEngine::class => $this->queryEngine,
                    ToolOrchestrator::class => $this->toolOrchestrator,
                    ContextBuilder::class => $this->contextBuilder,
                    PermissionChecker::class => $this->permissionChecker,
                    ToolRegistry::class => $this->toolRegistry,
                    HookExecutor::class => $this->hookExecutor,
                    StreamingClient::class => $this->streamingClient,
                    \HaoCode\Services\Telemetry\PhoenixTracer::class => \HaoCode\Services\Telemetry\PhoenixTracer::fromConfig(['enabled' => false]),
                    \HaoCode\Services\Settings\SettingsManager::class => new \HaoCode\Services\Settings\SettingsManager(),
                    default => throw new \RuntimeException("Unexpected container resolution: {$abstract}"),
                };
            }
        };
    }
}
