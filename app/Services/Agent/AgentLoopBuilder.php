<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Services\Api\ForkSafeProvider;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\SettingsAwareProvider;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;

/** Owns run-context normalization and concrete AgentLoop construction. @internal */
final class AgentLoopBuilder
{
    public function __construct(
        private readonly SettingsManager $baseSettings,
        private readonly ContextBuilder $baseContextBuilder,
        private readonly PermissionChecker $basePermissionChecker,
        private readonly HookExecutor $baseHookExecutor,
        private readonly PhoenixTracer $tracer,
        private readonly LlmProvider $baseProvider,
        private readonly string $systemPromptPath,
        private readonly ?string $globalSettingsPath = null,
    ) {}

    public function prepareRunContext(AgentLoopSpec $invocation): ?AgentRunContext
    {
        $context = $invocation->runContext;
        $requiresScope = $invocation->readOnly
            || $invocation->model !== null
            || (is_string($invocation->appendSystemPrompt)
                && trim($invocation->appendSystemPrompt) !== '')
            || $invocation->omitProjectInstructions
            || $invocation->agentType !== null;

        if ($context !== null && $requiresScope) {
            $context = $context->fork(
                workingDirectory: $invocation->workingDirectory,
                readOnly: $invocation->readOnly,
                omitProjectInstructions: $invocation->omitProjectInstructions,
                agentType: $invocation->agentType,
            );
        } elseif ($context === null && $requiresScope) {
            $projectDirectory = ($invocation->workingDirectory ?? getcwd()) ?: '/';
            $settings = clone $this->baseSettings;
            if ($invocation->readOnly) {
                $settings->set('permission_mode', 'plan');
            }
            $context = new AgentRunContext(
                $projectDirectory,
                $projectDirectory,
                $settings,
                new SkillLoader($projectDirectory),
                new CancellationToken,
                memoryStore: new JsonMemoryStore($settings->getMemoryStoragePath()),
                memoryTools: ['MemoryRead'],
                omitProjectInstructions: $invocation->omitProjectInstructions,
                agentType: $invocation->agentType,
                readOnly: $invocation->readOnly,
            );
        }

        if ($context !== null) {
            if ($invocation->model !== null) {
                $context->settings->set('model', $invocation->model);
            }
            if (is_string($invocation->appendSystemPrompt)
                && trim($invocation->appendSystemPrompt) !== '') {
                $existing = trim((string) $context->settings->getAppendSystemPrompt());
                $append = trim($invocation->appendSystemPrompt);
                $context->settings->set(
                    'append_system_prompt',
                    $existing === '' ? $append : $existing."\n\n".$append,
                );
            }
        }

        return $context;
    }

    public function build(
        AgentLoopSpec $invocation,
        ?AgentRunContext $runContext,
        ToolRegistry $toolRegistry,
        AgentPersistence $persistence,
    ): AgentLoop {
        if ($runContext !== null) {
            $settings = $runContext->settings;
            $permissionChecker = new PermissionChecker($settings, new DenialTracker);
            $hookExecutor = new HookExecutor(
                $runContext->projectDirectory,
                $this->globalSettingsPath,
            );
            $contextBuilder = new ContextBuilder(
                settings: $settings,
                toolRegistry: $toolRegistry,
                memoryStore: $runContext->memoryStore ?? new JsonMemoryStore,
                skillLoader: $runContext->skillLoader,
                contextPreset: $runContext->contextPreset === ContextPreset::GENERIC
                    ? new GenericContextPreset
                    : new CodingContextPreset(
                        gitContext: new GitContext($runContext->projectDirectory),
                        workingDirectory: $runContext->projectDirectory,
                        omitProjectInstructions: $runContext->omitProjectInstructions,
                        systemPromptPath: $this->systemPromptPath,
                    ),
                outputStyleLoader: new OutputStyleLoader($runContext->projectDirectory),
                textOnly: $toolRegistry->getAllTools() === [],
                includeMemoryInTextOnly: $runContext->includeMemoryInTextOnly,
                planFilePath: $persistence->sessionManager->getPlanFilePath(),
            );
        } else {
            $settings = $this->baseSettings;
            $contextBuilder = $this->baseContextBuilder;
            $permissionChecker = $this->basePermissionChecker;
            $hookExecutor = $this->baseHookExecutor;
        }

        $provider = $invocation->provider ?? $this->baseProvider;
        if ($invocation->model !== null && $provider instanceof PooledProvider) {
            $provider = $provider->requiringScopedSettings();
        }
        if ($invocation->afterFork && $provider instanceof ForkSafeProvider) {
            $provider = $provider->freshAfterFork($runContext?->settings);
        } elseif ($runContext !== null && $provider instanceof SettingsAwareProvider) {
            $provider = $provider->withSettingsManager($settings);
        } elseif ($invocation->model !== null && $invocation->provider !== null) {
            throw new \RuntimeException('Agent model override requires a settings-aware provider.');
        }

        $costTracker = new CostTracker(
            budgetLedger: $runContext?->budgetLedger,
            usageAccumulator: $runContext?->usageAccumulator,
        );
        $costTracker->setProviderContext($settings->getProviderType(), $settings->getModel());
        $queryEngine = new QueryEngine(
            $provider,
            $toolRegistry,
            $this->tracer,
            $settings,
            $costTracker,
            $persistence->runJournal,
        );
        $orchestrator = new ToolOrchestrator(
            toolRegistry: $toolRegistry,
            permissionChecker: $permissionChecker,
            hookExecutor: $hookExecutor,
            tracer: $this->tracer,
            runJournal: $persistence->runJournal,
            durableToolCoordinator: $persistence->durableToolCoordinator,
        );
        if ($runContext !== null) {
            $orchestrator->configureHumanInterrupts(
                $runContext->interruptOn,
                $runContext->enableAskUser,
            );
            $orchestrator->enablePermissionInterrupts(
                ! $invocation->ephemeral
                && $runContext->settings->getPermissionMode()
                    !== \HaoCode\Services\Permissions\PermissionMode::BypassPermissions,
            );
        }

        $maxInputTokens = ContextBudget::safeInputLimit(
            $settings->getContextWindow(),
            $settings->getMaxTokens(),
        );
        $loop = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $persistence->sessionManager,
            contextCompactor: new ContextCompactor(
                $queryEngine,
                $hookExecutor,
                $settings->getContextWindow(),
                $maxInputTokens,
            ),
            costTracker: $costTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
            tracer: $this->tracer,
            cancellationToken: $runContext?->cancellationToken,
            maxEstimatedInputTokens: $maxInputTokens,
            runContext: $runContext,
            provider: $provider,
            runJournal: $persistence->runJournal,
        );
        if ($invocation->workingDirectory !== null) {
            $loop->setWorkingDirectory($invocation->workingDirectory);
        }
        if ($invocation->limits !== null) {
            $loop->setMaxTurns($invocation->limits->maxTurns);
        }

        return $loop;
    }
}
