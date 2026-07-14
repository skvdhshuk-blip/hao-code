<?php

namespace HaoCode\Services\Mcp;

/**
 * Transport layer for communicating with an MCP server via JSON-RPC 2.0.
 * Supports stdio (subprocess), http (streamable HTTP), and sse transports.
 */
final class McpTransport
{
    private ?int $nextId = 1;

    /** @var resource|null stdio process handle */
    private $process = null;

    /** @var resource|null stdin pipe */
    private $stdin = null;

    /** @var resource|null stdout pipe */
    private $stdout = null;

    /** @var resource|null stderr pipe for capturing server error logs */
    private $stderr = null;

    /** @var string read buffer for stdio */
    private string $readBuffer = '';

    /** 4 MB read-buffer ceiling to prevent OOM from malicious servers */
    private const READ_BUFFER_MAX = 4 * 1024 * 1024;

    /** Environment variables safe to pass to stdio servers by default. */
    private const STDIO_ENV_ALLOWLIST = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL',
        'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL',
        'SystemRoot', 'ComSpec', 'PATHEXT',
    ];

    private ?string $httpSessionId = null;

    private ?string $protocolVersion = null;

    /** @var array<string, callable[]> Registered notification handlers by method */
    private array $notificationHandlers = [];

    /** @var array<string, callable> Registered inbound request handlers by method (for reverse RPCs) */
    private array $requestHandlers = [];

    private function __construct(
        private readonly string $transport,
        private readonly ?string $command,
        private readonly array $args,
        private readonly ?string $url,
        private readonly array $env,
        private readonly array $headers,
        private readonly ?string $workingDirectory,
    ) {}

    /**
     * Create a transport from a normalized server config array.
     *
     * @param  array{transport: string, command: ?string, args: array, url: ?string, env: array, headers: array, cwd?: string}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            transport: $config['transport'],
            command: $config['command'] ?? null,
            args: $config['args'] ?? [],
            url: $config['url'] ?? null,
            env: $config['env'] ?? [],
            headers: $config['headers'] ?? [],
            workingDirectory: $config['cwd'] ?? null,
        );
    }

    public function getTransportType(): string
    {
        return $this->transport;
    }

    /**
     * Set the protocol version negotiated during initialize.
     * HTTP transports include it on every subsequent request.
     */
    public function setProtocolVersion(string $protocolVersion): void
    {
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Register a handler for inbound notifications (server → client, no response expected).
     */
    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method][] = $handler;
    }

    /**
     * Register a handler for inbound requests (server → client, response required).
     * Handler receives (array $params) and must return the result array.
     */
    public function onRequest(string $method, callable $handler): void
    {
        $this->requestHandlers[$method] = $handler;
    }

    /**
     * Open the transport connection.
     *
     * @throws McpConnectionException
     */
    public function connect(int $timeoutSeconds = 30): void
    {
        match ($this->transport) {
            'stdio' => $this->connectStdio(),
            'http', 'sse' => null, // HTTP transports are stateless per-request
            default => throw new McpConnectionException("Unsupported transport: {$this->transport}"),
        };
    }

    /**
     * Send a JSON-RPC request and return the result.
     *
     * @return mixed The 'result' field from the JSON-RPC response
     *
     * @throws McpConnectionException on transport or protocol errors
     */
    public function request(string $method, array $params = [], int $timeoutSeconds = 60): mixed
    {
        $id = $this->nextId++;
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ];

        return match ($this->transport) {
            'stdio' => $this->sendStdio($message, $timeoutSeconds),
            'http' => $this->sendHttp($message, $timeoutSeconds),
            'sse' => $this->sendHttp($message, $timeoutSeconds),
            default => throw new McpConnectionException("Unsupported transport: {$this->transport}"),
        };
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    public function notify(string $method, array $params = []): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ];

        match ($this->transport) {
            'stdio' => $this->writeStdio($message),
            'http', 'sse' => $this->sendHttpNotification($message),
            default => null,
        };
    }

    /**
     * Drain any pending stderr output from the server process.
     * Returns captured lines (for OTEL logging / debugging).
     *
     * @return string[]
     */
    public function drainStderr(): array
    {
        if ($this->stderr === null) {
            return [];
        }

        $lines = [];
        while (($line = fgets($this->stderr)) !== false) {
            $trimmed = rtrim($line);
            if ($trimmed !== '') {
                $lines[] = $this->redactMessage($trimmed);
            }
        }

        return $lines;
    }

    /**
     * Close the transport and release resources.
     */
    public function close(): void
    {
        if ($this->stdin !== null) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            @fclose($this->stdout);
            $this->stdout = null;
        }
        if ($this->stderr !== null) {
            @fclose($this->stderr);
            $this->stderr = null;
        }
        if ($this->process !== null) {
            // Send SIGTERM, then SIGKILL after a short wait
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 15); // SIGTERM
                usleep(200_000);
                $status = proc_get_status($this->process);
                if ($status['running']) {
                    proc_terminate($this->process, 9); // SIGKILL
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        $this->readBuffer = '';
    }

    public function isConnected(): bool
    {
        if ($this->transport === 'stdio') {
            return $this->process !== null && proc_get_status($this->process)['running'];
        }

        // HTTP transports are always "connected" as long as we have a URL
        return $this->url !== null;
    }

    // ─── stdio transport ────────────────────────────────────────────────

    private function connectStdio(): void
    {
        if ($this->command === null) {
            throw new McpConnectionException('Stdio transport requires a command');
        }

        // Array form bypasses shell interpolation and keeps command/arguments distinct.
        $cmd = array_merge([$this->command], $this->args);
        $env = $this->buildStdioEnvironment();

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr (captured)
        ];

        $this->process = proc_open($cmd, $descriptorSpec, $pipes, $this->workingDirectory, $env);

        if (! is_resource($this->process)) {
            // Omit $cmd to avoid leaking local filesystem paths in exception messages
            throw new McpConnectionException('Failed to start MCP server process');
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set stdout/stderr to non-blocking for timeout support
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    private function writeStdio(array $message): void
    {
        if ($this->stdin === null) {
            throw new McpConnectionException('Stdio transport not connected');
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $written = @fwrite($this->stdin, $json."\n");

        if ($written === false) {
            throw new McpConnectionException('Failed to write to MCP server stdin');
        }

        @fflush($this->stdin);
    }

    private function sendStdio(array $message, int $timeoutSeconds): mixed
    {
        $this->writeStdio($message);

        $deadline = microtime(true) + $timeoutSeconds;
        $expectedId = $message['id'];

        while (microtime(true) < $deadline) {
            // Try to read a complete JSON-RPC message (newline-delimited)
            $chunk = @fread($this->stdout, 65536);
            if ($chunk !== false && $chunk !== '') {
                $this->readBuffer .= $chunk;
            }

            // Guard against OOM: drop buffer and abort if no newline within 4 MB
            if (strlen($this->readBuffer) > self::READ_BUFFER_MAX) {
                $this->readBuffer = '';
                throw McpConnectionException::transport(
                    "MCP server sent oversized payload (>{$this->formatBytes(self::READ_BUFFER_MAX)}) without newline delimiter"
                );
            }

            // Try to extract complete lines
            while (($newlinePos = strpos($this->readBuffer, "\n")) !== false) {
                $line = substr($this->readBuffer, 0, $newlinePos);
                $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $response = json_decode($line, true);
                if (! is_array($response)) {
                    continue;
                }

                // Inbound notification (no id): dispatch to handlers
                if (! isset($response['id']) && isset($response['method'])) {
                    $this->dispatchNotification($response);

                    continue;
                }

                // Inbound request from server (has both id and method): handle and respond
                if (isset($response['id'], $response['method'])) {
                    $this->handleInboundRequest($response);
                    // This is not our awaited response — keep reading
                    if ($response['id'] !== $expectedId) {
                        continue;
                    }
                }

                if (! isset($response['id'])) {
                    continue;
                }

                if ($response['id'] === $expectedId) {
                    if (isset($response['error'])) {
                        throw new McpConnectionException(
                            'MCP error: '.($response['error']['message'] ?? 'Unknown error'),
                            (int) ($response['error']['code'] ?? 0),
                        );
                    }

                    return $response['result'] ?? null;
                }
            }

            // Small sleep to avoid busy-waiting
            usleep(10_000);
        }

        throw new McpConnectionException("MCP request timed out after {$timeoutSeconds}s: {$message['method']}");
    }

    /**
     * Dispatch an inbound notification to registered handlers.
     */
    private function dispatchNotification(array $message): void
    {
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        if (isset($this->notificationHandlers[$method])) {
            foreach ($this->notificationHandlers[$method] as $handler) {
                try {
                    ($handler)($params);
                } catch (\Throwable) {
                    // Handlers must not crash the read loop
                }
            }
        }
    }

    /**
     * Handle an inbound JSON-RPC request from the server and send a response.
     */
    private function handleInboundRequest(array $message): void
    {
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];
        $id = $message['id'];

        if (isset($this->requestHandlers[$method])) {
            try {
                $result = ($this->requestHandlers[$method])($params);
                $this->writeStdio([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $this->writeStdio([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32603,
                        'message' => $e->getMessage(),
                    ],
                ]);
            }
        } else {
            // Method not found
            $this->writeStdio([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32601,
                    'message' => "Method not found: {$method}",
                ],
            ]);
        }
    }

    // ─── HTTP transport (streamable HTTP / SSE) ─────────────────────────

    private function sendHttp(array $message, int $timeoutSeconds): mixed
    {
        if ($this->url === null) {
            throw new McpConnectionException('HTTP transport requires a URL');
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], $this->buildHttpHeaders());

        if ($this->protocolVersion !== null) {
            $headers[] = 'MCP-Protocol-Version: '.$this->protocolVersion;
        }

        if ($this->httpSessionId !== null) {
            $headers[] = 'Mcp-Session-Id: '.$this->httpSessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw McpConnectionException::transport("HTTP request failed: {$curlError}");
        }

        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        // Extract session ID from response headers
        if (preg_match('/^Mcp-Session-Id:\s*(.+)$/mi', $responseHeaders, $m)) {
            $this->httpSessionId = trim($m[1]);
        }

        if ($httpCode === 401) {
            throw McpConnectionException::application('MCP server authentication required (401)', 401);
        }

        if ($httpCode === 404 && $this->httpSessionId !== null) {
            // Session expired — retry once with a fresh session
            $this->httpSessionId = null;
            $headers = array_filter($headers, fn ($h) => ! str_starts_with($h, 'Mcp-Session-Id:'));
            $ch2 = curl_init($this->url);
            curl_setopt_array($ch2, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => array_values($headers),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADER => true,
            ]);
            $rawResponse = curl_exec($ch2);
            $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch2);
            curl_close($ch2);

            if ($rawResponse === false || ($httpCode < 200 || $httpCode >= 300)) {
                $reason = $rawResponse === false ? $curlError : "HTTP {$httpCode}";
                throw McpConnectionException::transport("MCP session expired and retry failed: {$reason}", 404);
            }

            $responseHeaders = substr($rawResponse, 0, $headerSize);
            $responseBody = substr($rawResponse, $headerSize);

            if (preg_match('/^Mcp-Session-Id:\s*(.+)$/mi', $responseHeaders, $m)) {
                $this->httpSessionId = trim($m[1]);
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw McpConnectionException::transport("MCP HTTP error {$httpCode}: ".substr($responseBody, 0, 500));
        }

        // Check if response is SSE (text/event-stream)
        $contentType = '';
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $responseHeaders, $m)) {
            $contentType = trim($m[1]);
        }

        if ($contentType === 'text/event-stream') {
            return $this->parseSSEResponse($responseBody, $message['id']);
        }

        // Standard JSON response
        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            throw McpConnectionException::protocol('Invalid JSON response from MCP server');
        }

        if (isset($decoded['error'])) {
            throw McpConnectionException::application(
                'MCP error: '.($decoded['error']['message'] ?? 'Unknown error'),
                (int) ($decoded['error']['code'] ?? 0),
            );
        }

        return $decoded['result'] ?? null;
    }

    private function sendHttpNotification(array $message): void
    {
        if ($this->url === null) {
            return;
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], $this->buildHttpHeaders());

        if ($this->protocolVersion !== null) {
            $headers[] = 'MCP-Protocol-Version: '.$this->protocolVersion;
        }

        if ($this->httpSessionId !== null) {
            $headers[] = 'Mcp-Session-Id: '.$this->httpSessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw McpConnectionException::transport("HTTP notification failed: {$curlError}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw McpConnectionException::transport("MCP HTTP notification error {$httpCode}", $httpCode);
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildHttpHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }

    /**
     * Parse an SSE (text/event-stream) response body and extract the JSON-RPC result.
     */
    private function parseSSEResponse(string $body, int $expectedId): mixed
    {
        $events = preg_split('/\r?\n\r?\n/', $body);

        foreach ($events as $event) {
            $dataLines = [];
            foreach (explode("\n", $event) as $line) {
                $line = rtrim($line, "\r");
                if (str_starts_with($line, 'data:')) {
                    $value = substr($line, 5);
                    $dataLines[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                }
            }

            $data = implode("\n", $dataLines);

            if ($data === '') {
                continue;
            }

            $decoded = json_decode($data, true);
            if (! is_array($decoded)) {
                continue;
            }

            // Dispatch notifications embedded in SSE stream
            if (! isset($decoded['id']) && isset($decoded['method'])) {
                $this->dispatchNotification($decoded);

                continue;
            }

            if (! isset($decoded['id'])) {
                continue;
            }

            if ($decoded['id'] === $expectedId) {
                if (isset($decoded['error'])) {
                    throw McpConnectionException::application(
                        'MCP error: '.($decoded['error']['message'] ?? 'Unknown error'),
                        (int) ($decoded['error']['code'] ?? 0),
                    );
                }

                return $decoded['result'] ?? null;
            }
        }

        throw McpConnectionException::protocol('No matching response found in SSE stream');
    }

    /**
     * Scrub common sensitive patterns (API keys, tokens, paths) from log lines.
     */
    private function redactMessage(string $line): string
    {
        // Redact bearer tokens and API keys
        $line = preg_replace('/\b(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i', '$1[REDACTED]', $line) ?? $line;
        $line = preg_replace('/\b([A-Za-z_-]*(key|token|secret|password|passwd|pwd|auth)[A-Za-z_-]*\s*[=:]\s*)[^\s,;"\']+/i', '$1[REDACTED]', $line) ?? $line;

        // Redact absolute filesystem paths (keep basename only for readability)
        $line = preg_replace_callback('/(?:\/[a-zA-Z0-9_.~-]+){3,}/', static function (array $m): string {
            return '/.../'.basename($m[0]);
        }, $line) ?? $line;

        return $line;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 0).'MB';
        }

        return round($bytes / 1024, 0).'KB';
    }

    /**
     * Pass only process-launch essentials plus variables explicitly configured
     * for this MCP server. Host credentials are not inherited implicitly.
     *
     * @return array<string, string>
     */
    private function buildStdioEnvironment(): array
    {
        $environment = [];
        foreach (self::STDIO_ENV_ALLOWLIST as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return array_merge($environment, $this->env);
    }
}
