<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

final class McpSessionExpiredException extends McpConnectionException
{
    public function __construct(string $message = 'MCP Streamable HTTP session expired')
    {
        parent::__construct($message, 404, self::TYPE_TRANSPORT);
    }
}
