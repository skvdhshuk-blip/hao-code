<?php

namespace HaoCode\Services\Mcp\Server;

use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * Minimal MCP server: stdio JSON-RPC 2.0 with newline-delimited messages.
 *
 * Handles: initialize / tools/list / tools/call / prompts/list / prompts/get / shutdown / exit
 * Frame format: one JSON-RPC message per line.
 * Hard limit: 1 MiB per frame.
 */
class McpServer
{
    private const MAX_FRAME_BYTES = 1048576; // 1 MiB

    private const WRITE_TIMEOUT_SECONDS = 5.0;

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
            return $this->encodeMessage([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
        }

        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = $msg['params'] ?? [];

        if (! is_string($method) || $method === '') {
            return $this->encodeMessage([
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
                return $this->encodeMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            }

            return null;
        } catch (\RuntimeException $e) {
            $code = str_starts_with($e->getMessage(), 'Method not found') ? -32601 : -32603;
            $message = str_starts_with($e->getMessage(), 'Method not found') ? 'Method not found' : 'Internal error';
            $this->tracer?->recordException($span, $e);

            return $this->encodeMessage(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
        } catch (\Throwable $e) {
            $this->tracer?->recordException($span, $e);

            return $this->encodeMessage(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error']]);
        } finally {
            $span?->end();
        }
    }

    private function readFrame(): ?string
    {
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                return null;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strlen($line) > self::MAX_FRAME_BYTES) {
                fwrite(STDERR, "MCP message exceeds 1 MiB limit; closing\n");
                $this->running = false;

                return null;
            }

            return $line;
        }
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
            if (strlen($this->readBuffer) > self::MAX_FRAME_BYTES) {
                fwrite(STDERR, "MCP message exceeds 1 MiB limit; closing\n");
                $this->running = false;

                return null;
            }
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
                'version' => \HaoCode\Support\Runtime\SdkRuntime::config('app.version', 'dev'),
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
        $this->writeFrame($this->encodeMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]));
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->writeFrame($this->encodeMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]));
    }

    private function writeFrame(string $json): void
    {
        if (strlen($json) > self::MAX_FRAME_BYTES) {
            $decoded = json_decode($json, true);
            $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
            $json = json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => 'MCP response exceeds 1 MiB limit',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (! is_string($json)) {
                $this->running = false;

                return;
            }
        }

        $payload = $json."\n";
        $offset = 0;
        $deadline = microtime(true) + self::WRITE_TIMEOUT_SECONDS;
        $previousBlocking = stream_get_meta_data(STDOUT)['blocked'] ?? true;
        @stream_set_blocking(STDOUT, false);
        try {
            while ($offset < strlen($payload) && microtime(true) < $deadline) {
                $written = @fwrite(STDOUT, substr($payload, $offset));
                if ($written === false) {
                    $this->running = false;

                    return;
                }
                if ($written > 0) {
                    $offset += $written;
                    continue;
                }
                usleep(10_000);
            }
        } finally {
            @stream_set_blocking(STDOUT, (bool) $previousBlocking);
        }

        if ($offset < strlen($payload)) {
            $this->running = false;
        } else {
            fflush(STDOUT);
        }
    }

    /** @param array<string, mixed> $message */
    private function encodeMessage(array $message): string
    {
        try {
            return json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        }
    }
}
