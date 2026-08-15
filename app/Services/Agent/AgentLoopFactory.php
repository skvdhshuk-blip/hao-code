<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\ForkSafeProvider;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\SettingsAwareProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\Config\ConfigTool;
use HaoCode\Tools\Memory\MemoryDeleteTool;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\Memory\MemoryWriteTool;
use HaoCode\Tools\AskUserQuestion\AskUserQuestionTool;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillLoader;
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
     * @param array<int, \HaoCode\Contracts\ToolInterface> $replacementTools Framework-owned backend substitutions
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
        ?callable $additionalToolFilter = null,
        ?ToolRegistry $parentToolRegistry = null,
        ?string $model = null,
        ?string $appendSystemPrompt = null,
        bool $omitProjectInstructions = false,
        ?string $agentType = null,
        array $replacementTools = [],
        ?AgentRunContext $parentRunContext = null,
        ?RunLimits $limits = null,
    ): AgentLoop {
        return $this->createInvocation(new AgentLoopSpec(
            toolFilter: $toolFilter,
            workingDirectory: $workingDirectory,
            additionalTools: $additionalTools,
            provider: $streamingClient,
            runContext: $runContext,
            ephemeral: $ephemeral,
            afterFork: $afterFork,
            readOnly: $readOnly,
            additionalToolFilter: $additionalToolFilter,
            parentToolRegistry: $parentToolRegistry,
            parentRunContext: $parentRunContext,
            model: $model,
            appendSystemPrompt: $appendSystemPrompt,
            omitProjectInstructions: $omitProjectInstructions,
            agentType: $agentType,
            replacementTools: $replacementTools,
            limits: $limits,
        ));
    }

    /**
     * Canonical assembly entry for root and nested agent loops.
     *
     * @internal
     */
    public function createInvocation(AgentLoopSpec $invocation): AgentLoop
    {
        $toolFilter = $invocation->toolFilter;
        $workingDirectory = $invocation->workingDirectory;
        $additionalTools = $invocation->additionalTools;
        $streamingClient = $invocation->provider;
        $runContext = $invocation->runContext;
        $ephemeral = $invocation->ephemeral;
        $afterFork = $invocation->afterFork;
        $readOnly = $invocation->readOnly;
        $additionalToolFilter = $invocation->additionalToolFilter;
        $parentToolRegistry = $invocation->parentToolRegistry;
        $model = $invocation->model;
        $appendSystemPrompt = $invocation->appendSystemPrompt;
        $omitProjectInstructions = $invocation->omitProjectInstructions;
        $agentType = $invocation->agentType;
        $replacementTools = $invocation->replacementTools;

        $requiresScopedContext = $readOnly
            || $model !== null
            || (is_string($appendSystemPrompt) && trim($appendSystemPrompt) !== '')
            || $omitProjectInstructions
            || $agentType !== null;

        if ($runContext !== null && $requiresScopedContext) {
            $runContext = $runContext->fork(
                workingDirectory: $workingDirectory,
                readOnly: $readOnly,
                omitProjectInstructions: $omitProjectInstructions,
                agentType: $agentType,
            );
        } elseif ($runContext === null && $requiresScopedContext) {
            $projectDirectory = ($workingDirectory ?? getcwd()) ?: '/';
            $settings = clone $this->container->make(SettingsManager::class);
            if ($readOnly) {
                $settings->set('permission_mode', 'plan');
            }
            $runContext = new AgentRunContext(
                $projectDirectory,
                $projectDirectory,
                $settings,
                new SkillLoader($projectDirectory),
                new CancellationToken(),
                memoryStore: new JsonMemoryStore($settings->getMemoryStoragePath()),
                memoryTools: ['MemoryRead'],
                omitProjectInstructions: $omitProjectInstructions,
                agentType: $agentType,
                readOnly: $readOnly,
            );
        }

        if ($runContext !== null) {
            if ($model !== null) {
                $runContext->settings->set('model', $model);
            }
            if (is_string($appendSystemPrompt) && trim($appendSystemPrompt) !== '') {
                $existing = trim((string) $runContext->settings->getAppendSystemPrompt());
                $runContext->settings->set(
                    'append_system_prompt',
                    $existing === '' ? trim($appendSystemPrompt) : $existing."\n\n".trim($appendSystemPrompt),
                );
            }
        }

        // Build tool registry with optional filtering
        // Child loops must derive from the parent's already-filtered registry.
        // Rebuilding from the process-global registry would let a child agent
        // regain tools denied by the SDK run and would replace sandbox tool
        // implementations with their host equivalents.
        $parentRegistry = $parentToolRegistry ?? $this->container->make(ToolRegistry::class);
        $parentTools = $parentToolRegistry?->getAllTools();
        $toolRegistry = $this->buildToolRegistry(
            $parentRegistry,
            $toolFilter,
            $additionalTools !== [] || $replacementTools !== [] || $runContext !== null,
        );
        // Framework-owned backend substitutions are the only registrations
        // allowed to replace an existing capability identity.
        $additionalFilter = $additionalToolFilter ?? $toolFilter ?? static fn (string $name): bool => true;
        if ($toolFilter !== null && $additionalToolFilter !== null) {
            $additionalFilter = static function (string $name) use ($toolFilter, $additionalToolFilter): bool {
                return $toolFilter($name) && $additionalToolFilter($name);
            };
        }
        foreach ($replacementTools as $tool) {
            if ($additionalFilter($tool->name())) {
                if ($parentTools !== null) {
                    $parentTool = $parentTools[$tool->name()] ?? null;
                    if ($parentTool === null) {
                        throw new \LogicException(
                            "Nested agent cannot add replacement capability '{$tool->name()}' absent from its parent.",
                        );
                    }
                    if ($parentTool::class !== $tool::class) {
                        throw new \LogicException(
                            "Nested agent cannot replace parent capability '{$tool->name()}' with another implementation.",
                        );
                    }

                    continue;
                }
                $toolRegistry->replace($tool);
            }
        }

        // Register additional SDK/MCP tools through the same final capability filter as the
        // built-in registry. Keep the explicit additionalToolFilter hook for
        // compatibility, but use toolFilter as the defense-in-depth default
        // when callers do not provide one.
        foreach ($additionalTools as $tool) {
            if ($additionalFilter($tool->name())) {
                if ($parentTools !== null) {
                    $parentTool = $parentTools[$tool->name()] ?? null;
                    if ($parentTool === null) {
                        throw new \LogicException(
                            "Nested agent cannot add capability '{$tool->name()}' absent from its parent.",
                        );
                    }
                    if ($parentTool::class !== $tool::class) {
                        throw new \LogicException(
                            "Nested agent cannot replace parent capability '{$tool->name()}' with another implementation.",
                        );
                    }

                    continue;
                }
                $toolRegistry->register($tool);
            }
        }

        $parentAllows = static fn (string $name): bool => $parentTools === null || isset($parentTools[$name]);

        if ($runContext !== null) {
            $memoryStore = $runContext->memoryStore ?? new JsonMemoryStore;
            foreach (['MemoryRead', 'MemoryWrite', 'MemoryDelete'] as $memoryToolName) {
                $toolRegistry->unregister($memoryToolName);
            }
            foreach ([
                new MemoryReadTool($memoryStore),
                new MemoryWriteTool($memoryStore),
                new MemoryDeleteTool($memoryStore),
            ] as $memoryTool) {
                if ($parentAllows($memoryTool->name())
                    && in_array($memoryTool->name(), $runContext->memoryTools, true)
                    && ($toolFilter === null || $toolFilter($memoryTool->name()))) {
                    $toolRegistry->register($memoryTool);
                }
            }
        }

        if ($runContext?->enableAskUser && $parentAllows('AskUserQuestion')) {
            // A nested registry may already contain the parent's AskUser
            // capability. Keep that exact implementation instead of treating
            // inherited authority as a new registration.
            if (! $toolRegistry->has('AskUserQuestion')) {
                $toolRegistry->register(new AskUserQuestionTool);
            }
        }

        if ($runContext !== null) {
            $settings = $runContext->settings;
            $permissionChecker = new PermissionChecker($settings, new DenialTracker());
            $hookExecutor = new HookExecutor($runContext->projectDirectory);
            if ($parentAllows('Skill') && ($toolFilter === null || $toolFilter('Skill'))) {
                $skillTool = new SkillTool(
                    skillLoader: $runContext->skillLoader,
                    forkRunner: function (string $prompt, SkillDefinition $skill, ToolUseContext $context): string {
                        if ($context->runContext === null) {
                            throw new \RuntimeException('Forked skills require an active agent run context.');
                        }

                        $childContext = $context->runContext->fork($context->workingDirectory);
                        if ($skill->model !== null && trim($skill->model) !== '') {
                            $resolvedModel = \HaoCode\Tools\Skill\SkillModelResolver::resolve(
                                trim($skill->model),
                                $childContext->settings->getProviderType(),
                            );
                            if ($resolvedModel !== null) {
                                $childContext->settings->set('model', $resolvedModel);
                            }
                        }

                        $capabilitySpecs = \HaoCode\Tools\Skill\SkillCapability::normalizeSpecs($skill->allowedTools);
                        $filter = $capabilitySpecs === []
                            ? null
                            : static function (string $name) use ($capabilitySpecs): bool {
                                // Fork filter only sees tool names (no per-call input).
                                // Pattern enforcement still happens inside the child
                                // orchestrator when the skill scope is activated.
                                return in_array(
                                    $name,
                                    \HaoCode\Tools\Skill\SkillCapability::toolNames($capabilitySpecs),
                                    true,
                                );
                            };

                        $loop = $this->createIsolated(
                            toolFilter: $filter,
                            workingDirectory: $context->workingDirectory,
                            streamingClient: $context->provider,
                            runContext: $childContext,
                            ephemeral: ! ($childContext->interruptOn !== [] || $childContext->enableAskUser),
                            afterFork: true,
                            parentToolRegistry: $context->toolRegistry,
                            parentRunContext: $context->runContext,
                            limits: RunLimits::turns(20),
                        );
                        // Enforce patterned capability rules for the whole child
                        // run (toolFilter only sees names, not Bash commands).
                        if ($capabilitySpecs !== []) {
                            $loop->setBaseSkillScope($capabilitySpecs);
                        }

                        return (new AgentInvocation($prompt))->invoke($loop)->text;
                    },
                );
                if ($toolRegistry->has('Skill')) {
                    $toolRegistry->replace($skillTool);
                } else {
                    $toolRegistry->register($skillTool);
                }
            }
            if ($parentAllows('Config') && ($toolFilter === null || $toolFilter('Config'))) {
                $configTool = new ConfigTool($settings);
                if ($toolRegistry->has('Config')) {
                    $toolRegistry->replace($configTool);
                } else {
                    $toolRegistry->register($configTool);
                }
            }
            $contextBuilder = new ContextBuilder(
                settings: $settings,
                toolRegistry: $toolRegistry,
                memoryStore: $runContext->memoryStore ?? new JsonMemoryStore,
                skillLoader: $runContext->skillLoader,
                contextPreset: $runContext->contextPreset === ContextPreset::GENERIC
                    ? new GenericContextPreset()
                    : new CodingContextPreset(
                        gitContext: new GitContext($runContext->projectDirectory),
                        workingDirectory: $runContext->projectDirectory,
                        omitProjectInstructions: $runContext->omitProjectInstructions,
                    ),
                outputStyleLoader: new OutputStyleLoader($runContext->projectDirectory),
                textOnly: $toolRegistry->getAllTools() === [],
                includeMemoryInTextOnly: $runContext->includeMemoryInTextOnly,
            );
        } else {
            $settings = $this->container->make(SettingsManager::class);
            $contextBuilder = $this->container->make(ContextBuilder::class);
            $permissionChecker = $this->container->make(PermissionChecker::class);
            $hookExecutor = $this->container->make(HookExecutor::class);
        }

        $tracer = $this->container->make(PhoenixTracer::class);

        $client = $streamingClient ?? $this->container->make(StreamingClient::class);
        if ($model !== null && $client instanceof PooledProvider) {
            $client = $client->requiringScopedSettings();
        }
        if ($afterFork && $client instanceof ForkSafeProvider) {
            $client = $client->freshAfterFork($runContext?->settings);
        } elseif ($runContext !== null && $client instanceof SettingsAwareProvider) {
            $client = $client->withSettingsManager($settings);
        } elseif ($model !== null && $streamingClient !== null) {
            throw new \RuntimeException(
                'Agent model override requires a settings-aware provider.',
            );
        }
        $costTracker = new CostTracker(
            budgetLedger: $runContext?->budgetLedger,
            usageAccumulator: $runContext?->usageAccumulator,
        );
        $costTracker->setProviderContext($settings->getProviderType(), $settings->getModel());
        $queryEngine = new QueryEngine($client, $toolRegistry, $tracer, $settings, $costTracker);

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

        $maxEstimatedInputTokens = ContextBudget::safeInputLimit(
            $settings->getContextWindow(),
            $settings->getMaxTokens(),
        );

        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory(),
            permissionChecker: $permissionChecker,
            sessionManager: new SessionManager(persistenceEnabled: ! $ephemeral),
            contextCompactor: new ContextCompactor(
                $queryEngine,
                $hookExecutor,
                $settings->getContextWindow(),
                $maxEstimatedInputTokens,
            ),
            costTracker: $costTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
            tracer: $tracer,
            cancellationToken: $runContext?->cancellationToken,
            maxEstimatedInputTokens: $maxEstimatedInputTokens,
            runContext: $runContext,
            provider: $client,
        );

        if ($workingDirectory !== null) {
            $loop->setWorkingDirectory($workingDirectory);
        }
        if ($invocation->limits !== null) {
            $loop->setMaxTurns($invocation->limits->maxTurns);
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
                // SdkTool's public contract does not require cloneability.
                // Clone when the object supports it so mutable tool state is
                // isolated; otherwise retain the valid shared instance.
                $reflection = new \ReflectionObject($tool);
                $filtered->register($reflection->isCloneable() ? clone $tool : $tool);
            }
        }

        return $filtered;
    }
}
