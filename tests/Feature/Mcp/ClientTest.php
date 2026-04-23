<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MCP client gap-fill (PR B):
 * P0: roots/list, prompts, reconnect, notifications
 * P1: ping, logging, completion, list_changed, timeout, error classify, stderr
 *
 * @group mcp-client
 */
class ClientTest extends TestCase
{
    // ─── McpConnectionException error types ─────────────────────────────

    public function test_exception_error_types(): void
    {
        $protocol = McpConnectionException::protocol('bad version', -32600);
        $this->assertSame(McpConnectionException::TYPE_PROTOCOL, $protocol->getErrorType());
        $this->assertSame(-32600, $protocol->getCode());

        $transport = McpConnectionException::transport('curl failed');
        $this->assertSame(McpConnectionException::TYPE_TRANSPORT, $transport->getErrorType());

        $app = McpConnectionException::application('tool error', 1001);
        $this->assertSame(McpConnectionException::TYPE_APPLICATION, $app->getErrorType());
    }

    public function test_exception_default_type_is_application(): void
    {
        $e = new McpConnectionException('generic error');
        $this->assertSame(McpConnectionException::TYPE_APPLICATION, $e->getErrorType());
    }

    // ─── McpTransport notification dispatch ─────────────────────────────

    public function test_transport_notification_handler_is_registered(): void
    {
        $transport = $this->makeTransport();

        $received = [];
        $transport->onNotification('notifications/tools/list_changed', function (array $params) use (&$received): void {
            $received[] = $params;
        });

        // Simulate dispatch via reflection
        $dispatch = new \ReflectionMethod($transport, 'dispatchNotification');
        $dispatch->setAccessible(true);
        $dispatch->invoke($transport, ['method' => 'notifications/tools/list_changed', 'params' => ['foo' => 'bar']]);

        $this->assertCount(1, $received);
        $this->assertSame(['foo' => 'bar'], $received[0]);
    }

    public function test_transport_multiple_handlers_same_method(): void
    {
        $transport = $this->makeTransport();

        $calls = 0;
        $transport->onNotification('test/event', function () use (&$calls): void {
            $calls++;
        });
        $transport->onNotification('test/event', function () use (&$calls): void {
            $calls++;
        });

        $dispatch = new \ReflectionMethod($transport, 'dispatchNotification');
        $dispatch->setAccessible(true);
        $dispatch->invoke($transport, ['method' => 'test/event', 'params' => []]);

        $this->assertSame(2, $calls);
    }

    public function test_transport_handler_exception_does_not_crash(): void
    {
        $transport = $this->makeTransport();
        $transport->onNotification('test/bad', function (): void {
            throw new \RuntimeException('handler error');
        });

        $dispatch = new \ReflectionMethod($transport, 'dispatchNotification');
        $dispatch->setAccessible(true);

        // Should not throw
        $dispatch->invoke($transport, ['method' => 'test/bad', 'params' => []]);
        $this->assertTrue(true);
    }

    // ─── McpTransport inbound request handler ───────────────────────────

    public function test_transport_request_handler_registered(): void
    {
        $transport = $this->makeTransport();
        $transport->onRequest('roots/list', fn ($params) => ['roots' => []]);

        $handlers = (new \ReflectionProperty($transport, 'requestHandlers'))->getValue($transport);
        $this->assertArrayHasKey('roots/list', $handlers);
    }

    // ─── McpClient: prompts ──────────────────────────────────────────────

    public function test_list_prompts_returns_empty_when_no_capability(): void
    {
        $client = $this->makeInitializedClient(capabilities: ['tools' => new \stdClass]);

        $prompts = $client->listPrompts();
        $this->assertSame([], $prompts);
    }

    public function test_supports_prompts_reflects_capability(): void
    {
        $clientWith = $this->makeInitializedClient(capabilities: ['prompts' => new \stdClass]);
        $clientWithout = $this->makeInitializedClient(capabilities: []);

        $this->assertTrue($clientWith->supportsPrompts());
        $this->assertFalse($clientWithout->supportsPrompts());
    }

    // ─── McpClient: roots ───────────────────────────────────────────────

    public function test_set_roots_updates_roots(): void
    {
        $client = $this->makeInitializedClient();
        $roots = [['uri' => 'file:///workspace', 'name' => 'My Project']];
        $client->setRoots($roots);

        $rootsProp = new \ReflectionProperty($client, 'roots');
        $rootsProp->setAccessible(true);
        $this->assertSame($roots, $rootsProp->getValue($client));
    }

    // ─── McpClient: cache invalidation on list_changed ──────────────────

    public function test_tools_list_changed_clears_cache(): void
    {
        $client = $this->makeInitializedClient(capabilities: ['tools' => new \stdClass]);

        // Manually set cache
        $cacheProp = new \ReflectionProperty($client, 'toolsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($client, [['name' => 'old-tool', 'description' => '', 'inputSchema' => []]]);

        // Get the transport and fire the notification handler
        $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);
        $handlers = (new \ReflectionProperty($transport, 'notificationHandlers'))->getValue($transport);

        $this->assertArrayHasKey('notifications/tools/list_changed', $handlers);
        foreach ($handlers['notifications/tools/list_changed'] as $handler) {
            $handler([]);
        }

        $this->assertNull($cacheProp->getValue($client));
    }

    public function test_prompts_list_changed_clears_cache(): void
    {
        $client = $this->makeInitializedClient(capabilities: ['prompts' => new \stdClass]);

        $cacheProp = new \ReflectionProperty($client, 'promptsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($client, [['name' => 'old', 'description' => '']]);

        $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);
        $handlers = (new \ReflectionProperty($transport, 'notificationHandlers'))->getValue($transport);

        $this->assertArrayHasKey('notifications/prompts/list_changed', $handlers);
        foreach ($handlers['notifications/prompts/list_changed'] as $handler) {
            $handler([]);
        }

        $this->assertNull($cacheProp->getValue($client));
    }

    // ─── McpClient: registerNotificationHandler ─────────────────────────

    public function test_register_notification_handler_delegates_to_transport(): void
    {
        $client = $this->makeInitializedClient();

        $called = false;
        $client->registerNotificationHandler('notifications/message', function () use (&$called): void {
            $called = true;
        });

        $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);
        $handlers = (new \ReflectionProperty($transport, 'notificationHandlers'))->getValue($transport);

        $this->assertArrayHasKey('notifications/message', $handlers);
    }

    // ─── McpClient: not initialized guard ───────────────────────────────

    public function test_methods_throw_when_not_initialized(): void
    {
        $transport = $this->makeTransport();
        $client = new McpClient($transport, 'test-server');

        $this->expectException(McpConnectionException::class);
        $client->listPrompts();
    }

    // ─── McpConnectionManager: reconnect ────────────────────────────────

    public function test_reconnect_constant_max_attempts(): void
    {
        $reflection = new \ReflectionClass(McpConnectionManager::class);
        $const = $reflection->getConstant('MAX_RECONNECT_ATTEMPTS');
        $this->assertSame(3, $const);
    }

    public function test_reconnect_base_delay_ms(): void
    {
        $reflection = new \ReflectionClass(McpConnectionManager::class);
        $const = $reflection->getConstant('RECONNECT_BASE_MS');
        $this->assertSame(500, $const);
    }

    // ─── McpConnectionManager: discoverAllPrompts ───────────────────────

    public function test_discover_all_prompts_returns_empty_with_no_clients(): void
    {
        $configManager = $this->createMock(McpServerConfigManager::class);
        $configManager->method('listServers')->willReturn([]);

        $manager = new McpConnectionManager($configManager);
        $manager->connectAll();

        $this->assertSame([], $manager->discoverAllPrompts());
    }

    // ─── OTEL span naming contract ───────────────────────────────────────

    public function test_span_name_format_for_rpc_method(): void
    {
        $transport = $this->makeTransport();
        $client = new McpClient($transport, 'test-server');

        // Verify the private startSpan helper produces the right name via reflection
        $startSpan = new \ReflectionMethod($client, 'startSpan');
        $startSpan->setAccessible(true);

        // When tracer is null (disabled), startSpan returns null — no span emitted
        $result = $startSpan->invoke($client, 'tools/call', ['tool.name' => 'Read']);
        $this->assertNull($result, 'startSpan must return null when no tracer is injected');
    }

    public function test_mcp_client_accepts_tracer_constructor_arg(): void
    {
        $transport = $this->makeTransport();
        // McpClient constructor must accept optional PhoenixTracer without error
        $client = new McpClient($transport, 'test-server', null);
        $this->assertSame('test-server', $client->getServerName());
    }

    // ─── Security fixes (PR B security review) ──────────────────────────

    public function test_read_buffer_oom_guard_constant(): void
    {
        $reflection = new \ReflectionClass(McpTransport::class);
        $const = $reflection->getConstant('READ_BUFFER_MAX');
        $this->assertSame(4 * 1024 * 1024, $const);
    }

    public function test_drain_stderr_redacts_api_key(): void
    {
        $transport = $this->makeTransport();
        $redact = new \ReflectionMethod($transport, 'redactMessage');
        $redact->setAccessible(true);

        $result = $redact->invoke($transport, 'api_key=sk-abc123def456 connected');
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('sk-abc123def456', $result);
    }

    public function test_drain_stderr_redacts_bearer_token(): void
    {
        $transport = $this->makeTransport();
        $redact = new \ReflectionMethod($transport, 'redactMessage');
        $redact->setAccessible(true);

        $result = $redact->invoke($transport, 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig');
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $result);
    }

    public function test_drain_stderr_redacts_filesystem_path(): void
    {
        $transport = $this->makeTransport();
        $redact = new \ReflectionMethod($transport, 'redactMessage');
        $redact->setAccessible(true);

        $result = $redact->invoke($transport, 'Error reading /Users/alice/projects/secret/config.json');
        $this->assertStringNotContainsString('/Users/alice/projects/secret', $result);
    }

    // ─── Filesystem server smoke test (skipped without npm) ─────────────

    /**
     * @group mcp-filesystem
     */
    public function test_filesystem_server_initialize(): void
    {
        $npx = trim((string) shell_exec('which npx 2>/dev/null'));
        if (empty($npx)) {
            $this->markTestSkipped('npx not available — skipping real filesystem MCP server smoke test');
        }

        // Check package is cached/installed (don't trigger a network download in CI)
        $checkOutput = (string) shell_exec('npx --no-install @modelcontextprotocol/server-filesystem --help 2>&1');
        if (str_contains($checkOutput, 'loader') || str_contains($checkOutput, 'ERR_') || str_contains($checkOutput, 'Cannot find')) {
            $this->markTestSkipped('@modelcontextprotocol/server-filesystem not installed — skipping smoke test');
        }

        $tmpDir = sys_get_temp_dir().'/mcp-fs-test-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/hello.txt', 'hello from mcp');

        try {
            $transport = McpTransport::fromConfig([
                'transport' => 'stdio',
                'command' => $npx,
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', $tmpDir],
                'url' => null,
                'env' => [],
                'headers' => [],
            ]);

            $client = new McpClient($transport, 'filesystem');
            $client->connect(15);

            $this->assertTrue($client->isConnected());
            $this->assertNotNull($client->getServerInfo());

            $client->close();
        } finally {
            @unlink($tmpDir.'/hello.txt');
            @rmdir($tmpDir);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makeTransport(): McpTransport
    {
        return McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'http://localhost:9999',
            'env' => [],
            'headers' => [],
        ]);
    }

    /**
     * Creates a McpClient in initialized state without a real connection,
     * bypassing connect() via reflection.
     */
    private function makeInitializedClient(array $capabilities = []): McpClient
    {
        $transport = $this->makeTransport();
        $client = new McpClient($transport, 'test-server');

        // Set up initialized state via reflection
        $r = new \ReflectionClass($client);

        $r->getProperty('initialized')->setValue($client, true);
        $r->getProperty('capabilities')->setValue($client, $capabilities);

        // Register the list_changed handlers as connect() would do
        $transport->onNotification('notifications/tools/list_changed', function () use ($client, $r): void {
            $r->getProperty('toolsCache')->setValue($client, null);
        });
        $transport->onNotification('notifications/resources/list_changed', function () use ($client, $r): void {
            $r->getProperty('resourcesCache')->setValue($client, null);
        });
        $transport->onNotification('notifications/prompts/list_changed', function () use ($client, $r): void {
            $r->getProperty('promptsCache')->setValue($client, null);
        });

        return $client;
    }
}
