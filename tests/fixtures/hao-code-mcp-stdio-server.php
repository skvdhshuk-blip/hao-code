<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use HaoCode\Services\Mcp\Server\McpServer;
use HaoCode\Services\Mcp\Server\ToolAdapter;

(new McpServer(new ToolAdapter()))->run();
