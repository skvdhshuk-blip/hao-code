<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Runtime\ProcessSupervisor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait McpTransportReadHttpBodyConcern
{

    private function readHttpBody(ResponseInterface $response, float $timeoutSeconds): string
    {
        try {
            $headers = $response->getHeaders(false);
            $contentLength = $headers['content-length'][0] ?? null;
            if (is_string($contentLength) && ctype_digit($contentLength)
                && (int) $contentLength > self::HTTP_RESPONSE_MAX) {
                $response->cancel();

                throw McpConnectionException::protocol(
                    'MCP HTTP response exceeded '.self::HTTP_RESPONSE_MAX.' bytes.',
                );
            }

            $body = '';
            foreach ($this->httpClient->stream($response, max(0.001, $timeoutSeconds)) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw McpConnectionException::transport('MCP HTTP response timed out');
                }
                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $content = $chunk->getContent();
                if ($content === '') {
                    continue;
                }
                if (strlen($body) + strlen($content) > self::HTTP_RESPONSE_MAX) {
                    $response->cancel();

                    throw McpConnectionException::protocol(
                        'MCP HTTP response exceeded '.self::HTTP_RESPONSE_MAX.' bytes.',
                    );
                }
                $body .= $content;
            }

            return $body;
        } catch (McpConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw McpConnectionException::transport('Failed to read MCP HTTP response: '.$exception->getMessage());
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

    private function openServerEventStream(float $timeoutSeconds = self::SERVER_STREAM_TIMEOUT_SECONDS): void
    {
        if ($this->url === null || $this->serverEventStream !== null || ! $this->serverEventStreamSupported) {
            return;
        }

        try {
            $response = $this->createHttpRequest(
                'GET',
                null,
                $timeoutSeconds,
                $this->serverLastEventId,
                self::SERVER_STREAM_MAX_DURATION_SECONDS,
            );
            $status = $this->prepareHttpResponse(
                $response,
                true,
                null,
                $timeoutSeconds,
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

    /**
     * @return string[]
     */
    private function stderrLines(string $buffer): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $buffer) ?: [] as $line) {
            $trimmed = rtrim($line);
            if ($trimmed !== '') {
                $lines[] = $this->redactMessage($trimmed);
            }
        }

        return $lines;
    }

    private function stderrPreview(): string
    {
        $this->drainStderrPipe();
        $lines = $this->stderrLines($this->stderrBuffer);

        return $lines === [] ? '(no stderr)' : substr(implode("\n", $lines), -1000);
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
