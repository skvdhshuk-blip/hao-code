<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp\Server;

use HaoCode\Services\Telemetry\PhoenixTracer;

/**
 * HTTP transport for MCP server using stream_socket_server (zero dependencies).
 *
 * Security red lines:
 * - --token required (fail-fast if absent)
 * - Default bind 127.0.0.1; warn if 0.0.0.0
 * - Bearer token checked per request
 * - Per-IP 60 req/min rate limit (429)
 * - OTEL attrs: method/status/duration_ms/caller_ip/result_kind (no token/body)
 */
class HttpTransport
{
    private const MAX_REQUEST_BYTES = 1048576; // 1 MiB

    private const RATE_LIMIT_PER_MIN = 60;

    /** @var array<string, array{count: int, window_start: float}> */
    private array $rateLimitTable = [];

    public function __construct(
        private readonly McpServer $server,
        private readonly string $bind,
        private readonly int $port,
        private readonly string $token,
        private readonly ?PhoenixTracer $tracer = null,
    ) {
        if ($token === '') {
            fwrite(STDERR, "FATAL: --token is required for HTTP transport\n");
            exit(1);
        }

        if ($this->bind === '0.0.0.0') {
            fwrite(STDERR, "WARNING: Binding to 0.0.0.0 exposes MCP server to all interfaces\n");
        }
    }

    public function run(): void
    {
        $address = "tcp://{$this->bind}:{$this->port}";
        $socket = @stream_socket_server($address, $errno, $errstr);

        if ($socket === false) {
            fwrite(STDERR, "MCP HTTP transport failed to bind {$address}: {$errstr} ({$errno})\n");
            exit(1);
        }

        fwrite(STDERR, "MCP HTTP transport listening on {$address}\n");

        while (true) {
            $conn = @stream_socket_accept($socket, 30);
            if ($conn === false) {
                continue;
            }

            $callerIp = $this->extractPeerIp($conn);
            $this->handleConnection($conn, $callerIp);
            fclose($conn);
        }

        fclose($socket);
    }

    private function handleConnection($conn, string $callerIp): void
    {
        $startTime = microtime(true);
        $status = 200;
        $resultKind = 'ok';

        try {
            // Rate limit check
            if (! $this->checkRateLimit($callerIp)) {
                $this->sendHttpResponse($conn, 429, 'application/json', json_encode([
                    'error' => 'Too Many Requests',
                ]));
                $status = 429;
                $resultKind = 'rate_limited';

                return;
            }

            $rawRequest = $this->readHttpRequest($conn);
            if ($rawRequest === null) {
                $this->sendHttpResponse($conn, 400, 'application/json', json_encode([
                    'error' => 'Bad Request',
                ]));
                $status = 400;
                $resultKind = 'bad_request';

                return;
            }

            [$method, $path, $headers, $body] = $rawRequest;

            // Only POST / is allowed
            if ($method !== 'POST' || $path !== '/') {
                $this->sendHttpResponse($conn, 404, 'application/json', json_encode([
                    'error' => 'Not Found',
                ]));
                $status = 404;
                $resultKind = 'not_found';

                return;
            }

            // Bearer token auth
            $authHeader = $headers['authorization'] ?? $headers['Authorization'] ?? '';
            if (! $this->validateBearer($authHeader)) {
                $this->sendHttpResponse($conn, 401, 'application/json', json_encode([
                    'error' => 'Unauthorized',
                ]));
                $status = 401;
                $resultKind = 'auth_failed';

                return;
            }

            if ($body === '') {
                $this->sendHttpResponse($conn, 400, 'application/json', json_encode([
                    'error' => 'Empty body',
                ]));
                $status = 400;
                $resultKind = 'empty_body';

                return;
            }

            $response = $this->server->handleMessage($body);

            if ($response === null) {
                // Notification — respond 204
                $this->sendHttpResponse($conn, 204, 'application/json', '');
                $status = 204;
            } else {
                $this->sendHttpResponse($conn, 200, 'application/json', $response);
                $status = 200;
            }

        } finally {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->recordSpan($callerIp, $status, $durationMs, $resultKind);
        }
    }

    /** @return array{0:string,1:string,2:array<string,string>,3:string}|null */
    private function readHttpRequest($conn): ?array
    {
        $raw = '';
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $chunk = @fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                if (feof($conn)) {
                    break;
                }
                usleep(1000);

                continue;
            }
            $raw .= $chunk;

            // Check if we have full headers
            $headerEnd = strpos($raw, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headerSection = substr($raw, 0, $headerEnd);
                $bodyStart = $headerEnd + 4;

                $lines = explode("\r\n", $headerSection);
                $requestLine = array_shift($lines);
                $parts = explode(' ', $requestLine, 3);
                if (count($parts) < 2) {
                    return null;
                }
                [$httpMethod, $path] = $parts;

                $headers = [];
                foreach ($lines as $line) {
                    $colonPos = strpos($line, ':');
                    if ($colonPos !== false) {
                        $key = strtolower(trim(substr($line, 0, $colonPos)));
                        $headers[$key] = trim(substr($line, $colonPos + 1));
                    }
                }

                $contentLength = (int) ($headers['content-length'] ?? 0);
                if ($contentLength > self::MAX_REQUEST_BYTES) {
                    return null;
                }

                $bodyReceived = substr($raw, $bodyStart);

                // Read remaining body if needed
                while (strlen($bodyReceived) < $contentLength && microtime(true) < $deadline) {
                    $chunk = @fread($conn, min(4096, $contentLength - strlen($bodyReceived)));
                    if ($chunk === false || $chunk === '') {
                        if (feof($conn)) {
                            break;
                        }
                        usleep(1000);

                        continue;
                    }
                    $bodyReceived .= $chunk;
                }

                $body = substr($bodyReceived, 0, $contentLength);

                return [$httpMethod, $path, $headers, $body];
            }

            if (strlen($raw) > self::MAX_REQUEST_BYTES) {
                return null;
            }
        }

        return null;
    }

    private function sendHttpResponse($conn, int $statusCode, string $contentType, string $body): void
    {
        $statusText = match ($statusCode) {
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            default => 'Internal Server Error',
        };

        $bodyLen = strlen($body);
        $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n";
        $response .= "Content-Type: {$contentType}\r\n";
        $response .= "Content-Length: {$bodyLen}\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n";
        if ($bodyLen > 0) {
            $response .= $body;
        }

        fwrite($conn, $response);
    }

    private function validateBearer(string $authHeader): bool
    {
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }
        $provided = substr($authHeader, 7);

        return hash_equals($this->token, $provided);
    }

    private function checkRateLimit(string $ip): bool
    {
        $now = microtime(true);
        $windowDuration = 60.0;

        if (! isset($this->rateLimitTable[$ip])) {
            $this->rateLimitTable[$ip] = ['count' => 1, 'window_start' => $now];

            return true;
        }

        $entry = &$this->rateLimitTable[$ip];

        if (($now - $entry['window_start']) >= $windowDuration) {
            $entry['count'] = 1;
            $entry['window_start'] = $now;

            return true;
        }

        if ($entry['count'] >= self::RATE_LIMIT_PER_MIN) {
            return false;
        }

        $entry['count']++;

        return true;
    }

    private function extractPeerIp($conn): string
    {
        $peer = @stream_socket_get_name($conn, true);
        if ($peer === false) {
            return 'unknown';
        }
        // Format: "ip:port"
        $lastColon = strrpos($peer, ':');

        return $lastColon !== false ? substr($peer, 0, $lastColon) : $peer;
    }

    private function recordSpan(string $callerIp, int $status, int $durationMs, string $resultKind): void
    {
        if ($this->tracer === null) {
            return;
        }

        $span = $this->tracer->startSpan('mcp.http.request', PhoenixTracer::KIND_TOOL, [
            'http.method' => 'POST',
            'http.status_code' => $status,
            'duration_ms' => $durationMs,
            'caller_ip' => $callerIp,
            'result_kind' => $resultKind,
            // NOTE: token and body are intentionally omitted (security red line #3)
        ]);
        $span?->end();
    }
}
