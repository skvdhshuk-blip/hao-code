<?php

namespace Tests\Unit;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;

trait McpClientTestTestDiscoverAllToolsEmptyWhenNoConnectionsConcern
{

    public function test_discover_all_tools_empty_when_no_connections(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->discoverAllTools());
    }

    public function test_discover_all_resources_empty_when_no_connections(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->discoverAllResources());
    }

    public function test_client_not_initialized_throws(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => [],
        ]);

        $client = new McpClient($transport, 'test');

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('not initialized');
        $client->listTools();
    }

    public function test_client_is_not_connected_before_init(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => [],
        ]);

        $client = new McpClient($transport, 'test');
        $this->assertFalse($client->isConnected());
    }
}
