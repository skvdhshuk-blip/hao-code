<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    private const SERVER_STREAM_TIMEOUT_SECONDS = 30;

    private const SERVER_STREAM_MAX_DURATION_SECONDS = 86400;

    /** Environment variables safe to pass to stdio servers by default. */
    private const STDIO_ENV_ALLOWLIST = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL',
        'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL',
        'SystemRoot', 'ComSpec', 'PATHEXT',
    ];

    private ?string $httpSessionId = null;

    private ?string $protocolVersion = null;

    private HttpClientInterface $httpClient;

    private ?McpOAuthTokenProvider $oauthTokenProvider;

    private ?ResponseInterface $serverEventStream = null;

    private ?McpSseDecoder $serverEventDecoder = null;

    private ?string $serverLastEventId = null;

    private int $serverRetryMilliseconds = 1000;

    private float $serverReconnectAt = 0.0;

    private bool $serverEventStreamSupported = true;

    private bool $httpSessionExpired = false;

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
        ?HttpClientInterface $httpClient,
        array $oauth,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
        $this->oauthTokenProvider = $oauth !== []
            ? new McpOAuthTokenProvider($oauth, $this->httpClient)
            : null;
    }

    /**
     * Create a transport from a normalized server config array.
     *
     * @param  array{transport: string, command: ?string, args: array, url: ?string, env: array, headers: array, cwd?: string, oauth?: array<string, string>}  $config
     */
    public static function fromConfig(array $config, ?HttpClientInterface $httpClient = null): self
    {
        return new self(
            transport: $config['transport'],
            command: $config['command'] ?? null,
            args: $config['args'] ?? [],
            url: $config['url'] ?? null,
            env: $config['env'] ?? [],
            headers: $config['headers'] ?? [],
            workingDirectory: $config['cwd'] ?? null,
            httpClient: $httpClient,
            oauth: $config['oauth'] ?? [],
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
            'http', 'sse' => $this->resetHttpSession(),
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
        if ($this->httpSessionExpired) {
            throw new McpSessionExpiredException;
        }

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
        if (($this->transport === 'http' || $this->transport === 'sse') && $this->httpSessionId !== null) {
            $this->closeHttpSession();
        }
        $this->cancelServerEventStream();

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
        $this->httpSessionId = null;
        $this->protocolVersion = null;
        $this->httpSessionExpired = false;
    }

    public function isConnected(): bool
    {
        if ($this->transport === 'stdio') {
            return $this->process !== null && proc_get_status($this->process)['running'];
        }

        return $this->url !== null && ! $this->httpSessionExpired;
    }

    /**
     * Start the optional server-initiated GET event stream.
     */
    public function startServerEventStream(): void
    {
        if (($this->transport !== 'http' && $this->transport !== 'sse') || ! $this->serverEventStreamSupported) {
            return;
        }

        $this->openServerEventStream();
    }

    /**
     * Cooperatively process server-initiated messages without blocking the caller.
     */
    public function poll(float $timeoutSeconds = 0.0): void
    {
        if ($this->httpSessionExpired) {
            throw new McpSessionExpiredException;
        }

        if ($this->serverEventStream === null) {
            if ($this->serverEventStreamSupported && microtime(true) >= $this->serverReconnectAt) {
                $this->openServerEventStream();
            }

            return;
        }

        $deadline = microtime(true) + max(0.0, $timeoutSeconds);
        $this->pumpServerEventStream($deadline);
    }

    /**
     * Clear transport-level state before a required MCP re-initialization.
     */
    public function resetHttpSession(): void
    {
        $this->cancelServerEventStream();
        $this->httpSessionId = null;
        $this->protocolVersion = null;
        $this->httpSessionExpired = false;
        $this->serverLastEventId = null;
        $this->serverRetryMilliseconds = 1000;
        $this->serverReconnectAt = 0.0;
        $this->serverEventStreamSupported = true;
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
                $this->sendInboundResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $this->sendInboundResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Client request handler failed',
                    ],
                ]);
            }
        } else {
            $this->sendInboundResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32601,
                    'message' => "Method not found: {$method}",
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $message */
    private function sendInboundResponse(array $message): void
    {
        match ($this->transport) {
            'stdio' => $this->writeStdio($message),
            'http', 'sse' => $this->sendHttpOneWay($message),
            default => null,
        };
    }

    // ─── HTTP transport (Streamable HTTP / legacy SSE response) ─────────

    /** @param array<string, mixed> $message */
    private function sendHttp(array $message, int $timeoutSeconds): mixed
    {
        $this->assertHttpReady();
        $deadline = microtime(true) + $timeoutSeconds;
        $response = $this->createHttpRequest('POST', $message, $timeoutSeconds);
        $status = $this->prepareHttpResponse($response, true, $message, $timeoutSeconds);

        if ($status < 200 || $status >= 300) {
            $body = $this->responsePreview($response);
            throw McpConnectionException::transport("MCP HTTP error {$status}: {$body}", $status);
        }

        $contentType = $this->responseContentType($response);
        if ($contentType === 'text/event-stream') {
            return $this->consumeSseRequest($response, $message['id'], $deadline);
        }
        if ($contentType !== 'application/json') {
            throw McpConnectionException::protocol(
                'MCP HTTP response has unsupported Content-Type: '.($contentType ?: 'missing')
            );
        }

        return $this->decodeJsonRpcResponse($response->getContent(false), $message['id']);
    }

    /** @param array<string, mixed> $message */
    private function sendHttpNotification(array $message): void
    {
        $this->sendHttpOneWay($message);
    }

    /** @param array<string, mixed> $message */
    private function sendHttpOneWay(array $message): void
    {
        $this->assertHttpReady();
        $response = $this->createHttpRequest('POST', $message, 5);
        $status = $this->prepareHttpResponse($response, true, $message, 5);

        if ($status === 202 || $status === 204) {
            return;
        }
        if ($status >= 200 && $status < 300 && $response->getContent(false) === '') {
            return;
        }

        throw McpConnectionException::transport("MCP HTTP one-way message returned {$status}", $status);
    }

    /**
     * @param array<string, mixed>|null $message
     */
    private function createHttpRequest(
        string $method,
        ?array $message,
        int $timeoutSeconds,
        ?string $lastEventId = null,
        ?int $maxDurationSeconds = null,
    ): ResponseInterface {
        $options = [
            'headers' => $this->buildHttpHeaders($message !== null),
            'timeout' => max(1, $timeoutSeconds),
            'max_duration' => max(1, $maxDurationSeconds ?? $timeoutSeconds),
            'buffer' => false,
        ];
        if ($message !== null) {
            $options['json'] = $message;
        }
        if ($lastEventId !== null) {
            $options['headers']['Last-Event-ID'] = $lastEventId;
        }

        try {
            return $this->httpClient->request($method, (string) $this->url, $options);
        } catch (\Throwable $exception) {
            throw McpConnectionException::transport('MCP HTTP request failed: '.$exception->getMessage());
        }
    }

    /**
     * Resolve headers/status and retry once after a configured OAuth refresh.
     *
     * @param array<string, mixed>|null $message
     */
    private function prepareHttpResponse(
        ResponseInterface &$response,
        bool $allowOAuthRetry,
        ?array $message,
        int $timeoutSeconds,
        string $method = 'POST',
        ?string $lastEventId = null,
        ?int $maxDurationSeconds = null,
    ): int {
        try {
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (\Throwable $exception) {
            throw McpConnectionException::transport('MCP HTTP response failed: '.$exception->getMessage());
        }

        if ($status === 401
            && $allowOAuthRetry
            && ! $this->hasHeader($this->headers, 'Authorization')
            && $this->oauthTokenProvider?->refreshAfterUnauthorized()) {
            $response->cancel();
            $response = $this->createHttpRequest(
                $method,
                $message,
                $timeoutSeconds,
                $lastEventId,
                $maxDurationSeconds,
            );

            return $this->prepareHttpResponse(
                $response,
                false,
                $message,
                $timeoutSeconds,
                $method,
                $lastEventId,
                $maxDurationSeconds,
            );
        }

        if ($status === 401) {
            throw McpConnectionException::application('MCP server authentication required (401)', 401);
        }

        if ($status === 404 && $this->httpSessionId !== null) {
            $this->markHttpSessionExpired();
            throw new McpSessionExpiredException;
        }

        if (isset($headers['mcp-session-id'][0])) {
            $this->acceptSessionId($headers['mcp-session-id'][0]);
        }

        return $status;
    }

    /**
     * @return array<string, string>
     */
    private function buildHttpHeaders(bool $hasBody): array
    {
        $headers = $this->headers;
        $headers['Accept'] = $hasBody
            ? 'application/json, text/event-stream'
            : 'text/event-stream';
        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }
        if ($this->httpSessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->httpSessionId;
        }
        if (! $this->hasHeader($headers, 'Authorization')) {
            $authorization = $this->oauthTokenProvider?->authorizationHeader();
            if ($authorization !== null) {
                $headers['Authorization'] = $authorization;
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function responseContentType(ResponseInterface $response): string
    {
        $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');

        return trim(explode(';', $contentType, 2)[0]);
    }

    private function responsePreview(ResponseInterface $response): string
    {
        try {
            return substr($response->getContent(false), 0, 500);
        } catch (\Throwable) {
            return '';
        }
    }

    private function decodeJsonRpcResponse(string $body, int|string $expectedId): mixed
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! array_key_exists('id', $decoded)) {
            throw McpConnectionException::protocol('Invalid JSON response from MCP server');
        }
        if ((string) $decoded['id'] !== (string) $expectedId) {
            throw McpConnectionException::protocol('MCP response ID did not match the request');
        }
        if (isset($decoded['error'])) {
            throw McpConnectionException::application(
                'MCP error: '.($decoded['error']['message'] ?? 'Unknown error'),
                (int) ($decoded['error']['code'] ?? 0),
            );
        }

        return $decoded['result'] ?? null;
    }

    private function consumeSseRequest(
        ResponseInterface $response,
        int|string $expectedId,
        float $deadline,
    ): mixed {
        $decoder = new McpSseDecoder(self::READ_BUFFER_MAX);
        $lastEventId = null;
        $retryMilliseconds = 1000;

        while (microtime(true) < $deadline) {
            $ended = false;
            $responses = [$response];
            if ($this->serverEventStream !== null) {
                $responses[] = $this->serverEventStream;
            }

            try {
                foreach ($this->httpClient->stream($responses, min(1.0, max(0.001, $deadline - microtime(true)))) as $streamResponse => $chunk) {
                    if ($chunk->isTimeout()) {
                        break;
                    }
                    if ($chunk->isFirst()) {
                        continue;
                    }

                    if ($streamResponse === $this->serverEventStream) {
                        $this->consumeServerStreamChunk($chunk->isLast() ? '' : $chunk->getContent(), $chunk->isLast());

                        continue;
                    }

                    $events = $decoder->push($chunk->isLast() ? '' : $chunk->getContent(), $chunk->isLast());
                    foreach ($events as $event) {
                        if ($event['id'] !== null) {
                            $lastEventId = $event['id'];
                        }
                        if ($event['retry'] !== null) {
                            $retryMilliseconds = $event['retry'];
                        }
                        $result = $this->routeSseEvent($event, $expectedId);
                        if ($result['matched']) {
                            $response->cancel();

                            return $result['result'];
                        }
                    }

                    if ($chunk->isLast()) {
                        $ended = true;
                        break;
                    }
                }
            } catch (McpConnectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $ended = true;
            }

            if (! $ended) {
                continue;
            }
            if ($lastEventId === null) {
                throw McpConnectionException::protocol('MCP SSE stream ended before a matching response');
            }

            $this->waitForRetry($retryMilliseconds, $deadline);
            $response = $this->createHttpRequest(
                'GET',
                null,
                max(1, (int) ceil($deadline - microtime(true))),
                $lastEventId,
            );
            $status = $this->prepareHttpResponse(
                $response,
                true,
                null,
                max(1, (int) ceil($deadline - microtime(true))),
                'GET',
                $lastEventId,
            );
            if ($status !== 200 || $this->responseContentType($response) !== 'text/event-stream') {
                throw McpConnectionException::transport("MCP SSE resume failed with HTTP {$status}", $status);
            }
        }

        $this->sendCancellation($expectedId);
        throw McpConnectionException::transport('MCP Streamable HTTP request timed out');
    }

    /**
     * @param array{data: string, id: ?string, retry: ?int, event: ?string} $event
     * @return array{matched: bool, result: mixed}
     */
    private function routeSseEvent(array $event, int|string|null $expectedId): array
    {
        if ($event['data'] === '') {
            return ['matched' => false, 'result' => null];
        }

        $message = json_decode($event['data'], true);
        if (! is_array($message)) {
            throw McpConnectionException::protocol('MCP SSE event contained invalid JSON');
        }
        if (isset($message['method']) && array_key_exists('id', $message)) {
            $this->handleInboundRequest($message);

            return ['matched' => false, 'result' => null];
        }
        if (isset($message['method'])) {
            $this->dispatchNotification($message);

            return ['matched' => false, 'result' => null];
        }
        if ($expectedId === null || ! array_key_exists('id', $message) || (string) $message['id'] !== (string) $expectedId) {
            return ['matched' => false, 'result' => null];
        }
        if (isset($message['error'])) {
            throw McpConnectionException::application(
                'MCP error: '.($message['error']['message'] ?? 'Unknown error'),
                (int) ($message['error']['code'] ?? 0),
            );
        }

        return ['matched' => true, 'result' => $message['result'] ?? null];
    }

    private function openServerEventStream(): void
    {
        if ($this->url === null || $this->serverEventStream !== null || ! $this->serverEventStreamSupported) {
            return;
        }

        try {
            $response = $this->createHttpRequest(
                'GET',
                null,
                self::SERVER_STREAM_TIMEOUT_SECONDS,
                $this->serverLastEventId,
                self::SERVER_STREAM_MAX_DURATION_SECONDS,
            );
            $status = $this->prepareHttpResponse(
                $response,
                true,
                null,
                self::SERVER_STREAM_TIMEOUT_SECONDS,
                'GET',
                $this->serverLastEventId,
                self::SERVER_STREAM_MAX_DURATION_SECONDS,
            );
        } catch (McpSessionExpiredException $exception) {
            throw $exception;
        } catch (\Throwable) {
            if (isset($response)) {
                $response->cancel();
            }
            $this->scheduleServerReconnect();

            return;
        }

        if ($status === 405) {
            $response->cancel();
            $this->serverEventStreamSupported = false;

            return;
        }
        if ($status !== 200 || $this->responseContentType($response) !== 'text/event-stream') {
            $response->cancel();
            $this->scheduleServerReconnect();

            return;
        }

        $this->serverEventStream = $response;
        $this->serverEventDecoder = new McpSseDecoder(self::READ_BUFFER_MAX);
    }

    private function pumpServerEventStream(float $deadline): void
    {
        if ($this->serverEventStream === null) {
            return;
        }

        try {
            foreach ($this->httpClient->stream($this->serverEventStream, max(0.001, $deadline - microtime(true))) as $chunk) {
                if ($chunk->isTimeout()) {
                    break;
                }
                $this->consumeServerStreamChunk($chunk->isLast() ? '' : $chunk->getContent(), $chunk->isLast());
                if ($chunk->isLast() || microtime(true) >= $deadline) {
                    break;
                }
            }
        } catch (McpConnectionException $exception) {
            $this->cancelServerEventStream();
            throw $exception;
        } catch (\Throwable) {
            $this->cancelServerEventStream();
            $this->scheduleServerReconnect();
        }
    }

    private function consumeServerStreamChunk(string $content, bool $endOfStream): void
    {
        if ($this->serverEventDecoder === null) {
            return;
        }

        foreach ($this->serverEventDecoder->push($content, $endOfStream) as $event) {
            if ($event['id'] !== null) {
                $this->serverLastEventId = $event['id'];
            }
            if ($event['retry'] !== null) {
                $this->serverRetryMilliseconds = $event['retry'];
            }
            $this->routeSseEvent($event, null);
        }

        if ($endOfStream) {
            $this->cancelServerEventStream();
            $this->scheduleServerReconnect();
        }
    }

    private function scheduleServerReconnect(): void
    {
        $this->serverReconnectAt = microtime(true) + ($this->serverRetryMilliseconds / 1000);
    }

    private function cancelServerEventStream(): void
    {
        $this->serverEventStream?->cancel();
        $this->serverEventStream = null;
        $this->serverEventDecoder = null;
    }

    private function closeHttpSession(): void
    {
        try {
            $response = $this->createHttpRequest('DELETE', null, 5);
            $status = $this->prepareHttpResponse($response, true, null, 5, 'DELETE');
            if (($status < 200 || $status >= 300) && $status !== 405) {
                throw McpConnectionException::transport("MCP session DELETE returned {$status}", $status);
            }
        } catch (\Throwable) {
            // Session shutdown is best-effort and must not hide the caller's result.
        }
    }

    private function markHttpSessionExpired(): void
    {
        $this->httpSessionExpired = true;
        $this->cancelServerEventStream();
    }

    private function acceptSessionId(string $sessionId): void
    {
        if ($sessionId === '' || preg_match('/[^\x21-\x7E]/', $sessionId) === 1) {
            throw McpConnectionException::protocol('MCP server returned an invalid session ID');
        }

        $this->httpSessionId = $sessionId;
    }

    private function waitForRetry(int $milliseconds, float $deadline): void
    {
        $remainingMicroseconds = max(0, (int) (($deadline - microtime(true)) * 1_000_000));
        usleep(min($remainingMicroseconds, max(0, $milliseconds) * 1000));
    }

    private function sendCancellation(int|string $requestId): void
    {
        try {
            $this->sendHttpOneWay([
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => $requestId, 'reason' => 'Client request timed out'],
            ]);
        } catch (\Throwable) {
            // The timeout remains the primary failure.
        }
    }

    private function assertHttpReady(): void
    {
        if ($this->url === null) {
            throw new McpConnectionException('HTTP transport requires a URL');
        }
        if ($this->httpSessionExpired) {
            throw new McpSessionExpiredException;
        }
    }

    /**
     * Retained for compatibility with focused parser tests.
     */
    private function parseSSEResponse(string $body, int $expectedId): mixed
    {
        $decoder = new McpSseDecoder(self::READ_BUFFER_MAX);
        foreach ($decoder->push($body, true) as $event) {
            $result = $this->routeSseEvent($event, $expectedId);
            if ($result['matched']) {
                return $result['result'];
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
