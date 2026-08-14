<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Runtime\ProcessSupervisor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport layer for communicating with an MCP server via JSON-RPC 2.0.
 * Supports stdio (subprocess), http (streamable HTTP), and sse transports.
 */
final class McpTransport
{
    use McpTransportConstructConcern;
    use McpTransportWriteStdioPayloadConcern;
    use McpTransportReadHttpBodyConcern;

    private ?int $nextId = 1;

    /** @var resource|null stdio process handle */
    private $process = null;

    /** @var resource|null stdin pipe */
    private $stdin = null;

    /** @var resource|null stdout pipe */
    private $stdout = null;

    /** @var resource|null stderr pipe for capturing server error logs */
    private $stderr = null;

    /** @var string read buffer for stdio */
    private string $readBuffer = '';

    /** 4 MB read-buffer ceiling to prevent OOM from malicious servers */
    private const READ_BUFFER_MAX = 4 * 1024 * 1024;

    /** HTTP JSON/error bodies use the same hard ceiling as stdio frames. */
    private const HTTP_RESPONSE_MAX = 4 * 1024 * 1024;

    private const STDERR_BUFFER_MAX = 32 * 1024;

    private const SERVER_STREAM_TIMEOUT_SECONDS = 30;

    private const SERVER_STREAM_MAX_DURATION_SECONDS = 86400;

    /** Environment variables safe to pass to stdio servers by default. */
    private const STDIO_ENV_ALLOWLIST = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL',
        'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL',
        'SystemRoot', 'ComSpec', 'PATHEXT',
    ];

    private ?string $httpSessionId = null;

    private ?string $protocolVersion = null;

    private HttpClientInterface $httpClient;

    private ?McpOAuthTokenProvider $oauthTokenProvider;

    private ?ResponseInterface $serverEventStream = null;

    private ?McpSseDecoder $serverEventDecoder = null;

    private ?string $serverLastEventId = null;

    private string $stderrBuffer = '';

    private int $serverRetryMilliseconds = 1000;

    private float $serverReconnectAt = 0.0;

    private bool $serverEventStreamSupported = true;

    private bool $httpSessionExpired = false;

    /** @var array<string, callable[]> Registered notification handlers by method */
    private array $notificationHandlers = [];

    /** @var array<string, callable> Registered inbound request handlers by method (for reverse RPCs) */
    private array $requestHandlers = [];

    // ─── stdio transport ────────────────────────────────────────────────

    // ─── HTTP transport (Streamable HTTP / legacy SSE response) ─────────
}
