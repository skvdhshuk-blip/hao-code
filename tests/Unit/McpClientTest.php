<?php

namespace Tests\Unit;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;

class McpClientTest extends TestCase
{
    use McpClientTestTestTransportFromConfigStdioConcern;
    use McpClientTestTestDiscoverAllToolsEmptyWhenNoConnectionsConcern;

    // ─── McpTransport ─────────────────────────────────────────────────

    // ─── McpConnectionManager ──────────────────────────────────────��──

    // ─── McpClient (unit, without real transport) ─────────────────────
}
