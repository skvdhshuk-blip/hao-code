<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * Manages the lifecycle of MCP server connections.
 * Connects enabled servers, provides tool discovery, and handles reconnection.
 */
final class McpConnectionManager
{
    /** @var array<string, McpClient> Connected clients by server name */
    private array $clients = [];

    /** @var array<string, McpConnectionException> Failed connections by server name */
    private array $failures = [];

    /** @var list<array{code: string, tool: string}> */
    private array $toolDiagnostics = [];

    /** @var bool Whether initial connection has been performed */
    private bool $initialized = false;

    /** Maximum reconnect attempts per server */
    private const MAX_RECONNECT_ATTEMPTS = 3;

    /** Base delay in milliseconds for exponential backoff */
    private const RECONNECT_BASE_MS = 500;

    public function __construct(
        private readonly McpServerConfigManager $configManager,
        private readonly ?PhoenixTracer $tracer = null,
        private readonly string $clientVersion = 'dev',
    ) {}

    /**
     * Connect to all enabled MCP servers.
     * Safe to call multiple times — will only connect on the first call.
     *
     * @param  callable|null  $onServerStatus  Called with (string $name, string $status, ?string $error) for progress
     */
    public function connectAll(?callable $onServerStatus = null): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $servers = $this->configManager->listServers();

        foreach ($servers as $server) {
            if (! $server['enabled']) {
                if ($onServerStatus) {
                    $onServerStatus($server['name'], 'disabled', null);
                }

                continue;
            }

            try {
                if ($onServerStatus) {
                    $onServerStatus($server['name'], 'connecting', null);
                }
                $client = $this->connectServer($server);
                $this->clients[$server['name']] = $client;
                if ($onServerStatus) {
                    $onServerStatus($server['name'], 'connected', null);
                }
            } catch (McpConnectionException $e) {
                $this->failures[$server['name']] = $e;
                if ($onServerStatus) {
                    $onServerStatus($server['name'], 'failed', $e->getMessage());
                }
            }
        }
    }

    /**
     * Connect to a single server by name.
     *
     * @throws McpConnectionException
     */
    public function connectByName(string $name): McpClient
    {
        if (isset($this->clients[$name])) {
            if ($this->clients[$name]->isConnected()) {
                return $this->clients[$name];
            }

            return $this->reconnect($name);
        }

        $server = $this->configManager->getServer($name);
        if ($server === null) {
            throw new McpConnectionException("MCP server '{$name}' not found in configuration");
        }

        if (! $server['enabled']) {
            throw new McpConnectionException("MCP server '{$name}' is disabled");
        }

        try {
            $client = $this->connectServer($server);
            $this->clients[$name] = $client;
            unset($this->failures[$name]);
        } catch (McpConnectionException $e) {
            $this->failures[$name] = $e;
            throw $e;
        }

        return $client;
    }

    /**
     * Attempt to reconnect a disconnected server with exponential backoff.
     * Makes up to MAX_RECONNECT_ATTEMPTS attempts before giving up.
     *
     * @throws McpConnectionException if all attempts fail
     */
    public function reconnect(string $name): McpClient
    {
        // Close existing stale client if present
        if (isset($this->clients[$name])) {
            try {
                $this->clients[$name]->close();
            } catch (\Throwable) {
                // Best-effort close
            }
            unset($this->clients[$name]);
        }

        $server = $this->configManager->getServer($name);
        if ($server === null) {
            throw new McpConnectionException("MCP server '{$name}' not found in configuration");
        }

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RECONNECT_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                // Exponential backoff: 500ms, 1000ms, 2000ms
                $delayMs = self::RECONNECT_BASE_MS * (2 ** ($attempt - 2));
                usleep($delayMs * 1000);
            }

            try {
                $client = $this->connectServer($server);
                $this->clients[$name] = $client;
                unset($this->failures[$name]);

                return $client;
            } catch (McpConnectionException $e) {
                $lastException = $e;
            }
        }

        $this->failures[$name] = $lastException;
        throw new McpConnectionException(
            "Failed to reconnect to MCP server '{$name}' after ".self::MAX_RECONNECT_ATTEMPTS.' attempts: '.$lastException->getMessage(),
            $lastException->getCode(),
        );
    }

    /**
     * Ensure a client is connected, reconnecting if necessary.
     *
     * @throws McpConnectionException
     */
    public function ensureConnected(string $name): McpClient
    {
        $client = $this->clients[$name] ?? null;
        if ($client !== null && $client->isConnected()) {
            return $client;
        }

        return $this->reconnect($name);
    }

    /**
     * Get a connected client by server name.
     */
    public function getClient(string $name): ?McpClient
    {
        return $this->clients[$name] ?? null;
    }

    /**
     * Get all connected clients.
     *
     * @return array<string, McpClient>
     */
    public function getConnectedClients(): array
    {
        return $this->clients;
    }

    /**
     * Get all connection failures.
     *
     * @return array<string, McpConnectionException>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /** @return list<array{code: string, tool: string}> */
    public function getToolDiagnostics(): array
    {
        return $this->toolDiagnostics;
    }

    /** @internal */
    public function recordInvalidToolSchema(string $qualifiedName): void
    {
        $this->toolDiagnostics[] = [
            'code' => 'invalid_tool_schema',
            'tool' => $qualifiedName,
        ];
    }

    /**
     * Cooperatively process pending server-initiated MCP messages.
     *
     * The timeout is shared across all connected clients rather than applied to
     * each client independently. The SDK run installs this as its cooperative
     * event pump; hosts integrating the manager directly must call poll() during
     * idle time so stdio notifications and reverse requests are serviced.
     */
    public function poll(float $timeoutSeconds = 0.0): void
    {
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        foreach ($this->clients as $serverName => $client) {
            $remaining = $timeoutSeconds > 0.0
                ? max(0.0, $deadline - microtime(true))
                : 0.0;

            try {
                $client->poll($remaining);
                unset($this->failures[$serverName]);
            } catch (McpConnectionException $exception) {
                $this->failures[$serverName] = $exception;
            }
        }
    }

    /**
     * Discover all tools across all connected MCP servers.
     * Returns tools in the format: mcp__<server>__<tool>
     *
     * @return array<int, array{
     *     qualifiedName: string,
     *     serverName: string,
     *     toolName: string,
     *     description: string,
     *     inputSchema: array,
     *     annotations: array,
     * }>
     */
    public function discoverAllTools(): array
    {
        $allTools = [];

        foreach ($this->clients as $serverName => $client) {
            if (! $client->supportsTools()) {
                continue;
            }

            try {
                $tools = $client->listTools();
                foreach ($tools as $tool) {
                    $allTools[] = [
                        'qualifiedName' => self::buildToolName($serverName, $tool['name']),
                        'serverName' => $serverName,
                        'toolName' => $tool['name'],
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema'],
                        'annotations' => $tool['annotations'] ?? [],
                    ];
                }
                unset($this->failures[$serverName]);
            } catch (McpConnectionException $e) {
                $this->failures[$serverName] = $e;
            }
        }

        return $allTools;
    }

    /**
     * Discover all resources across all connected MCP servers.
     *
     * @return array<int, array{uri: string, name: string, mimeType?: string, description?: string, server: string}>
     */
    public function discoverAllResources(): array
    {
        $allResources = [];

        foreach ($this->clients as $serverName => $client) {
            if (! $client->supportsResources()) {
                continue;
            }

            try {
                $resources = $client->listResources();
                foreach ($resources as $resource) {
                    $resource['server'] = $serverName;
                    $allResources[] = $resource;
                }
                unset($this->failures[$serverName]);
            } catch (McpConnectionException $e) {
                $this->failures[$serverName] = $e;
            }
        }

        return $allResources;
    }

    /**
     * Discover all prompts across all connected MCP servers.
     *
     * @return array<int, array{name: string, description: string, arguments?: array, server: string}>
     */
    public function discoverAllPrompts(): array
    {
        $allPrompts = [];

        foreach ($this->clients as $serverName => $client) {
            if (! $client->supportsPrompts()) {
                continue;
            }

            try {
                $prompts = $client->listPrompts();
                foreach ($prompts as $prompt) {
                    $prompt['server'] = $serverName;
                    $allPrompts[] = $prompt;
                }
                unset($this->failures[$serverName]);
            } catch (McpConnectionException $e) {
                $this->failures[$serverName] = $e;
            }
        }

        return $allPrompts;
    }

    /**
     * Disconnect a specific server.
     */
    public function disconnect(string $name): void
    {
        if (isset($this->clients[$name])) {
            $this->clients[$name]->close();
            unset($this->clients[$name]);
        }
    }

    /**
     * Disconnect all servers and reset state.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
        $this->failures = [];
        $this->toolDiagnostics = [];
        $this->initialized = false;
    }

    /**
     * Build a fully qualified MCP tool name: mcp__<server>__<tool>
     */
    public static function buildToolName(string $serverName, string $toolName): string
    {
        return 'mcp__'.self::normalizeName($serverName).'__'.self::normalizeName($toolName);
    }

    /**
     * Parse a qualified tool name back to server + tool.
     *
     * @return array{serverName: string, toolName: string}|null
     */
    public static function parseToolName(string $qualifiedName): ?array
    {
        if (! str_starts_with($qualifiedName, 'mcp__')) {
            return null;
        }

        $parts = explode('__', $qualifiedName, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'serverName' => $parts[1],
            'toolName' => $parts[2],
        ];
    }

    /**
     * Normalize a server or tool name for use in qualified tool names.
     * Replaces non-alphanumeric characters with underscores.
     */
    private static function normalizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    /**
     * @param  array{name: string, transport: string, command: ?string, args: array, url: ?string, env: array, headers: array, oauth?: array<string, string>, cwd?: string}  $serverConfig
     *
     * @throws McpConnectionException
     */
    private function connectServer(array $serverConfig): McpClient
    {
        $transport = McpTransport::fromConfig($serverConfig);
        $client = new McpClient($transport, $serverConfig['name'], $this->tracer, $this->clientVersion);
        $client->connect();

        return $client;
    }
}
