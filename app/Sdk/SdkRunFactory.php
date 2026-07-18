<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;

/** @internal */
final class SdkRunFactory
{
    public static function create(
        HaoCodeConfig $config,
        AgentLoopFactory $factory,
        ?StreamingClient $streamingClient = null,
    ): SdkRun {
        $runContext = self::createValidatedRunContext($config);
        $provider = $streamingClient
            ?? self::buildStreamingClient($config, $runContext->settings)
            ?? \HaoCode\Support\Runtime\SdkRuntime::app(StreamingClient::class)->withSettingsManager($runContext->settings);

        $providerType = self::resolveProviderType($config, $runContext->settings);
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
            $additionalTools = array_merge($additionalTools, $mcpTools, $config->tools);

            $loop = $factory->createIsolated(
                toolFilter: $config->toolFilter(),
                workingDirectory: $config->effectiveWorkingDirectory(),
                additionalTools: $additionalTools,
                streamingClient: $provider,
                runContext: $runContext,
                ephemeral: $config->ephemeral,
            );
        } catch (\Throwable $e) {
            $sandboxRuntime?->close();
            $mcpConnectionManager?->disconnectAll();

            throw $e;
        }

        $loop->setMaxTurns($config->maxTurns);
        if ($mcpConnectionManager !== null) {
            $loop->setEventPump(static function () use ($mcpConnectionManager): void {
                $mcpConnectionManager->poll();
            });
        }

        if ($config->maxBudgetUsd !== null) {
            $loop->getCostTracker()->setThresholds(
                warn: $config->maxBudgetUsd * 0.8,
                stop: $config->maxBudgetUsd,
            );
        }

        if ($config->abortController !== null) {
            $config->abortController->onAbort(fn () => $loop->abort());
        }

        return new SdkRun($loop, $sandboxRuntime, $mcpConnectionManager);
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
            model: $config->model ?? $settings->getModel(),
            baseUrl: $baseUrl,
            maxTokens: $config->maxTokens ?? $settings->getMaxTokens(),
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            settingsManager: null,
            idleTimeoutSeconds: (int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_idle_timeout', 60),
            streamPollTimeoutSeconds: (float) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_poll_timeout', 1.0),
            providerType: $providerType,
            oauthBearer: $config->oauthBearer === true,
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
