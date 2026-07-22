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
     * An explicit additional-tool filter cannot bypass the main capability
     * filter. This mirrors the two-filter defense-in-depth path in
     * AgentLoopFactory::createIsolated().
     */
    public function test_additional_tools_apply_the_main_filter_before_the_hook(): void
    {
        $allowed = $this->createMock(\HaoCode\Contracts\ToolInterface::class);
        $allowed->method('name')->willReturn('AllowedTool');
        $notWhitelisted = $this->createMock(\HaoCode\Contracts\ToolInterface::class);
        $notWhitelisted->method('name')->willReturn('NotWhitelistedTool');
        $hookDenied = $this->createMock(\HaoCode\Contracts\ToolInterface::class);
        $hookDenied->method('name')->willReturn('HookDeniedTool');

        $toolFilter = static fn (string $name): bool => in_array($name, ['AllowedTool', 'HookDeniedTool'], true);
        $additionalToolFilter = static fn (string $name): bool => $name !== 'HookDeniedTool';

        $factory = new AgentLoopFactory(container: $this->buildContainer(new ToolRegistry()));
        $loop = $factory->createIsolated(
            toolFilter: $toolFilter,
            additionalTools: [$allowed, $notWhitelisted, $hookDenied],
            streamingClient: $this->createMock(StreamingClient::class),
            additionalToolFilter: $additionalToolFilter,
        );
        $registryProperty = new \ReflectionProperty($loop, 'toolRegistry');
        /** @var ToolRegistry $registry */
        $registry = $registryProperty->getValue($loop);

        $this->assertNotNull($registry->getTool('AllowedTool'));
        $this->assertNull($registry->getTool('NotWhitelistedTool'));
        $this->assertNull($registry->getTool('HookDeniedTool'));
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
