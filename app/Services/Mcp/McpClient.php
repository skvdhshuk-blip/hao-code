<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Telemetry\PhoenixTracer;
use OpenTelemetry\API\Trace\SpanInterface;

/**
 * MCP protocol client — handles initialization handshake, tool/resource/prompt
 * discovery and invocation over a McpTransport.
 */
final class McpClient
{
    use McpClientConstructConcern;
    use McpClientListAllPagesConcern;

    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
        '2024-10-07',
    ];

    /** Safety cap for tools/list, resources/list, prompts/list pagination. */
    private const LIST_MAX_PAGES = 100;

    /** Prevent a valid-looking paginated server from exhausting client memory. */
    private const LIST_MAX_ITEMS = 10_000;

    /** Bound decoded list payloads in addition to the per-page transport cap. */
    private const LIST_MAX_AGGREGATE_BYTES = 16 * 1024 * 1024;

    /** Cursor values are protocol metadata, not an unbounded payload channel. */
    private const LIST_MAX_CURSOR_BYTES = 16_384;

    private bool $initialized = false;

    /** @var array<string, mixed>|null Server capabilities from initialize response */
    private ?array $capabilities = null;

    /** @var array{name: string, version: string}|null */
    private ?array $serverInfo = null;

    /** @var string|null Server instructions */
    private ?string $instructions = null;

    /** @var array<int, array{name: string, description: string, inputSchema: array}>|null Cached tools list */
    private ?array $toolsCache = null;

    /** @var array<int, array{uri: string, name: string, mimeType?: string, description?: string}>|null */
    private ?array $resourcesCache = null;

    /** @var array<int, array{name: string, description: string, arguments?: array}>|null */
    private ?array $promptsCache = null;

    /** @var array<array{uri: string, name?: string}> Workspace roots reported to the server */
    private array $roots = [];

    private bool $protocolHandlersRegistered = false;
}
