<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\SettingsAwareProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Sdk\SdkTool;
use HaoCode\Tools\Skill\SkillLoader;
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

    public function test_non_cloneable_sdk_tools_remain_available_in_child_registry(): void
    {
        $tool = new class extends SdkTool {
            private function __clone() {}

            public function name(): string { return 'NonCloneable'; }
            public function description(): string { return 'A valid non-cloneable SDK tool'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { return 'ok'; }
        };
        $registry = new ToolRegistry();
        $registry->register($tool);

        $factory = new AgentLoopFactory(container: $this->buildContainer($registry));
        $method = new \ReflectionMethod($factory, 'buildToolRegistry');
        $childRegistry = $method->invoke($factory, $registry, null, true);

        $this->assertSame($tool, $childRegistry->getTool('NonCloneable'));
    }

    public function test_cloneable_stateful_sdk_tools_receive_independent_child_instances(): void
    {
        $tool = new class extends SdkTool {
            public int $calls = 0;

            public function name(): string { return 'Stateful'; }
            public function description(): string { return 'A stateful SDK tool'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { return (string) ++$this->calls; }
        };
        $registry = new ToolRegistry();
        $registry->register($tool);

        $factory = new AgentLoopFactory(container: $this->buildContainer($registry));
        $method = new \ReflectionMethod($factory, 'buildToolRegistry');
        $childRegistry = $method->invoke($factory, $registry, null, true);
        $childTool = $childRegistry->getTool('Stateful');

        $this->assertNotSame($tool, $childTool);
        $childTool->handle([]);
        $this->assertSame(0, $tool->calls);
        $this->assertSame(1, $childTool->calls);
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

    public function test_agent_overrides_are_scoped_to_child_settings_and_provider(): void
    {
        $root = sys_get_temp_dir().'/haocode-agent-factory-settings-'.bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        $parentSettings = new SettingsManager($root);
        $parentSettings->set('api_base_url', 'https://api.anthropic.com');
        $parentSettings->set('append_system_prompt', 'Parent append');
        file_put_contents($root.'/AGENTS.md', 'Project-only instructions must be omitted.');
        $runContext = new AgentRunContext(
            workingDirectory: $root,
            projectDirectory: $root,
            settings: $parentSettings,
            skillLoader: new SkillLoader($root),
            cancellationToken: new CancellationToken(),
            memoryStore: new JsonMemoryStore($root.'/memory'),
        );
        $provider = new class implements SettingsAwareProvider {
            public ?SettingsManager $settings = null;

            public function withSettingsManager(SettingsManager $settingsManager): LlmProvider
            {
                $copy = clone $this;
                $copy->settings = $settingsManager;

                return $copy;
            }

            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                if (false) {
                    yield;
                }
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };

        try {
            $factory = new AgentLoopFactory(container: $this->buildContainer(new ToolRegistry()));
            $loop = $factory->createIsolated(
                workingDirectory: $root,
                streamingClient: $provider,
                runContext: $runContext,
                model: 'claude-haiku-4-5-20251001',
                appendSystemPrompt: 'Child agent instructions',
                omitProjectInstructions: true,
            );

            $contextProperty = new \ReflectionProperty($loop, 'runContext');
            /** @var AgentRunContext $childContext */
            $childContext = $contextProperty->getValue($loop);
            $this->assertNotSame($runContext, $childContext);
            $this->assertSame('claude-haiku-4-5-20251001', $childContext->settings->getModel());
            $this->assertSame(
                "Parent append\n\nChild agent instructions",
                $childContext->settings->getAppendSystemPrompt(),
            );
            $this->assertTrue($childContext->omitProjectInstructions);
            $this->assertNotSame(
                $childContext->settings->getModel(),
                $parentSettings->getModel(),
            );

            $providerProperty = new \ReflectionProperty($loop, 'provider');
            $childProvider = $providerProperty->getValue($loop);
            $this->assertSame($childContext->settings, $childProvider->settings);

            $builderProperty = new \ReflectionProperty($loop, 'contextBuilder');
            /** @var ContextBuilder $builder */
            $builder = $builderProperty->getValue($loop);
            $prompt = $builder->buildSystemPrompt()[0]['text'];
            $this->assertStringNotContainsString('Project-only instructions must be omitted.', $prompt);
        } finally {
            $this->removeDirectory($root);
        }
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

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
