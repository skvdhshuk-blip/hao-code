<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Telemetry\PhoenixTracer;
use OpenTelemetry\API\Trace\SpanInterface;

trait McpClientListAllPagesConcern
{

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
