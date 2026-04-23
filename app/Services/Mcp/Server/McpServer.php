<?php

namespace HaoCode\Services\Mcp\Server;

use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * Minimal MCP server: stdio JSON-RPC 2.0 with LSP-style framing.
 *
 * Handles: initialize / tools/list / tools/call / prompts/list / prompts/get / shutdown / exit
 * Frame format: Content-Length: N\r\n\r\n<JSON>
 * Hard limit: 1 MiB per frame.
 */
class McpServer
{
    private const MAX_FRAME_BYTES = 1048576; // 1 MiB

    private const PROTOCOL_VERSION = '2024-11-05';

    private bool $initialized = false;

    private bool $running = true;

    private string $readBuffer = '';

    /** @var int Bash calls in flight */
    private int $bashConcurrency = 0;

    public function __construct(
        private readonly ToolAdapter $toolAdapter,
        private readonly ?PhoenixTracer $tracer = null,
        private readonly string $caller = 'unknown',
    ) {}

    public function run(): void
    {
        $sessionSpan = $this->tracer?->startSpan('mcp.server.session', PhoenixTracer::KIND_AGENT, [
            'mcp.caller' => $this->caller,
        ]);

        try {
            while ($this->running) {
                $frame = $this->readFrame();
                if ($frame === null) {
                    break;
                }
                $this->dispatch($frame);
            }
        } finally {
            $sessionSpan?->end();
            $this->tracer?->shutdown();
        }
    }

    /**
     * Handle a single JSON-RPC message and return the JSON-encoded response (or null for notifications).
     * Extracted for reuse by HTTP transport.
     */
    public function handleMessage(string $json): ?string
    {
        $msg = json_decode($json, true);
        if (! is_array($msg)) {
            return json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
        }

        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = $msg['params'] ?? [];

        if (! is_string($method) || $method === '') {
            return json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32600, 'message' => 'Invalid Request'],
            ]);
        }

        $spanName = match ($method) {
            'tools/call' => 'mcp.server.tool.call',
            default => 'mcp.server.'.str_replace('/', '.', $method),
        };
        $span = $this->tracer?->startSpan($spanName, PhoenixTracer::KIND_TOOL, [
            'mcp.caller' => $this->caller,
            'mcp.method' => $method,
        ]);

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params, $span),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($params),
                'notifications/initialized' => null,
                'shutdown' => (function () {
                    $this->running = false;

                    return [];
                })(),
                'exit' => (function () {
                    $this->running = false;

                    return null;
                })(),
                default => throw new \RuntimeException("Method not found: $method"),
            };

            if ($result === null) {
                return null; // notification — no response
            }

            if ($id !== null) {
                return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            }

            return null;
        } catch (\RuntimeException $e) {
            $code = str_starts_with($e->getMessage(), 'Method not found') ? -32601 : -32603;
            $message = str_starts_with($e->getMessage(), 'Method not found') ? 'Method not found' : 'Internal error';
            $this->tracer?->recordException($span, $e);

            return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);

            return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error']]);
        } finally {
            $span?->end();
        }
    }

    private function readFrame(): ?string
    {
        $contentLength = null;

        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                return null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                // blank line = end of headers
                if ($contentLength === null) {
                    continue;
                }
                break;
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($line, 15));
                if ($contentLength > self::MAX_FRAME_BYTES) {
                    fwrite(STDERR, "MCP frame exceeds 1 MiB limit; closing\n");
                    $this->running = false;

                    return null;
                }
            }
        }

        if ($contentLength === null || $contentLength <= 0) {
            return null;
        }

        // Body may already be partially or fully in $this->readBuffer
        while (strlen($this->readBuffer) < $contentLength) {
            $chunk = fread(STDIN, $contentLength - strlen($this->readBuffer));
            if ($chunk === false || $chunk === '') {
                if (feof(STDIN)) {
                    return null;
                }

                continue;
            }
            $this->readBuffer .= $chunk;
        }

        $body = substr($this->readBuffer, 0, $contentLength);
        $this->readBuffer = substr($this->readBuffer, $contentLength);

        return $body;
    }

    private function readLine(): ?string
    {
        while (true) {
            $pos = strpos($this->readBuffer, "\n");
            if ($pos !== false) {
                $line = substr($this->readBuffer, 0, $pos + 1);
                $this->readBuffer = substr($this->readBuffer, $pos + 1);

                return $line;
            }
            $data = fread(STDIN, 4096);
            if ($data === false || $data === '') {
                if (feof(STDIN)) {
                    return null;
                }

                continue;
            }
            $this->readBuffer .= $data;
        }
    }

    private function dispatch(string $json): void
    {
        $response = $this->handleMessage($json);
        if ($response !== null) {
            $this->writeFrame($response);
        }
    }

    private function handleInitialize(array $params): array
    {
        $this->initialized = true;
        $allowedTools = array_map(fn ($t) => $t['name'], $this->toolAdapter->listTools());

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'hao-code',
                'version' => config('app.version', 'dev'),
                'meta' => [
                    'allowed_tools' => $allowedTools,
                    'bash_concurrency' => 1,
                    'expose_skills' => 'project',
                ],
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return ['tools' => $this->toolAdapter->listTools()];
    }

    private function handleToolsCall(array $params, $span): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        if ($name === '') {
            return $this->mcpError('Tool name required');
        }

        // Bash concurrency gate
        if (str_starts_with($name, 'Bash') || $name === 'Bash') {
            if ($this->bashConcurrency >= 1) {
                return $this->mcpError('access denied');
            }
            $this->bashConcurrency++;
        }

        $span?->setAttribute('mcp.tool', $name);
        $span?->setAttribute('mcp.args_hash', hash('sha256', json_encode($args) ?: ''));

        try {
            $result = $this->toolAdapter->invoke($name, $args);
            $span?->setAttribute('mcp.decision', 'allow');

            return $result;
        } catch (\Throwable $e) {
            $span?->setAttribute('mcp.decision', 'error');
            $this->tracer?->recordException($span, $e);

            return $this->mcpError($e->getMessage());
        } finally {
            if (str_starts_with($name, 'Bash') || $name === 'Bash') {
                $this->bashConcurrency = max(0, $this->bashConcurrency - 1);
            }
        }
    }

    private function handlePromptsList(): array
    {
        return ['prompts' => $this->toolAdapter->listPrompts()];
    }

    private function handlePromptsGet(array $params): array
    {
        $name = $params['name'] ?? '';
        if ($name === '') {
            return $this->mcpError('access denied');
        }

        return $this->toolAdapter->getPrompt($name);
    }

    private function mcpError(string $message): array
    {
        return [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => $message]],
        ];
    }

    private function sendResult(mixed $id, array $result): void
    {
        $this->writeFrame(json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]));
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->writeFrame(json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]));
    }

    private function writeFrame(string $json): void
    {
        $len = strlen($json);
        fwrite(STDOUT, "Content-Length: {$len}\r\n\r\n{$json}");
    }
}
