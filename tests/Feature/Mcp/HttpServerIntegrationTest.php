<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * Black-box integration tests for the MCP HTTP transport.
 * Each test spawns a fresh server process and communicates over HTTP.
 */
class HttpServerIntegrationTest extends TestCase
{
    private string $binPath;

    private string $tmpRoot;

    private string $token = 'test-secret-token-abc123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->binPath = realpath(__DIR__.'/../../../bin/hao-code') ?: '';
        $this->tmpRoot = sys_get_temp_dir().'/mcp-http-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0755, true);

        file_put_contents($this->tmpRoot.'/hello.txt', 'hello world');
        file_put_contents($this->tmpRoot.'/.env', 'SECRET=do-not-expose');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    /** HTTP mode requires --token; missing token → server refuses to start */
    public function test_missing_token_fails_fast(): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$this->binPath, 'mcp-serve', '--mode=http', '--root='.$this->tmpRoot],
            $descriptors,
            $pipes,
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertNotEquals(0, $exit, 'Missing --token must cause non-zero exit');
        $this->assertStringContainsString('token', strtolower($stderr));
    }

    /** initialize request with valid Bearer token succeeds */
    public function test_initialize_with_valid_token(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $resp = $this->httpPost($port, $this->initMsg(), $this->token);
            $this->assertArrayHasKey('result', $resp);
            $this->assertArrayHasKey('serverInfo', $resp['result']);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Request without Authorization header → 401 */
    public function test_missing_auth_returns_401(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $raw = $this->rawHttpPost($port, json_encode($this->initMsg()), '');
            $this->assertStringContainsString('401', $raw);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Request with wrong token → 401 */
    public function test_wrong_token_returns_401(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $raw = $this->rawHttpPost($port, json_encode($this->initMsg()), 'Bearer wrong-token');
            $this->assertStringContainsString('401', $raw);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Valid tools/list request */
    public function test_tools_list(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $this->httpPost($port, $this->initMsg(), $this->token);
            $resp = $this->httpPost($port, $this->rpcMsg('tools/list'), $this->token);
            $this->assertArrayHasKey('result', $resp);
            $names = array_column($resp['result']['tools'], 'name');
            $this->assertContains('Read', $names);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Read .env must be blocked (blacklist) */
    public function test_blacklist_rejects_env(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $this->httpPost($port, $this->initMsg(), $this->token);
            $resp = $this->httpPost($port, $this->rpcMsg('tools/call', [
                'name' => 'Read',
                'arguments' => ['file_path' => $this->tmpRoot.'/.env'],
            ]), $this->token);
            $result = $resp['result'] ?? [];
            $this->assertTrue($result['isError'] ?? false, '.env must be rejected');
            $this->assertStringNotContainsString('SECRET', $result['content'][0]['text'] ?? '');
        } finally {
            $this->stopServer($server);
        }
    }

    /** Per-IP rate limit: 60+1 requests → 429 on 61st */
    public function test_rate_limit_returns_429(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $hit429 = false;
            for ($i = 0; $i < 65; $i++) {
                $raw = $this->rawHttpPost($port, json_encode($this->rpcMsg('tools/list')), 'Bearer '.$this->token);
                if (str_contains($raw, '429')) {
                    $hit429 = true;
                    break;
                }
            }
            $this->assertTrue($hit429, 'Rate limit must return 429 after 60 requests/min');
        } finally {
            $this->stopServer($server);
        }
    }

    /** Default bind address is 127.0.0.1 (test that server actually listens) */
    public function test_default_bind_localhost(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
            $this->assertNotFalse($conn, 'Server must listen on 127.0.0.1');
            if ($conn) {
                fclose($conn);
            }
        } finally {
            $this->stopServer($server);
        }
    }

    /** notifications/initialized (no id) → 204 No Content */
    public function test_notification_returns_204(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $this->httpPost($port, $this->initMsg(), $this->token);
            $notification = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []];
            $raw = $this->rawHttpPost($port, json_encode($notification), 'Bearer '.$this->token);
            $this->assertStringContainsString('204', $raw);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Unknown method returns JSON-RPC error -32601 */
    public function test_unknown_method_returns_error(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $resp = $this->httpPost($port, $this->rpcMsg('unknown/method'), $this->token);
            $this->assertArrayHasKey('error', $resp);
            $this->assertEquals(-32601, $resp['error']['code']);
        } finally {
            $this->stopServer($server);
        }
    }

    /** Invalid JSON body returns JSON-RPC parse error -32700 */
    public function test_invalid_json_returns_parse_error(): void
    {
        [$server, $port] = $this->startHttpServer();

        try {
            $raw = $this->rawHttpPost($port, 'not-json{{{', 'Bearer '.$this->token);
            $this->assertStringContainsString('200', $raw);
            // Extract JSON body from response
            $bodyStart = strpos($raw, "\r\n\r\n");
            $body = $bodyStart !== false ? substr($raw, $bodyStart + 4) : '';
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('error', $decoded);
            $this->assertEquals(-32700, $decoded['error']['code']);
        } finally {
            $this->stopServer($server);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array{0: array{process: resource, pipes: array}, 1: int} */
    private function startHttpServer(): array
    {
        $port = $this->findFreePort();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [
                $this->binPath, 'mcp-serve',
                '--mode=http',
                '--root='.$this->tmpRoot,
                '--preset=strict',
                '--port='.$port,
                '--token='.$this->token,
            ],
            $descriptors,
            $pipes,
        );
        $this->assertIsResource($process, 'HTTP server process must start');

        // Wait for server to start listening
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $e, $s, 0.1);
            if ($conn !== false) {
                fclose($conn);
                break;
            }
            usleep(50000);
        }

        return [['process' => $process, 'pipes' => $pipes], $port];
    }

    private function stopServer(array $server): void
    {
        fclose($server['pipes'][0]);
        fclose($server['pipes'][1]);
        fclose($server['pipes'][2]);
        proc_terminate($server['process']);
        proc_close($server['process']);
    }

    private function httpPost(int $port, array $msg, string $token): array
    {
        $raw = $this->rawHttpPost($port, json_encode($msg), 'Bearer '.$token);
        $bodyStart = strpos($raw, "\r\n\r\n");
        $body = $bodyStart !== false ? substr($raw, $bodyStart + 4) : '';
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function rawHttpPost(int $port, string $body, string $authHeader): string
    {
        $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        if ($conn === false) {
            return '';
        }

        $len = strlen($body);
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: 127.0.0.1:{$port}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: {$len}\r\n";
        if ($authHeader !== '') {
            $request .= "Authorization: {$authHeader}\r\n";
        }
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $body;

        fwrite($conn, $request);

        $response = '';
        $deadline = microtime(true) + 10;
        while (! feof($conn) && microtime(true) < $deadline) {
            $chunk = fread($conn, 4096);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
            } else {
                usleep(5000);
            }
        }

        fclose($conn);

        return $response;
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return 15173;
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $parts = explode(':', $name);

        return (int) end($parts);
    }

    private function initMsg(): array
    {
        return $this->rpcMsg('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0.0.1'],
        ]);
    }

    private function rpcMsg(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => rand(1, 9999),
            'method' => $method,
            'params' => $params,
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir.'/'.$file;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
