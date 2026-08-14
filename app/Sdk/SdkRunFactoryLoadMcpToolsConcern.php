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
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\WebFetch\WebFetchTool;

trait SdkRunFactoryLoadMcpToolsConcern
{

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
