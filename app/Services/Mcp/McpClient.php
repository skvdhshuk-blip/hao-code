<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Telemetry\PhoenixTracer;
use OpenTelemetry\API\Trace\SpanInterface;

/**
 * MCP protocol client — handles initialization handshake, tool/resource/prompt
 * discovery and invocation over a McpTransport.
 */
final class McpClient
{
    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
        '2024-10-07',
    ];

    /** Safety cap for tools/list, resources/list, prompts/list pagination. */
    private const LIST_MAX_PAGES = 100;

    /** Prevent a valid-looking paginated server from exhausting client memory. */
    private const LIST_MAX_ITEMS = 10_000;

    /** Bound decoded list payloads in addition to the per-page transport cap. */
    private const LIST_MAX_AGGREGATE_BYTES = 16 * 1024 * 1024;

    /** Cursor values are protocol metadata, not an unbounded payload channel. */
    private const LIST_MAX_CURSOR_BYTES = 16_384;

    private bool $initialized = false;

    /** @var array<string, mixed>|null Server capabilities from initialize response */
    private ?array $capabilities = null;

    /** @var array{name: string, version: string}|null */
    private ?array $serverInfo = null;

    /** @var string|null Server instructions */
    private ?string $instructions = null;

    /** @var array<int, array{name: string, description: string, inputSchema: array}>|null Cached tools list */
    private ?array $toolsCache = null;

    /** @var array<int, array{uri: string, name: string, mimeType?: string, description?: string}>|null */
    private ?array $resourcesCache = null;

    /** @var array<int, array{name: string, description: string, arguments?: array}>|null */
    private ?array $promptsCache = null;

    /** @var array<array{uri: string, name?: string}> Workspace roots reported to the server */
    private array $roots = [];

    private bool $protocolHandlersRegistered = false;

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

    /**
     * Walk MCP list endpoints following nextCursor until exhausted.
     *
     * @return list<mixed>
     *
     * @throws McpConnectionException
     */
    private function listAllPages(string $method, string $itemsKey, int $timeoutSeconds): array
    {
        $items = [];
        $aggregateBytes = 0;
        $cursor = null;
        $seenCursors = [];
        $hasMore = true;
        // One absolute deadline for the whole list operation (not per page).
        $deadline = microtime(true) + max(0.001, (float) $timeoutSeconds);

        for ($page = 0; $page < self::LIST_MAX_PAGES && $hasMore; $page++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new McpConnectionException(
                    "MCP {$method} timed out after {$timeoutSeconds}s while following nextCursor.",
                );
            }
            $params = [];
            if (is_string($cursor) && $cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $result = $this->requestWithSessionRecovery($method, $params, $remaining, $deadline);
            if (! is_array($result)) {
                $hasMore = false;
                break;
            }

            $pageItems = $result[$itemsKey] ?? [];
            if (is_array($pageItems)) {
                foreach ($pageItems as $item) {
                    if (count($items) >= self::LIST_MAX_ITEMS) {
                        throw new McpConnectionException(
                            "MCP {$method} exceeded ".self::LIST_MAX_ITEMS.' aggregated items.',
                        );
                    }

                    $encodedItem = json_encode(
                        $item,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );
                    $itemBytes = is_string($encodedItem)
                        ? strlen($encodedItem)
                        : strlen(serialize($item));
                    if ($aggregateBytes + $itemBytes > self::LIST_MAX_AGGREGATE_BYTES) {
                        throw new McpConnectionException(
                            "MCP {$method} exceeded ".self::LIST_MAX_AGGREGATE_BYTES
                            .' aggregated bytes.',
                        );
                    }

                    $items[] = $item;
                    $aggregateBytes += $itemBytes;
                }
            }

            $next = $result['nextCursor'] ?? null;
            if (! is_string($next) || $next === '') {
                $hasMore = false;
                break;
            }
            if (strlen($next) > self::LIST_MAX_CURSOR_BYTES) {
                throw new McpConnectionException(
                    "MCP {$method} returned a cursor larger than ".self::LIST_MAX_CURSOR_BYTES.' bytes.',
                );
            }
            if (isset($seenCursors[$next])) {
                throw new McpConnectionException(
                    "MCP {$method} pagination loop detected at cursor.",
                );
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }

        if ($hasMore) {
            throw new McpConnectionException(
                "MCP {$method} exceeded ".self::LIST_MAX_PAGES.' pages while following nextCursor.',
            );
        }

        return $items;
    }

    /**
     * Get a specific prompt with optional argument substitution.
     *
     * @param  array<string, string>  $arguments  Prompt argument values
     * @return array{description?: string, messages: array}
     *
     * @throws McpConnectionException
     */
    public function getPrompt(string $name, array $arguments = [], int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        $span = $this->startSpan('prompts/get', ['mcp.prompt.name' => $name]);
        try {
            $params = ['name' => $name];
            if (! empty($arguments)) {
                $params['arguments'] = $arguments;
            }

            $result = $this->requestWithSessionRecovery('prompts/get', $params, $timeoutSeconds);

            return [
                'description' => $result['description'] ?? null,
                'messages' => $result['messages'] ?? [],
            ];
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Send a ping to the server to check liveness.
     *
     * @throws McpConnectionException
     */
    public function ping(int $timeoutSeconds = 10): void
    {
        $this->ensureInitialized();

        $span = $this->startSpan('ping');
        try {
            $this->requestWithSessionRecovery('ping', [], $timeoutSeconds);
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Request the server to set its log level.
     *
     * @param  string  $level  One of: debug, info, notice, warning, error, critical, alert, emergency
     *
     * @throws McpConnectionException
     */
    public function setLogLevel(string $level, int $timeoutSeconds = 10): void
    {
        $this->ensureInitialized();

        $span = $this->startSpan('logging/setLevel', ['mcp.log.level' => $level]);
        try {
            $this->requestWithSessionRecovery('logging/setLevel', ['level' => $level], $timeoutSeconds);
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Request argument completion from the server.
     *
     * @param  array{type: 'ref/prompt'|'ref/resource', name?: string, uri?: string}  $ref
     * @param  array{name: string, value: string}  $argument
     * @return array{values: string[], total?: int, hasMore?: bool}
     *
     * @throws McpConnectionException
     */
    public function complete(array $ref, array $argument, int $timeoutSeconds = 30): array
    {
        $this->ensureInitialized();

        $span = $this->startSpan('completion/complete', ['mcp.completion.ref_type' => $ref['type'] ?? '']);
        try {
            $result = $this->requestWithSessionRecovery('completion/complete', [
                'ref' => $ref,
                'argument' => $argument,
            ], $timeoutSeconds);

            $completion = $result['completion'] ?? [];

            return [
                'values' => $completion['values'] ?? [],
                'total' => $completion['total'] ?? null,
                'hasMore' => $completion['hasMore'] ?? false,
            ];
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Clear cached tools, resources, and prompts (e.g. after reconnection).
     */
    public function clearCache(): void
    {
        $this->toolsCache = null;
        $this->resourcesCache = null;
        $this->promptsCache = null;
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        $this->resetConnectionState();
    }

    public function isConnected(): bool
    {
        return $this->initialized && $this->transport->isConnected();
    }

    /**
     * Cooperatively process pending server-initiated messages.
     *
     * Stdio has no independent reader thread: an idle host must call poll(),
     * or issue another MCP request, for inbound notifications/reverse
     * requests to be observed and answered.
     */
    public function poll(float $timeoutSeconds = 0.0): void
    {
        $this->ensureInitialized();
        $this->transport->poll($timeoutSeconds);
    }

    private function ensureInitialized(): void
    {
        if (! $this->initialized) {
            throw new McpConnectionException("MCP client for '{$this->serverName}' is not initialized. Call connect() first.");
        }
    }

    private function registerProtocolHandlers(): void
    {
        if ($this->protocolHandlersRegistered) {
            return;
        }

        $this->transport->onRequest('roots/list', function (array $params): array {
            return ['roots' => $this->roots];
        });
        $this->transport->onNotification('notifications/tools/list_changed', function (): void {
            $this->toolsCache = null;
        });
        $this->transport->onNotification('notifications/resources/list_changed', function (): void {
            $this->resourcesCache = null;
        });
        $this->transport->onNotification('notifications/prompts/list_changed', function (): void {
            $this->promptsCache = null;
        });
        $this->protocolHandlersRegistered = true;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requestWithSessionRecovery(
        string $method,
        array $params,
        float $timeoutSeconds,
        ?float $deadline = null,
    ): mixed
    {
        $deadline ??= microtime(true) + max(0.001, $timeoutSeconds);

        try {
            $this->transport->poll();

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP request timed out before dispatch: {$method}"
                );
            }

            return $this->transport->request($method, $params, $remaining);
        } catch (McpSessionExpiredException) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP request timed out while recovering session: {$method}"
                );
            }

            $this->initialized = false;
            $this->clearCache();
            $this->transport->resetHttpSession();
            $this->connectUntil(
                min($deadline, microtime(true) + 30.0),
                min(30.0, $remaining),
            );

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw McpConnectionException::transport(
                    "MCP request timed out after recovering session: {$method}"
                );
            }

            return $this->transport->request($method, $params, $remaining);
        }
    }

    /**
     * Start an OTEL span named `mcp.client.request.<method>`.
     * Returns null when telemetry is disabled (no-op path).
     *
     * @param  array<string, scalar>  $extraAttributes
     */
    private function startSpan(string $method, array $extraAttributes = []): ?SpanInterface
    {
        return $this->tracer?->startSpan(
            'mcp.client.request.'.str_replace('/', '.', $method),
            PhoenixTracer::KIND_TOOL,
            array_merge([
                'mcp.server.name' => $this->serverName,
                'mcp.method' => $method,
            ], $extraAttributes),
        );
    }
}
