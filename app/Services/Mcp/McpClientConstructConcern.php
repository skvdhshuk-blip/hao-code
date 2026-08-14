<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Telemetry\PhoenixTracer;
use OpenTelemetry\API\Trace\SpanInterface;

trait McpClientConstructConcern
{

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $serverName,
        private readonly ?PhoenixTracer $tracer = null,
    ) {}

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public function getServerInfo(): ?array
    {
        return $this->serverInfo;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    /**
     * Set workspace roots used to respond to the server's roots/list request.
     *
     * This only updates the host-side response state. It does not send a
     * notifications/roots/list_changed message after the connection is open;
     * set roots before connect when the server needs to discover them during
     * initialization.
     *
     * @param  array<array{uri: string, name?: string}>  $roots
     */
    public function setRoots(array $roots): void
    {
        $this->roots = $roots;
    }

    /**
     * Register a handler for inbound server notifications.
     * Multiple handlers per method are allowed.
     */
    public function registerNotificationHandler(string $method, callable $handler): void
    {
        $this->transport->onNotification($method, $handler);
    }

    /**
     * Connect to the MCP server and perform the initialization handshake.
     *
     * @throws McpConnectionException
     */
    public function connect(int $timeoutSeconds = 30): void
    {
        $this->connectUntil(
            microtime(true) + max(0.001, (float) $timeoutSeconds),
            $timeoutSeconds,
        );
    }

    private function connectUntil(float $deadline, float $timeoutLabel): void
    {
        $span = $this->startSpan('initialize');
        // One absolute deadline covers transport connect + initialize (not each step).
        try {
            $connectRemaining = $deadline - microtime(true);
            if ($connectRemaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP connect timed out after {$timeoutLabel}s for '{$this->serverName}'."
                );
            }
            $this->transport->connect($connectRemaining);

            $this->registerProtocolHandlers();

            $initRemaining = $deadline - microtime(true);
            if ($initRemaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP connect timed out after {$timeoutLabel}s during initialize for '{$this->serverName}'."
                );
            }

            $result = $this->transport->request('initialize', [
                'protocolVersion' => self::LATEST_PROTOCOL_VERSION,
                'capabilities' => [
                    'roots' => ['listChanged' => true],
                ],
                'clientInfo' => [
                    'name' => 'hao-code',
                    'version' => (string) \HaoCode\Support\Runtime\SdkRuntime::environment('HAO_CODE_VERSION', 'dev'),
                ],
            ], $initRemaining);

            if (! is_array($result)) {
                throw new McpConnectionException("Invalid initialize response from {$this->serverName}");
            }

            // Accept protocol versions implemented by the current client.
            $serverVersion = $result['protocolVersion'] ?? null;
            if (! is_string($serverVersion) || ! in_array($serverVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
                $received = is_scalar($serverVersion) ? (string) $serverVersion : 'missing';
                throw McpConnectionException::protocol(
                    "Unsupported protocol version {$received} from {$this->serverName}"
                );
            }

            $this->transport->setProtocolVersion($serverVersion);

            $this->capabilities = $result['capabilities'] ?? [];
            $this->serverInfo = $result['serverInfo'] ?? null;
            $this->instructions = $result['instructions'] ?? null;

            // Send initialized notification
            $notifyRemaining = $deadline - microtime(true);
            if ($notifyRemaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP connect timed out after {$timeoutLabel}s before initialized notification for '{$this->serverName}'."
                );
            }
            $this->transport->notify('notifications/initialized', timeoutSeconds: $notifyRemaining);

            $this->initialized = true;
            $streamRemaining = $deadline - microtime(true);
            if ($streamRemaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP connect timed out after {$timeoutLabel}s before event stream setup for '{$this->serverName}'."
                );
            }
            $this->transport->startServerEventStream($streamRemaining);
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            $this->resetConnectionState();
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Clear all connection-scoped state after a failed handshake or close.
     */
    private function resetConnectionState(): void
    {
        $this->initialized = false;
        $this->capabilities = null;
        $this->serverInfo = null;
        $this->instructions = null;
        $this->clearCache();
        try {
            $this->transport->close();
        } catch (\Throwable) {
            // Best-effort; state is already cleared.
        }
    }

    /**
     * Whether the server supports tools capability.
     */
    public function supportsTools(): bool
    {
        return $this->capabilities !== null && array_key_exists('tools', $this->capabilities);
    }

    /**
     * Whether the server supports resources capability.
     */
    public function supportsResources(): bool
    {
        return $this->capabilities !== null && array_key_exists('resources', $this->capabilities);
    }

    /**
     * Whether the server supports prompts capability.
     */
    public function supportsPrompts(): bool
    {
        return $this->capabilities !== null && array_key_exists('prompts', $this->capabilities);
    }

    /**
     * Fetch the list of tools from the MCP server.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     *
     * @throws McpConnectionException
     */
    public function listTools(bool $useCache = true, int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        if ($useCache && $this->toolsCache !== null) {
            return $this->toolsCache;
        }

        if (! $this->supportsTools()) {
            return [];
        }

        $span = $this->startSpan('tools/list');
        try {
            $tools = [];
            foreach ($this->listAllPages('tools/list', 'tools', $timeoutSeconds) as $tool) {
                if (! is_array($tool) || ! isset($tool['name'])) {
                    continue;
                }
                $tools[] = [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass],
                    'annotations' => $tool['annotations'] ?? [],
                ];
            }

            $this->toolsCache = $tools;
            $span?->setAttribute('mcp.tools.count', count($tools));

            return $tools;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Call a tool on the MCP server.
     *
     * @return array{content: array, isError: bool, structuredContent?: mixed}
     *
     * @throws McpConnectionException
     */
    public function callTool(string $toolName, array $arguments = [], int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        $span = $this->startSpan('tools/call', ['tool.name' => $toolName]);
        try {
            $result = $this->requestWithSessionRecovery('tools/call', [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ], $timeoutSeconds);

            if (! is_array($result)) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Empty response from MCP server']],
                    'isError' => true,
                ];
            }

            $response = [
                'content' => $result['content'] ?? [],
                'isError' => (bool) ($result['isError'] ?? false),
                'structuredContent' => $result['structuredContent'] ?? null,
            ];

            if ($response['isError']) {
                $span?->setAttribute('mcp.tool.is_error', true);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Fetch resources list from the MCP server.
     *
     * @return array<int, array{uri: string, name: string, mimeType?: string, description?: string}>
     *
     * @throws McpConnectionException
     */
    public function listResources(bool $useCache = true, int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        if ($useCache && $this->resourcesCache !== null) {
            return $this->resourcesCache;
        }

        if (! $this->supportsResources()) {
            return [];
        }

        $span = $this->startSpan('resources/list');
        try {
            $resources = [];
            foreach ($this->listAllPages('resources/list', 'resources', $timeoutSeconds) as $resource) {
                if (! is_array($resource) || ! isset($resource['uri'])) {
                    continue;
                }
                $entry = [
                    'uri' => (string) $resource['uri'],
                    'name' => (string) ($resource['name'] ?? $resource['uri']),
                ];
                if (isset($resource['mimeType'])) {
                    $entry['mimeType'] = (string) $resource['mimeType'];
                }
                if (isset($resource['description'])) {
                    $entry['description'] = (string) $resource['description'];
                }
                $resources[] = $entry;
            }

            $this->resourcesCache = $resources;
            $span?->setAttribute('mcp.resources.count', count($resources));

            return $resources;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Read a specific resource from the MCP server.
     *
     * @return array{contents: array<int, array{uri: string, mimeType?: string, text?: string, blob?: string}>}
     *
     * @throws McpConnectionException
     */
    public function readResource(string $uri, int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        $span = $this->startSpan('resources/read', ['mcp.resource.uri' => $uri]);
        try {
            $result = $this->requestWithSessionRecovery('resources/read', [
                'uri' => $uri,
            ], $timeoutSeconds);

            return [
                'contents' => $result['contents'] ?? [],
            ];
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Fetch the list of prompts from the MCP server.
     *
     * @return array<int, array{name: string, description: string, arguments?: array}>
     *
     * @throws McpConnectionException
     */
    public function listPrompts(bool $useCache = true, int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        if ($useCache && $this->promptsCache !== null) {
            return $this->promptsCache;
        }

        if (! $this->supportsPrompts()) {
            return [];
        }

        $span = $this->startSpan('prompts/list');
        try {
            $prompts = [];
            foreach ($this->listAllPages('prompts/list', 'prompts', $timeoutSeconds) as $prompt) {
                if (! is_array($prompt) || ! isset($prompt['name'])) {
                    continue;
                }
                $entry = [
                    'name' => (string) $prompt['name'],
                    'description' => (string) ($prompt['description'] ?? ''),
                ];
                if (isset($prompt['arguments']) && is_array($prompt['arguments'])) {
                    $entry['arguments'] = $prompt['arguments'];
                }
                $prompts[] = $entry;
            }

            $this->promptsCache = $prompts;
            $span?->setAttribute('mcp.prompts.count', count($prompts));

            return $prompts;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }
}
