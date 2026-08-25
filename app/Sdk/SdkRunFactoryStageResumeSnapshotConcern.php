<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\SettingsAwareProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Cost\UsageAccumulator;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Internal\RunCapabilityGuard;
use HaoCode\Sdk\Internal\RunCapabilityResolver;
use HaoCode\Sdk\Internal\RunSpec;
use HaoCode\Sdk\Internal\LegacyHaoCodeConfigAdapter;
use HaoCode\Sdk\Internal\RunBootstrap;
use HaoCode\Sdk\Internal\RuntimeDefaults;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\WebFetch\WebFetchTool;

trait SdkRunFactoryStageResumeSnapshotConcern
{

    /**
     * Internal adaptation point between the modern Agent/RunOptions pair and
     * the legacy HaoCodeConfig-based run assembly.
     *
     * Both {@see Runner} (single runs) and {@see Conversation} (multi-turn
     * sessions) route through this method so there is exactly one place that
     * turns an agent definition into a running AgentLoop. It intentionally
     * lives here — not on AgentLoopFactory — because run assembly needs
     * SDK-layer concerns (credential pools, sandbox, MCP tools, budget
     * thresholds, abort wiring) while AgentLoopFactory stays an SDK-agnostic
     * service-layer primitive shared with sub-agents and skill forks.
     *
     * @internal
     */
    public static function createFromAgent(
        Agent $agent,
        ?RunOptions $options,
        AgentLoopFactory $factory,
        ?LlmProvider $streamingClient = null,
        ?array $resumeSnapshot = null,
        ?BudgetLedger $budgetLedger = null,
        ?ToolRegistry $parentToolRegistry = null,
        ?UsageAccumulator $usageAccumulator = null,
        ?AgentRunContext $parentRunContext = null,
    ): SdkRun {
        return self::createBootstrap(new RunBootstrap(
            spec: RunSpec::fromAgent($agent, $options),
            factory: $factory,
            runtimeDefaults: RuntimeDefaults::capture(),
            resumeSnapshot: $resumeSnapshot,
            provider: $streamingClient,
            budgetLedger: $budgetLedger,
            parentToolRegistry: $parentToolRegistry,
            usageAccumulator: $usageAccumulator,
            parentRunContext: $parentRunContext,
        ));
    }

    public static function create(
        HaoCodeConfig $config,
        AgentLoopFactory $factory,
        ?LlmProvider $streamingClient = null,
        ?array $resumeSnapshot = null,
        ?BudgetLedger $budgetLedger = null,
        ?ToolRegistry $parentToolRegistry = null,
        ?UsageAccumulator $usageAccumulator = null,
        ?AgentRunContext $parentRunContext = null,
    ): SdkRun {
        return self::createBootstrap(new RunBootstrap(
            spec: RunSpec::fromConfig($config),
            factory: $factory,
            runtimeDefaults: RuntimeDefaults::capture(),
            resumeSnapshot: $resumeSnapshot,
            provider: $streamingClient,
            budgetLedger: $budgetLedger,
            parentToolRegistry: $parentToolRegistry,
            usageAccumulator: $usageAccumulator,
            parentRunContext: $parentRunContext,
        ));
    }

    private static function createBootstrap(RunBootstrap $bootstrap): SdkRun
    {
        $spec = $bootstrap->spec;
        $factory = $bootstrap->factory;
        $streamingClient = $bootstrap->provider;
        $resumeSnapshot = $bootstrap->resumeSnapshot;
        $budgetLedger = $bootstrap->budgetLedger;
        $parentToolRegistry = $bootstrap->parentToolRegistry;
        $usageAccumulator = $bootstrap->usageAccumulator;
        $parentRunContext = $bootstrap->parentRunContext;
        $config = $spec->options->toConfig($spec->agent);
        $limits = $spec->limits;
        // When a parent LlmProvider is injected (AgentAsTool composition), the
        // child's own apiKey may be empty — credentials live on the provider.
        $runContext = $parentRunContext === null
            ? self::createValidatedRunContext(
                $config,
                requireApiKey: $streamingClient === null,
            )
            : AgentRunContextFactory::makeChild(
                $config,
                $parentRunContext,
                $config->effectiveWorkingDirectory() ?? $parentRunContext->workingDirectory,
            );
        if ($usageAccumulator !== null) {
            $runContext = $runContext->withUsageAccumulator($usageAccumulator);
        }
        $budgetLimit = $limits->budgetForResume($resumeSnapshot);
        if ($budgetLedger !== null) {
            if ($budgetLimit === null || abs($budgetLedger->getLimit() - $budgetLimit) > 0.0000001) {
                throw new \RuntimeException('Shared budget ledger does not match the run budget.');
            }
        } elseif ($budgetLimit !== null) {
            $ledgerId = self::snapshotString($resumeSnapshot ?? [], 'budget_ledger_id');
            $minimumSpent = is_numeric($resumeSnapshot['estimated_cost_usd'] ?? null)
                ? max(0.0, (float) $resumeSnapshot['estimated_cost_usd'])
                : 0.0;
            $budgetLedger = $ledgerId !== null
                ? BudgetLedger::resume(
                    $ledgerId,
                    $budgetLimit,
                    $bootstrap->runtimeDefaults->budgetDirectory,
                    $minimumSpent,
                )
                : BudgetLedger::create($budgetLimit, $bootstrap->runtimeDefaults->budgetDirectory);
        }
        if ($budgetLedger !== null) {
            $runContext = $runContext->fork(budgetLedger: $budgetLedger);
        }
        if ($resumeSnapshot !== null) {
            $baseModel = self::snapshotString($resumeSnapshot, 'base_model')
                ?? self::snapshotString($resumeSnapshot, 'model');
            $runContext = $runContext->fork(
                workingDirectory: self::snapshotString($resumeSnapshot, 'cwd'),
                projectDirectory: self::snapshotString($resumeSnapshot, 'project_directory'),
                readOnly: (bool) ($resumeSnapshot['read_only'] ?? false),
                omitProjectInstructions: (bool) ($resumeSnapshot['omit_project_instructions'] ?? false),
                agentType: self::snapshotString($resumeSnapshot, 'agent_type'),
                contextPreset: self::snapshotString($resumeSnapshot, 'context_preset'),
                worktreePath: self::snapshotString($resumeSnapshot, 'worktree_path'),
                worktreeBranch: self::snapshotString($resumeSnapshot, 'worktree_branch'),
                managedWorktree: (bool) ($resumeSnapshot['managed_worktree'] ?? false),
                backgroundOwnerAgentId: self::snapshotString($resumeSnapshot, 'background_owner_agent_id'),
            );
            foreach ([
                'system_prompt' => 'system_prompt',
                'append_system_prompt' => 'append_system_prompt',
            ] as $snapshotKey => $settingsKey) {
                if (array_key_exists($snapshotKey, $resumeSnapshot)) {
                    $runContext->settings->set($settingsKey, $resumeSnapshot[$snapshotKey]);
                }
            }
            if ($baseModel !== null) {
                $runContext->settings->set('model', $baseModel);
            }
        }
        $resolvedProvider = $runContext->settings->resolveProviderConfig();
        $capabilityGuard = new RunCapabilityGuard(
            $config,
            RunCapabilityResolver::defaults(),
            $budgetLimit,
            requireResolvedCredential: $streamingClient === null || $streamingClient instanceof StreamingClient,
            injectedCredentialProviderTypes: $streamingClient instanceof StreamingClient
                ? $streamingClient->configuredCredentialProviderTypes()
                : [],
        );
        if ($streamingClient !== null && ! $streamingClient instanceof SettingsAwareProvider) {
            $capabilityGuard->lockProviderRuntime($resolvedProvider, $runContext->settings);
        }
        $capabilityGuard->assertSupported($resolvedProvider, $runContext->settings);

        $provider = $streamingClient
            ?? self::buildStreamingClient(
                $config,
                $runContext->settings,
                self::snapshotString($resumeSnapshot ?? [], 'base_model')
                    ?? self::snapshotString($resumeSnapshot ?? [], 'model'),
                $bootstrap->runtimeDefaults,
            )
            ?? \HaoCode\Support\Runtime\SdkRuntime::app(StreamingClient::class)->withSettingsManager($runContext->settings);

        $providerType = $resolvedProvider->providerType;
        $resolvedModel = $resolvedProvider->model;
        if ($config->credentialPool !== null) {
            $provider = new PooledProvider(
                $provider,
                $config->credentialPool,
                $providerType,
                settingsManager: $runContext->settings,
            );
        }

        if ($parentToolRegistry !== null && $config->sandbox !== null) {
            throw new \InvalidArgumentException(
                'Nested agents inherit the parent resource boundary and cannot create an independent sandbox.',
            );
        }

        $sandboxRuntime = $parentToolRegistry === null && $config->sandbox !== null
            ? SandboxManager::create($config->sandbox, $config->cwd)
            : null;
        $mcpConnectionManager = null;

        try {
            [$mcpTools, $mcpConnectionManager] = $parentToolRegistry === null
                ? self::loadMcpTools($config, $runContext->projectDirectory)
                : [[], null];
            $sandboxTools = $sandboxRuntime?->tools() ?? [];
            $replacementTools = $sandboxTools;
            // Register a WebFetchTool constructed from the run's WebFetch
            // security policy (private-network toggle + CIDR allowlist + byte
            // cap) only when the run actually allows WebFetch. This keeps the
            // safe default — a plain query() exposes no tools — intact while
            // still honoring webfetchAllowPrivateNetworks etc. once WebFetch
            // is opted into. User-supplied WebFetch in $config->tools is
            // appended afterwards but must not overwrite sandbox replacements.
            if ($parentToolRegistry === null && self::allowsWebFetch($config)) {
                $replacementTools[] = self::buildWebFetchTool($config);
            }
            self::assertNoReplacementToolConflicts($replacementTools, $mcpTools, $config->tools);
            $additionalTools = array_merge($mcpTools, $config->tools);

            $toolFilter = $config->toolFilter();
            if (is_array($resumeSnapshot['allowed_tools'] ?? null)) {
                $allowed = array_fill_keys($resumeSnapshot['allowed_tools'], true);
                $configuredFilter = $toolFilter;
                $toolFilter = static fn (string $name): bool =>
                    isset($allowed[$name]) && ($configuredFilter === null || $configuredFilter($name));
            }

            $loop = $factory->createIsolated(
                toolFilter: $toolFilter,
                workingDirectory: self::snapshotString($resumeSnapshot ?? [], 'cwd')
                    ?? $config->effectiveWorkingDirectory(),
                additionalTools: $additionalTools,
                streamingClient: $provider,
                runContext: $runContext,
                ephemeral: $config->ephemeral,
                additionalToolFilter: $config->additionalToolFilter(),
                parentToolRegistry: $parentToolRegistry,
                model: self::snapshotString($resumeSnapshot ?? [], 'base_model')
                    ?? self::snapshotString($resumeSnapshot ?? [], 'model'),
                replacementTools: $replacementTools,
                parentRunContext: $parentRunContext,
                limits: \HaoCode\Services\Agent\RunLimits::turns(
                    $limits->turnsForResume($resumeSnapshot),
                ),
            );
            $capabilityGuard->bindEffectiveManifest($loop->getToolManifest());
            $runContext->settings->setRuntimeConfigurationValidator([$capabilityGuard, 'assertSupported']);
            $runContext->settings->assertRuntimeConfigurationSupported();
        } catch (\Throwable $e) {
            $sandboxRuntime?->close();
            $mcpConnectionManager?->disconnectAll();

            throw $e;
        }

        $loop->restoreRunSnapshot($resumeSnapshot ?? []);
        if ($mcpConnectionManager !== null) {
            $loop->setEventPump(static function () use ($mcpConnectionManager): void {
                $mcpConnectionManager->poll();
            });
        }

        $costTracker = $loop->getCostTracker();
        $costTracker->setProviderContext($providerType, $resolvedModel);
        if ($budgetLimit !== null) {
            $loop->getCostTracker()->setThresholds(
                warn: $budgetLimit * 0.8,
                stop: $budgetLimit,
            );
            if (is_numeric($resumeSnapshot['estimated_cost_usd'] ?? null)) {
                $costTracker->setTotalCost((float) $resumeSnapshot['estimated_cost_usd']);
            }
        }

        $unsubscribeAbort = null;
        if ($config->abortController !== null) {
            $abortController = $config->abortController;
            $loop->setAbortRequestedChecker(
                static fn (): bool => $abortController->isAborted(),
            );
            $unsubscribeAbort = $abortController->subscribe(
                static fn () => $loop->abort(),
            );
        }

        return new SdkRun(
            $loop,
            $sandboxRuntime,
            $mcpConnectionManager,
            $unsubscribeAbort,
            $capabilityGuard,
        );
    }

    private static function snapshotString(array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * When sandbox replacements are active, reject custom/MCP tools that would
     * silently overwrite Read/Write/Glob/Grep/Bash host-boundary tools.
     *
     * @param  list<object>  $mcpTools
     * @param  list<object>  $userTools
     */
    private static function assertNoReplacementToolConflicts(array $replacementTools, array $mcpTools, array $userTools): void
    {
        if ($replacementTools === []) {
            return;
        }
        $reserved = [];
        foreach ($replacementTools as $tool) {
            if (is_object($tool) && method_exists($tool, 'name')) {
                $reserved[(string) $tool->name()] = true;
            }
        }
        foreach (array_merge($mcpTools, $userTools) as $tool) {
            if (! is_object($tool) || ! method_exists($tool, 'name')) {
                continue;
            }
            $name = (string) $tool->name();
            if (isset($reserved[$name])) {
                throw new \InvalidArgumentException(
                    "Tool name '{$name}' is owned by the active runtime policy and cannot be "
                    .'overridden by custom or MCP tools.',
                );
            }
        }
    }

    public static function createValidatedRunContext(
        HaoCodeConfig $config,
        bool $requireApiKey = true,
    ): AgentRunContext {
        $runContext = AgentRunContextFactory::make($config);
        $resolvedProvider = $runContext->settings->resolveProviderConfig();
        $providerType = $resolvedProvider->providerType;
        $hasPooledCredential = $config->credentialPool?->hasProvider($providerType) ?? false;
        $apiKey = $resolvedProvider->apiKey;

        if ($requireApiKey && trim($apiKey) === '' && ! $hasPooledCredential) {
            $environment = $providerType === 'anthropic'
                ? 'ANTHROPIC_API_KEY'
                : 'OPENAI_API_KEY';
            throw new \RuntimeException(
                "API key is required for provider type \"{$providerType}\". "
                .'Pass HaoCodeConfig(apiKey: ...), configure a matching credentialPool/provider entry, '
                ."or set {$environment} in the process environment. .env files are not loaded automatically.",
            );
        }

        return $runContext;
    }

    public static function buildStreamingClient(
        HaoCodeConfig $config,
        ?SettingsManager $settings = null,
        ?string $modelOverride = null,
        ?RuntimeDefaults $runtimeDefaults = null,
    ): ?StreamingClient {
        if ($config->apiKey === null
            && $config->baseUrl === null
            && $config->model === null
            && $config->maxTokens === null
            && $config->providerType === null) {
            return null;
        }

        $settings ??= AgentRunContextFactory::make($config)->settings;
        $resolvedProvider = $settings->resolveProviderConfig();
        $providerType = $resolvedProvider->providerType;

        $runtimeDefaults ??= RuntimeDefaults::capture();

        return new StreamingClient(
            apiKey: $resolvedProvider->apiKey,
            model: $modelOverride ?? $resolvedProvider->model,
            baseUrl: $resolvedProvider->baseUrl,
            maxTokens: $resolvedProvider->maxTokens,
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            settingsManager: null,
            idleTimeoutSeconds: $runtimeDefaults->apiStreamIdleTimeoutSeconds,
            streamPollTimeoutSeconds: $runtimeDefaults->apiStreamPollTimeoutSeconds,
            providerType: $providerType,
            oauthBearer: $config->oauthBearer === true,
            headers: $config->headers,
        );
    }

    private static function resolveProviderType(HaoCodeConfig $config, SettingsManager $settings): string
    {
        if ($config->providerType === null) {
            return $settings->getProviderType();
        }

        return \HaoCode\Services\Settings\ProviderType::normalizeRequired($config->providerType);
    }

    /**
     * Build a WebFetchTool honoring the run's WebFetch security policy.
     * The caller's CIDR list is passed through exactly; there is no implicit
     * loopback exception.
     */
    private static function buildWebFetchTool(HaoCodeConfig $config): WebFetchTool
    {
        return new WebFetchTool(
            allowPrivateNetworks: $config->webfetchAllowPrivateNetworks,
            ssrfAllowList: $config->webfetchPrivateAllowList,
            maxBytes: $config->webfetchMaxBytes,
        );
    }

    /**
     * WebFetch is registered only when the run opted into it explicitly —
     * either via an allowedTools entry or a wildcard — so the safe default
     * (plain query() exposes no tools) is preserved.
     */
    private static function allowsWebFetch(HaoCodeConfig $config): bool
    {
        $filter = $config->toolFilter();

        return $filter === null || $filter('WebFetch');
    }
}
