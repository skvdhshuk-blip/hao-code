<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\ForkSafeProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\Config\ConfigTool;
use HaoCode\Tools\AskUserQuestion\AskUserQuestionTool;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

class AgentLoopFactory
{
    public function __construct(
        private readonly object $container,
    ) {}

    /**
     * Create an isolated AgentLoop for sub-agents or SDK usage.
     *
     * @param callable|null $toolFilter If provided, only tools where $toolFilter(toolName) returns true are included
     * @param string|null $workingDirectory Override working directory (e.g., for worktree isolation)
     * @param array<int, \HaoCode\Contracts\ToolInterface> $additionalTools Extra tools to register (e.g., SDK custom tools)
     * @param StreamingClient|null $streamingClient Custom API client (e.g., SDK config overrides)
     */
    public function createIsolated(
        ?callable $toolFilter = null,
        ?string $workingDirectory = null,
        array $additionalTools = [],
        ?LlmProvider $streamingClient = null,
        ?AgentRunContext $runContext = null,
        bool $ephemeral = false,
        bool $afterFork = false,
        bool $readOnly = false,
    ): AgentLoop {
        if ($readOnly && $runContext === null) {
            $projectDirectory = ($workingDirectory ?? getcwd()) ?: '/';
            $settings = clone $this->container->make(SettingsManager::class);
            $settings->set('permission_mode', 'plan');
            $runContext = new AgentRunContext(
                $projectDirectory,
                $projectDirectory,
                $settings,
                new SkillLoader($projectDirectory),
                new CancellationToken(),
            );
        }

        // Build tool registry with optional filtering
        $parentRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry = $this->buildToolRegistry(
            $parentRegistry,
            $toolFilter,
            $additionalTools !== [] || $runContext !== null,
        );
        foreach ($additionalTools as $tool) {
            $toolRegistry->register($tool);
        }

        if ($runContext?->enableAskUser) {
            $toolRegistry->register(new AskUserQuestionTool);
        }

        if ($runContext !== null) {
            $settings = $runContext->settings;
            $permissionChecker = new PermissionChecker($settings, new DenialTracker());
            $hookExecutor = new HookExecutor($runContext->projectDirectory);
            if ($toolFilter === null || $toolFilter('Skill')) {
                $toolRegistry->register(new SkillTool(
                    skillLoader: $runContext->skillLoader,
                    forkRunner: function (string $prompt, SkillDefinition $skill, ToolUseContext $context): string {
                        if ($context->runContext === null) {
                            throw new \RuntimeException('Forked skills require an active agent run context.');
                        }

                        $childContext = $context->runContext->fork($context->workingDirectory);
                        if ($skill->model !== null && trim($skill->model) !== '') {
                            $childContext->settings->set('model', trim($skill->model));
                        }

                        $allowedTools = array_values(array_unique(array_filter(array_map(
                            static function (mixed $name): string {
                                $name = trim((string) $name);
                                $patternStart = strpos($name, '(');

                                return $patternStart === false ? $name : substr($name, 0, $patternStart);
                            },
                            $skill->allowedTools,
                        ))));
                        $filter = $allowedTools === []
                            ? null
                            : static fn (string $name): bool => in_array($name, $allowedTools, true);

                        $loop = $this->createIsolated(
                            toolFilter: $filter,
                            workingDirectory: $context->workingDirectory,
                            streamingClient: $context->provider,
                            runContext: $childContext,
                            ephemeral: ! ($childContext->interruptOn !== [] || $childContext->enableAskUser),
                            afterFork: true,
                        );
                        $loop->setMaxTurns(20);

                        return $loop->run($prompt);
                    },
                ));
            }
            if ($toolFilter === null || $toolFilter('Config')) {
                $toolRegistry->register(new ConfigTool($settings));
            }
            $contextBuilder = new ContextBuilder(
                settings: $settings,
                toolRegistry: $toolRegistry,
                sessionMemory: $this->container->make(SessionMemory::class),
                skillLoader: $runContext->skillLoader,
                gitContext: new GitContext($runContext->projectDirectory),
                outputStyleLoader: new OutputStyleLoader($runContext->projectDirectory),
                workingDirectory: $runContext->projectDirectory,
                textOnly: $toolRegistry->getAllTools() === [],
            );
        } else {
            $settings = $this->container->make(SettingsManager::class);
            $contextBuilder = $this->container->make(ContextBuilder::class);
            $permissionChecker = $this->container->make(PermissionChecker::class);
            $hookExecutor = $this->container->make(HookExecutor::class);
        }

        $tracer = $this->container->make(PhoenixTracer::class);

        $client = $streamingClient ?? $this->container->make(StreamingClient::class);
        if ($afterFork && $client instanceof ForkSafeProvider) {
            $client = $client->freshAfterFork($runContext?->settings);
        }
        if ($streamingClient === null && $runContext !== null) {
            $client = $client->withSettingsManager($settings);
        }
        $queryEngine = new QueryEngine($client, $toolRegistry, $tracer, $settings);

        $toolOrchestrator = new ToolOrchestrator(
            toolRegistry: $toolRegistry,
            permissionChecker: $permissionChecker,
            hookExecutor: $hookExecutor,
            tracer: $tracer,
        );
        if ($runContext !== null) {
            $toolOrchestrator->configureHumanInterrupts($runContext->interruptOn, $runContext->enableAskUser);
            $toolOrchestrator->enablePermissionInterrupts(
                ! $ephemeral && $runContext->settings->getPermissionMode() !== \HaoCode\Services\Permissions\PermissionMode::BypassPermissions,
            );
        }

        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory(),
            permissionChecker: $permissionChecker,
            sessionManager: new SessionManager(persistenceEnabled: ! $ephemeral),
            contextCompactor: new ContextCompactor($queryEngine, $hookExecutor),
            costTracker: new CostTracker(),
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
            tracer: $tracer,
            cancellationToken: $runContext?->cancellationToken,
            maxEstimatedInputTokens: ContextBudget::safeInputLimit(
                $settings->getContextWindow(),
                $settings->getMaxTokens(),
            ),
            runContext: $runContext,
            provider: $client,
        );

        if ($workingDirectory !== null) {
            $loop->setWorkingDirectory($workingDirectory);
        }

        return $loop;
    }

    /**
     * Build a filtered ToolRegistry from the parent registry.
     */
    private function buildToolRegistry(ToolRegistry $parent, ?callable $filter, bool $forceClone = false): ToolRegistry
    {
        if ($filter === null && ! $forceClone) {
            return $parent;
        }

        $filtered = new ToolRegistry();

        foreach ($parent->getAllTools() as $tool) {
            if ($filter === null || $filter($tool->name())) {
                $filtered->register(clone $tool);
            }
        }

        return $filtered;
    }
}
