<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Runtime\ProcessSupervisor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait McpTransportConstructConcern
{

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
    public function connect(float $timeoutSeconds = 30): void
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
    public function request(string $method, array $params = [], float $timeoutSeconds = 60): mixed
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
    public function notify(string $method, array $params = [], float $timeoutSeconds = 5): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ];

        match ($this->transport) {
            'stdio' => $this->writeStdio($message, $timeoutSeconds),
            'http', 'sse' => $this->sendHttpNotification($message, $timeoutSeconds),
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
            $buffer = $this->stderrBuffer;
            $this->stderrBuffer = '';

            return $this->stderrLines($buffer);
        }

        $this->drainStderrPipe();
        $buffer = $this->stderrBuffer;
        $this->stderrBuffer = '';

        return $this->stderrLines($buffer);
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

        // Terminate the server tree before closing its pipes. Closing stdin
        // can make a wrapper exit immediately; if that happens first, a
        // descendant that outlives the wrapper would no longer be discoverable
        // through the parent/child relationship.
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            $pid = (int) ($status['pid'] ?? 0);
            if ($pid > 0) {
                ProcessSupervisor::terminateTree($pid, false);
            }
        }

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
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
        $this->readBuffer = '';
        $this->stderrBuffer = '';
        $this->httpSessionId = null;
        $this->protocolVersion = null;
        $this->httpSessionExpired = false;
    }

    public function isConnected(): bool
    {
        if ($this->transport === 'stdio') {
            return $this->stdioProcessRunning() === true;
        }

        return $this->url !== null && ! $this->httpSessionExpired;
    }

    /**
     * Start the optional server-initiated GET event stream.
     */
    public function startServerEventStream(float $timeoutSeconds = self::SERVER_STREAM_TIMEOUT_SECONDS): void
    {
        if (($this->transport !== 'http' && $this->transport !== 'sse') || ! $this->serverEventStreamSupported) {
            return;
        }

        $this->openServerEventStream($timeoutSeconds);
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
                $this->openServerEventStream(max(0.001, $timeoutSeconds));
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

    private function connectStdio(): void
    {
        if ($this->command === null) {
            throw new McpConnectionException('Stdio transport requires a command');
        }

        // A public transport can be connected more than once.  Close the
        // previous process before replacing its handles; otherwise the old
        // child keeps running after $this->process is overwritten and cannot
        // be terminated by a later close().
        if ($this->process !== null || $this->stdin !== null || $this->stdout !== null || $this->stderr !== null) {
            $this->close();
        }

        // Array form bypasses shell interpolation and keeps command/arguments distinct.
        $cmd = array_merge([$this->command], $this->args);
        $env = $this->buildStdioEnvironment();

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr (captured)
        ];

        $process = @proc_open($cmd, $descriptorSpec, $pipes, $this->workingDirectory, $env);

        if (! is_resource($process)) {
            // Omit $cmd to avoid leaking local filesystem paths in exception messages
            throw new McpConnectionException('Failed to start MCP server process');
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set stdout/stderr to non-blocking for timeout support
        stream_set_blocking($this->stdin, false);
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    private function writeStdio(array $message, float $timeoutSeconds = 5): void
    {
        if ($this->stdin === null) {
            throw new McpConnectionException('Stdio transport not connected');
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new McpConnectionException('Failed to encode MCP stdio message');
        }

        $this->writeStdioPayload($json."\n", microtime(true) + max(0.001, $timeoutSeconds));
    }

    private function sendStdio(array $message, float $timeoutSeconds): mixed
    {
        if ($this->stdin === null || $this->stdout === null) {
            throw new McpConnectionException('Stdio transport not connected');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $expectedId = $message['id'];
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new McpConnectionException('Failed to encode MCP stdio message');
        }
        $payload = $json."\n";
        $writeOffset = 0;

        while (microtime(true) < $deadline) {
            $read = [];
            if (is_resource($this->stdout)) {
                $read[] = $this->stdout;
            }
            if (is_resource($this->stderr)) {
                $read[] = $this->stderr;
            }
            $write = [];
            if ($writeOffset < strlen($payload) && is_resource($this->stdin)) {
                $write[] = $this->stdin;
            }
            $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $seconds = (int) floor($remaining);
            $microseconds = (int) max(1, min(999_999, ($remaining - $seconds) * 1_000_000));
            $selectFailed = false;

            if ($read === [] && $write === []) {
                usleep(10_000);
            } else {
                $changed = @stream_select($read, $write, $except, $seconds, $microseconds);
                if ($changed === false) {
                    usleep(10_000);
                    $read = [];
                    $write = [];
                    $selectFailed = true;
                }
            }

            if ($selectFailed) {
                // stream_select() is not implemented for every kind of
                // proc_open pipe on native Windows. The streams are already
                // non-blocking, so poll them directly instead of turning a
                // usable MCP server into a timeout-only failure mode.
                $this->drainStdoutPipe();
                $this->drainStderrPipe();
                if ($writeOffset < strlen($payload) && is_resource($this->stdin)) {
                    $write = [$this->stdin];
                }
            }

            if ($write !== [] && $writeOffset < strlen($payload)) {
                $written = @fwrite($this->stdin, substr($payload, $writeOffset));
                if ($written === false) {
                    throw new McpConnectionException('Failed to write to MCP server stdin');
                }
                if ($written > 0) {
                    $writeOffset += $written;
                    @fflush($this->stdin);
                }
            }

            foreach ($read as $stream) {
                if ($stream === $this->stderr) {
                    $this->drainStderrPipe();
                    continue;
                }
                if ($stream === $this->stdout) {
                    $this->drainStdoutPipe();
                }
            }

            $result = $this->consumeStdioReadBuffer($expectedId);
            if ($result['matched']) {
                return $result['result'];
            }

            $this->throwIfReadBufferOversized();

            if ($this->stdioProcessRunning() === false) {
                $this->drainStdoutPipe();
                $this->drainStderrPipe();
                $result = $this->consumeStdioReadBuffer($expectedId);
                if ($result['matched']) {
                    return $result['result'];
                }

                throw McpConnectionException::transport(
                    'MCP server process exited before response. Stderr: '.$this->stderrPreview()
                );
            }
        }

        throw new McpConnectionException("MCP request timed out after {$timeoutSeconds}s: {$message['method']}");
    }
}
