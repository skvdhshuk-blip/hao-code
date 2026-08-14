<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Runtime\ProcessSupervisor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait McpTransportWriteStdioPayloadConcern
{

    private function writeStdioPayload(string $payload, float $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($payload) && microtime(true) < $deadline) {
            $read = [];
            if (is_resource($this->stdout)) {
                $read[] = $this->stdout;
            }
            if (is_resource($this->stderr)) {
                $read[] = $this->stderr;
            }
            $write = is_resource($this->stdin) ? [$this->stdin] : [];
            $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $seconds = (int) floor($remaining);
            $microseconds = (int) max(1, min(999_999, ($remaining - $seconds) * 1_000_000));
            $changed = @stream_select($read, $write, $except, $seconds, $microseconds);
            $selectFailed = false;
            if ($changed === false) {
                usleep(10_000);
                $read = [];
                $write = [];
                $selectFailed = true;
            }

            if ($selectFailed) {
                // See sendStdio(): direct non-blocking polling is the
                // cross-platform fallback when select cannot watch pipes.
                $this->drainStdoutPipe();
                $this->drainStderrPipe();
                if (is_resource($this->stdin)) {
                    $write = [$this->stdin];
                }
            }

            foreach ($read as $stream) {
                if ($stream === $this->stdout) {
                    // A server may keep writing notifications while it waits
                    // for this reverse-RPC response. Drain stdout as well as
                    // stderr or the server can block on its output pipe while
                    // this side is blocked writing stdin.
                    $this->drainStdoutPipe();
                } elseif ($stream === $this->stderr) {
                    $this->drainStderrPipe();
                }
            }
            $this->throwIfReadBufferOversized();
            if ($write !== []) {
                $written = @fwrite($this->stdin, substr($payload, $offset));
                if ($written === false) {
                    throw new McpConnectionException('Failed to write to MCP server stdin');
                }
                if ($written > 0) {
                    $offset += $written;
                    @fflush($this->stdin);
                }
            }

            if ($this->stdioProcessRunning() === false) {
                throw McpConnectionException::transport(
                    'MCP server process exited while writing request. Stderr: '.$this->stderrPreview()
                );
            }
        }

        if ($offset < strlen($payload)) {
            throw McpConnectionException::transport('MCP stdio write timed out');
        }
    }

    private function drainStdoutPipe(): void
    {
        if ($this->stdout === null) {
            return;
        }
        while (($chunk = @fread($this->stdout, 65536)) !== false && $chunk !== '') {
            $this->readBuffer .= $chunk;
            if (strlen($this->readBuffer) > self::READ_BUFFER_MAX) {
                break;
            }
        }
    }

    private function drainStderrPipe(): void
    {
        if ($this->stderr === null) {
            return;
        }
        while (($chunk = @fread($this->stderr, 65536)) !== false && $chunk !== '') {
            $this->stderrBuffer .= $chunk;
            if (strlen($this->stderrBuffer) > self::STDERR_BUFFER_MAX) {
                $this->stderrBuffer = substr($this->stderrBuffer, -self::STDERR_BUFFER_MAX);
            }
        }
    }

    /**
     * @return array{matched: bool, result: mixed}
     */
    private function consumeStdioReadBuffer(mixed $expectedId): array
    {
        while (($newlinePos = strpos($this->readBuffer, "\n")) !== false) {
            if ($newlinePos > self::READ_BUFFER_MAX) {
                $this->readBuffer = '';
                throw McpConnectionException::transport(
                    "MCP server sent oversized payload (>{$this->formatBytes(self::READ_BUFFER_MAX)})"
                );
            }

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

            if (! isset($response['id']) && isset($response['method'])) {
                $this->dispatchNotification($response);

                continue;
            }

            if (isset($response['id'], $response['method'])) {
                $this->handleInboundRequest($response);
                if ($response['id'] !== $expectedId) {
                    continue;
                }
            }

            if (! isset($response['id']) || $response['id'] !== $expectedId) {
                continue;
            }

            if (isset($response['error'])) {
                throw new McpConnectionException(
                    'MCP error: '.($response['error']['message'] ?? 'Unknown error'),
                    (int) ($response['error']['code'] ?? 0),
                );
            }

            return ['matched' => true, 'result' => $response['result'] ?? null];
        }

        return ['matched' => false, 'result' => null];
    }

    private function throwIfReadBufferOversized(): void
    {
        if (strlen($this->readBuffer) <= self::READ_BUFFER_MAX) {
            return;
        }

        $this->readBuffer = '';
        throw McpConnectionException::transport(
            "MCP server sent oversized payload (>{$this->formatBytes(self::READ_BUFFER_MAX)}) without newline delimiter"
        );
    }

    private function stdioProcessRunning(): ?bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = @proc_get_status($this->process);
        if (! is_array($status)) {
            return null;
        }

        return (bool) ($status['running'] ?? false);
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

    /** @param array<string, mixed> $message */
    private function sendHttp(array $message, float $timeoutSeconds): mixed
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

        $body = $this->readHttpBody($response, max(0.001, $deadline - microtime(true)));

        return $this->decodeJsonRpcResponse($body, $message['id']);
    }

    /** @param array<string, mixed> $message */
    private function sendHttpNotification(array $message, float $timeoutSeconds): void
    {
        $this->sendHttpOneWay($message, $timeoutSeconds);
    }

    /** @param array<string, mixed> $message */
    private function sendHttpOneWay(array $message, float $timeoutSeconds = 5): void
    {
        $this->assertHttpReady();
        $response = $this->createHttpRequest('POST', $message, $timeoutSeconds);
        $status = $this->prepareHttpResponse($response, true, $message, $timeoutSeconds);

        if ($status === 202 || $status === 204) {
            return;
        }
        if ($status >= 200 && $status < 300
            && $this->readHttpBody($response, max(0.001, $timeoutSeconds)) === '') {
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
        float $timeoutSeconds,
        ?string $lastEventId = null,
        ?float $maxDurationSeconds = null,
    ): ResponseInterface {
        $options = [
            'headers' => $this->buildHttpHeaders($message !== null),
            'timeout' => max(0.001, $timeoutSeconds),
            'max_duration' => max(0.001, $maxDurationSeconds ?? $timeoutSeconds),
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
        float $timeoutSeconds,
        string $method = 'POST',
        ?string $lastEventId = null,
        ?float $maxDurationSeconds = null,
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
            return substr($this->readHttpBody($response, 1.0), 0, 500);
        } catch (\Throwable) {
            return '';
        }
    }
}
