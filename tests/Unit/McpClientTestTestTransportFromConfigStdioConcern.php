<?php

namespace Tests\Unit;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;

trait McpClientTestTestTransportFromConfigStdioConcern
{

    public function test_transport_from_config_stdio(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => 'echo',
            'args' => ['hello'],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->assertSame('stdio', $transport->getTransportType());
    }

    public function test_transport_from_config_http(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => ['Authorization' => 'Bearer test'],
        ]);

        $this->assertSame('http', $transport->getTransportType());
    }

    public function test_sse_parser_accepts_data_without_space(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => [],
        ]);
        $parse = new \ReflectionMethod($transport, 'parseSSEResponse');

        $result = $parse->invoke(
            $transport,
            "data:{\"jsonrpc\":\"2.0\",\"id\":7,\"result\":{\"ok\":true}}\n\n",
            7,
        );

        $this->assertSame(['ok' => true], $result);
    }

    public function test_stdio_connect_fails_without_command(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => null,
            'args' => [],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('command');
        $transport->connect();
    }

    public function test_reconnecting_stdio_closes_the_previous_process(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX process probe is unavailable.');
        }

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__).'/fixtures/mcp-jsonl-probe-server.php'],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);
        $processProperty = new \ReflectionProperty($transport, 'process');
        $firstPid = null;

        try {
            $transport->connect();
            $firstStatus = proc_get_status($processProperty->getValue($transport));
            $firstPid = (int) ($firstStatus['pid'] ?? 0);

            $transport->connect();
            $secondStatus = proc_get_status($processProperty->getValue($transport));
            $secondPid = (int) ($secondStatus['pid'] ?? 0);

            $this->assertGreaterThan(0, $firstPid);
            $this->assertGreaterThan(0, $secondPid);
            $this->assertNotSame($firstPid, $secondPid);

            $exited = false;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                if (! @posix_kill($firstPid, 0)) {
                    $exited = true;
                    break;
                }
                usleep(25_000);
            }
            $this->assertTrue($exited, 'The previous MCP stdio process is still running.');
        } finally {
            $transport->close();
        }
    }

    public function test_closing_stdio_kills_descendants_before_stdin_eof_can_orphan_them(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX process-tree probe is unavailable.');
        }

        $server = tempnam(sys_get_temp_dir(), 'mcp-descendant-server-');
        $pidFile = tempnam(sys_get_temp_dir(), 'mcp-descendant-pid-');
        $this->assertNotFalse($server);
        $this->assertNotFalse($pidFile);
        @unlink($pidFile);

        file_put_contents($server, "<?php\n"
            ."\$childPid = pcntl_fork();\n"
            ."if (\$childPid === 0) { sleep(10); exit(0); }\n"
            .'file_put_contents('.var_export($pidFile, true).', (string) $childPid);'."\n"
            .'fgets(STDIN);'."\n");

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $childPid = 0;
        try {
            $transport->connect();
            for ($attempt = 0; $attempt < 40 && ! is_file($pidFile); $attempt++) {
                usleep(25_000);
            }
            $childPid = (int) trim((string) @file_get_contents($pidFile));
            $this->assertGreaterThan(0, $childPid);
            $this->assertTrue(@posix_kill($childPid, 0));

            $transport->close();

            $exited = false;
            for ($attempt = 0; $attempt < 40; $attempt++) {
                if (! @posix_kill($childPid, 0)) {
                    $exited = true;
                    break;
                }
                usleep(25_000);
            }
            $this->assertTrue($exited, 'MCP stdio descendant survived transport close.');
        } finally {
            $transport->close();
            if ($childPid > 0 && @posix_kill($childPid, 0)) {
                @posix_kill($childPid, defined('SIGKILL') ? SIGKILL : 9);
            }
            @unlink($server);
            @unlink($pidFile);
        }
    }

    public function test_stdio_request_drains_large_stderr_while_waiting_for_stdout(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('stdio pipe pressure test is POSIX-focused.');
        }

        $server = tempnam(sys_get_temp_dir(), 'mcp-stderr-server-');
        $this->assertNotFalse($server);
        file_put_contents($server, <<<'PHP'
<?php
fwrite(STDERR, str_repeat('e', 2 * 1024 * 1024));
$line = fgets(STDIN);
$request = json_decode($line ?: '', true);
fwrite(STDOUT, json_encode([
    'jsonrpc' => '2.0',
    'id' => $request['id'] ?? null,
    'result' => ['ok' => true],
])."\n");
PHP);

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        try {
            $transport->connect();
            $result = $transport->request('ping', [], 3);

            $this->assertSame(['ok' => true], $result);
        } finally {
            $transport->close();
            @unlink($server);
        }
    }

    public function test_stdio_request_completes_after_partial_large_stdin_writes(): void
    {
        $server = tempnam(sys_get_temp_dir(), 'mcp-partial-write-server-');
        $this->assertNotFalse($server);
        file_put_contents($server, <<<'PHP'
<?php
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['id'])) {
        continue;
    }
    $blob = $request['params']['blob'] ?? '';
    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'id' => $request['id'],
        'result' => ['bytes' => is_string($blob) ? strlen($blob) : 0],
    ])."\n");
    fflush(STDOUT);
}
PHP);

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        try {
            $transport->connect();
            $size = 2 * 1024 * 1024;
            $result = $transport->request('large-request', ['blob' => str_repeat('x', $size)], 5);

            $this->assertSame(['bytes' => $size], $result);
        } finally {
            $transport->close();
            @unlink($server);
        }
    }

    public function test_stdio_reverse_request_write_drains_stdout_pressure(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('stdio pipe pressure test is POSIX-focused.');
        }

        $server = tempnam(sys_get_temp_dir(), 'mcp-reverse-stdout-server-');
        $this->assertNotFalse($server);
        file_put_contents($server, <<<'PHP'
<?php
$line = fgets(STDIN);
$request = json_decode($line ?: '', true);
$id = $request['id'] ?? null;

fwrite(STDOUT, json_encode([
    'jsonrpc' => '2.0',
    'id' => 99,
    'method' => 'roots/list',
    'params' => [],
])."\n");
fflush(STDOUT);

// Keep stdout busy while waiting for the client's reverse-RPC response.
// Without concurrent stdout draining on the client this blocks before stdin
// can accept the large response payload.
fwrite(STDOUT, str_repeat('n', 2 * 1024 * 1024)."\n");
fflush(STDOUT);

$reverseResponse = fgets(STDIN);
if ($reverseResponse === false) {
    exit(2);
}

fwrite(STDOUT, json_encode([
    'jsonrpc' => '2.0',
    'id' => $id,
    'result' => ['ok' => true],
])."\n");
fflush(STDOUT);
PHP);

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);
        $transport->onRequest('roots/list', static fn (): array => [
            'roots' => str_repeat('r', 2 * 1024 * 1024),
        ]);

        try {
            $transport->connect();
            $this->assertSame(['ok' => true], $transport->request('ping', [], 5));
        } finally {
            $transport->close();
            @unlink($server);
        }
    }

    public function test_stdio_server_rejects_an_oversized_response_frame(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__).'/fixtures/mcp-large-response-server.php'],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        try {
            $transport->connect(3);
            $this->expectException(McpConnectionException::class);
            $this->expectExceptionMessage('MCP error');
            $transport->request('tools/list', [], 3);
        } finally {
            $transport->close();
        }
    }

    public function test_stdio_server_rejects_an_oversized_newline_terminated_frame(): void
    {
        $server = tempnam(sys_get_temp_dir(), 'mcp-oversized-frame-');
        $this->assertNotFalse($server);
        file_put_contents(
            $server,
            "<?php\nfgets(STDIN);\necho str_repeat('x', 4 * 1024 * 1024 + 1), \"\\n\";\n",
        );

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        try {
            $this->expectExceptionMessage('oversized payload');
            $transport->connect(3);
            $transport->request('initialize', [], 3);
        } finally {
            $transport->close();
            @unlink($server);
        }
    }

    public function test_stdio_request_reports_server_exit_before_write_timeout(): void
    {
        $server = tempnam(sys_get_temp_dir(), 'mcp-exit-server-');
        $this->assertNotFalse($server);
        file_put_contents($server, "<?php\nexit(3);\n");

        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$server],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        try {
            $transport->connect();
            $start = microtime(true);
            try {
                $transport->request('ping', [], 3);
                $this->fail('Expected an MCP process-exited error.');
            } catch (McpConnectionException $e) {
                $this->assertStringContainsString('process exited before response', $e->getMessage());
                $this->assertLessThan(1.0, microtime(true) - $start);
            }
        } finally {
            $transport->close();
            @unlink($server);
        }
    }

    public function test_unsupported_transport_throws(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'websocket',
            'command' => null,
            'args' => [],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Unsupported transport');
        $transport->connect();
    }

    public function test_build_tool_name(): void
    {
        $this->assertSame(
            'mcp__my_server__my_tool',
            McpConnectionManager::buildToolName('my-server', 'my-tool')
        );
    }

    public function test_parse_tool_name(): void
    {
        $parsed = McpConnectionManager::parseToolName('mcp__github__create_issue');
        $this->assertNotNull($parsed);
        $this->assertSame('github', $parsed['serverName']);
        $this->assertSame('create_issue', $parsed['toolName']);
    }

    public function test_parse_tool_name_returns_null_for_non_mcp(): void
    {
        $this->assertNull(McpConnectionManager::parseToolName('Bash'));
        $this->assertNull(McpConnectionManager::parseToolName('mcp__only_one_part'));
    }

    public function test_connection_manager_starts_empty(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->getConnectedClients());
        $this->assertEmpty($manager->getFailures());
    }

    public function test_get_client_returns_null_for_unknown(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertNull($manager->getClient('nonexistent'));
    }

    public function test_connect_by_name_throws_for_missing_server(): void
    {
        $configManager = new class extends McpServerConfigManager {
            public function paths(): array { return ['global' => '/tmp/g.json', 'project' => '/tmp/p.json']; }
            public function listServers(): array { return []; }
            public function getServer(string $name): ?array { return null; }
        };

        $manager = new McpConnectionManager($configManager);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('not found');
        $manager->connectByName('nonexistent');
    }
}
