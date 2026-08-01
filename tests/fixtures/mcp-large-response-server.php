<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use HaoCode\Services\Mcp\Server\McpServer;
use HaoCode\Services\Mcp\Server\ToolAdapter;

final class LargeResponseToolAdapter extends ToolAdapter
{
    public function listTools(): array
    {
        return [[
            'name' => 'large-description',
            'description' => str_repeat('x', 2 * 1024 * 1024),
            'inputSchema' => ['type' => 'object'],
        ]];
    }
}

(new McpServer(new LargeResponseToolAdapter()))->run();
