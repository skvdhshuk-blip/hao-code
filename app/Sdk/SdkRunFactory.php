<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Settings\ModelCatalog;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\WebFetch\WebFetchTool;

/** @internal */
final class SdkRunFactory
{
    /** @var array<int, array<string, mixed>> */
    private static array $stagedResumeSnapshots = [];

    /** @internal */
    public static function stageResumeSnapshot(HaoCodeConfig $config, array $snapshot): void
    {
        self::$stagedResumeSnapshots[spl_object_id($config)] = $snapshot;
    }

    /** @internal */
    public static function consumeResumeSnapshot(HaoCodeConfig $config): ?array
    {
        $id = spl_object_id($config);
        $snapshot = self::$stagedResumeSnapshots[$id] ?? null;
        unset(self::$stagedResumeSnapshots[$id]);

        return $snapshot;
    }

    /** @internal */
    public static function clearResumeSnapshot(HaoCodeConfig $config): void
    {
        unset(self::$stagedResumeSnapshots[spl_object_id($config)]);
    }

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
        ?StreamingClient $streamingClient = null,
        ?array $resumeSnapshot = null,
    ): SdkRun {
        return self::create(
            ($options ?? new RunOptions)->toConfig($agent),
            $factory,
            $streamingClient,
            $resumeSnapshot,
        );
    }

    public static function create(
        HaoCodeConfig $config,
        AgentLoopFactory $factory,
        ?StreamingClient $streamingClient = null,
        ?array $resumeSnapshot = null,
    ): SdkRun {
        $runContext = self::createValidatedRunContext($config);
        if ($resumeSnapshot !== null) {
            $runContext = $runContext->fork(
                workingDirectory: self::snapshotString($resumeSnapshot, 'cwd'),
                projectDirectory: self::snapshotString($resumeSnapshot, 'project_directory'),
                readOnly: (bool) ($resumeSnapshot['read_only'] ?? false),
                omitProjectInstructions: (bool) ($resumeSnapshot['omit_project_instructions'] ?? false),
                agentType: self::snapshotString($resumeSnapshot, 'agent_type'),
                worktreePath: self::snapshotString($resumeSnapshot, 'worktree_path'),
                worktreeBranch: self::snapshotString($resumeSnapshot, 'worktree_branch'),
                managedWorktree: (bool) ($resumeSnapshot['managed_worktree'] ?? false),
            );
            foreach ([
                'model' => 'model',
                'system_prompt' => 'system_prompt',
                'append_system_prompt' => 'append_system_prompt',
            ] as $snapshotKey => $settingsKey) {
                if (array_key_exists($snapshotKey, $resumeSnapshot)) {
                    $runContext->settings->set($settingsKey, $resumeSnapshot[$snapshotKey]);
                }
            }
        }
        $provider = $streamingClient
            ?? self::buildStreamingClient(
                $config,
                $runContext->settings,
                self::snapshotString($resumeSnapshot ?? [], 'model'),
            )
            ?? \HaoCode\Support\Runtime\SdkRuntime::app(StreamingClient::class)->withSettingsManager($runContext->settings);

        $providerType = self::resolveProviderType($config, $runContext->settings);
        $resolvedModel = $runContext->settings->getModel();
        if ($config->maxBudgetUsd !== null
            && ModelCatalog::pricingFor($providerType, $resolvedModel) === null) {
            throw new \RuntimeException(
                "Cost budget requires pricing for model \"{$resolvedModel}\" "
                ."on provider type \"{$providerType}\". No trusted pricing is configured.",
            );
        }
        if ($config->credentialPool !== null) {
            $provider = new PooledProvider($provider, $config->credentialPool, $providerType);
        }

        $sandboxRuntime = $config->sandbox !== null
            ? SandboxManager::create($config->sandbox, $config->cwd)
            : null;
        $mcpConnectionManager = null;

        try {
            [$mcpTools, $mcpConnectionManager] = self::loadMcpTools($config, $runContext->projectDirectory);
            $additionalTools = $sandboxRuntime?->tools() ?? [];
            // Register a WebFetchTool constructed from the run's WebFetch
            // security policy (private-network toggle + CIDR allowlist + byte
            // cap) only when the run actually allows WebFetch. This keeps the
            // safe default — a plain query() exposes no tools — intact while
            // still honoring webfetchAllowPrivateNetworks etc. once WebFetch
            // is opted into. User-supplied WebFetch in $config->tools is
            // appended afterwards and overrides this one.
            if (self::allowsWebFetch($config)) {
                $additionalTools[] = self::buildWebFetchTool($config);
            }
            $additionalTools = array_merge(
                $additionalTools,
                $mcpTools,
                $config->tools,
            );

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
                model: self::snapshotString($resumeSnapshot ?? [], 'model'),
            );
        } catch (\Throwable $e) {
            $sandboxRuntime?->close();
            $mcpConnectionManager?->disconnectAll();

            throw $e;
        }

        $remainingTurns = $resumeSnapshot['max_turns_remaining'] ?? null;
        $loop->setMaxTurns(is_int($remainingTurns) && $remainingTurns > 0 ? $remainingTurns : $config->maxTurns);
        $loop->restoreRunSnapshot($resumeSnapshot ?? []);
        if ($mcpConnectionManager !== null) {
            $loop->setEventPump(static function () use ($mcpConnectionManager): void {
                $mcpConnectionManager->poll();
            });
        }

        $costTracker = $loop->getCostTracker();
        $costTracker->setProviderType($providerType);
        $costTracker->setModel($resolvedModel);
        if ($config->maxBudgetUsd !== null) {
            $loop->getCostTracker()->setThresholds(
                warn: $config->maxBudgetUsd * 0.8,
                stop: $config->maxBudgetUsd,
            );
            if (is_numeric($resumeSnapshot['estimated_cost_usd'] ?? null)) {
                $costTracker->setTotalCost((float) $resumeSnapshot['estimated_cost_usd']);
            }
        }

        if ($config->abortController !== null) {
            $config->abortController->onAbort(fn () => $loop->abort());
        }

        return new SdkRun($loop, $sandboxRuntime, $mcpConnectionManager);
    }

    private static function snapshotString(array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public static function createValidatedRunContext(HaoCodeConfig $config): AgentRunContext
    {
        $runContext = AgentRunContextFactory::make($config);
        $providerType = self::resolveProviderType($config, $runContext->settings);
        $hasPooledCredential = $config->credentialPool?->hasProvider($providerType) ?? false;
        $apiKey = $config->apiKey ?? $runContext->settings->getApiKey();

        if (trim($apiKey) === '' && ! $hasPooledCredential) {
            throw new \RuntimeException(
                'API key is required. Pass HaoCodeConfig(apiKey: ...), configure credentialPool, '.
                'or set ANTHROPIC_API_KEY in the process environment. .env files are not loaded automatically.',
            );
        }

        return $runContext;
    }

    public static function buildStreamingClient(
        HaoCodeConfig $config,
        ?SettingsManager $settings = null,
        ?string $modelOverride = null,
    ): ?StreamingClient {
        if ($config->apiKey === null
            && $config->baseUrl === null
            && $config->model === null
            && $config->maxTokens === null
            && $config->providerType === null) {
            return null;
        }

        $settings ??= AgentRunContextFactory::make($config)->settings;
        $providerType = self::resolveProviderType($config, $settings);
        $defaultBaseUrl = in_array($providerType, ['openai', 'openai_chat'], true)
            ? 'https://api.openai.com'
            : 'https://api.anthropic.com';
        $baseUrl = $config->baseUrl
            ?? ($config->providerType !== null ? $defaultBaseUrl : ($settings->getBaseUrl() ?: $defaultBaseUrl));

        return new StreamingClient(
            apiKey: $config->apiKey ?? $settings->getApiKey(),
            model: $modelOverride ?? $config->model ?? $settings->getModel(),
            baseUrl: $baseUrl,
            maxTokens: $config->maxTokens ?? $settings->getMaxTokens(),
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            settingsManager: null,
            idleTimeoutSeconds: (int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_idle_timeout', 60),
            streamPollTimeoutSeconds: (float) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_poll_timeout', 1.0),
            providerType: $providerType,
            oauthBearer: $config->oauthBearer === true,
            headers: $config->headers,
        );
    }

    private static function resolveProviderType(HaoCodeConfig $config, SettingsManager $settings): string
    {
        return match ($config->providerType) {
            'openai', 'openai_responses', 'responses' => 'openai',
            'openai_chat', 'openai_chat_completions', 'chat_completions' => 'openai_chat',
            'anthropic' => 'anthropic',
            null => $settings->getProviderType(),
            default => 'anthropic',
        };
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

    /**
     * Load MCP connections and dynamic tools for one isolated SDK run.
     *
     * @return array{0: array<int, \HaoCode\Contracts\ToolInterface>, 1: ?McpConnectionManager}
     */
    private static function loadMcpTools(HaoCodeConfig $config, string $projectDirectory): array
    {
        if (! self::allowsMcpTools($config)) {
            return [[], null];
        }

        $configManager = new McpServerConfigManager($projectDirectory);
        $servers = $configManager->listServers();
        if ($servers === []) {
            foreach ($config->allowedTools as $toolName) {
                if (str_starts_with($toolName, 'mcp__')) {
                    throw McpConnectionException::application(
                        "Explicitly allowed MCP tool has no configured server: {$toolName}"
                    );
                }
            }

            return [[], null];
        }

        $connectionManager = new McpConnectionManager($configManager);
        $connectAll = ($config->sandbox === null && in_array('*', $config->allowedTools, true))
            || in_array('ListMcpResourcesTool', $config->allowedTools, true)
            || in_array('ReadMcpResourceTool', $config->allowedTools, true);
        foreach ($servers as $server) {
            $prefix = McpConnectionManager::buildToolName($server['name'], '');
            $isAllowed = $connectAll;
            foreach ($config->allowedTools as $toolName) {
                if (str_starts_with($toolName, $prefix)) {
                    $isAllowed = true;
                    break;
                }
            }
            if (! $server['enabled'] || ! $isAllowed) {
                continue;
            }

            try {
                $connectionManager->connectByName($server['name']);
            } catch (McpConnectionException $e) {
                if (! $connectAll) {
                    throw $e;
                }
                // Connection failures remain available through the manager.
            }
        }
        $filter = $config->toolFilter();
        $tools = [];
        $registeredNames = [];
        $requestedNames = array_values(array_filter(
            $config->allowedTools,
            static fn (string $toolName): bool => str_starts_with($toolName, 'mcp__'),
        ));

        foreach ($connectionManager->discoverAllTools() as $definition) {
            if ($filter !== null && ! $filter($definition['qualifiedName'])) {
                continue;
            }
            if (isset($registeredNames[$definition['qualifiedName']])) {
                throw McpConnectionException::protocol(
                    "MCP tool name collision after normalization: {$definition['qualifiedName']}"
                );
            }
            $registeredNames[$definition['qualifiedName']] = true;

            $tools[] = new McpDynamicTool(
                qualifiedName: $definition['qualifiedName'],
                serverName: $definition['serverName'],
                toolName: $definition['toolName'],
                toolDescription: $definition['description'],
                inputJsonSchema: $definition['inputSchema'],
                annotations: $definition['annotations'],
                connectionManager: $connectionManager,
            );
        }

        foreach ($requestedNames as $requestedName) {
            if (($filter === null || $filter($requestedName)) && ! isset($registeredNames[$requestedName])) {
                throw McpConnectionException::application(
                    "Explicitly allowed MCP tool was not discovered: {$requestedName}"
                );
            }
        }

        if ($filter === null || $filter('ListMcpResourcesTool')) {
            $tools[] = new ListMcpResourcesTool($connectionManager);
        }
        if ($filter === null || $filter('ReadMcpResourceTool')) {
            $tools[] = new ReadMcpResourceTool($connectionManager);
        }

        return [$tools, $connectionManager];
    }

    private static function allowsMcpTools(HaoCodeConfig $config): bool
    {
        foreach ($config->allowedTools as $toolName) {
            if (str_starts_with($toolName, 'mcp__')
                || in_array($toolName, ['ListMcpResourcesTool', 'ReadMcpResourceTool'], true)) {
                return true;
            }
        }

        return $config->sandbox === null && in_array('*', $config->allowedTools, true);
    }
}
