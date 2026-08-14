<?php

namespace HaoCode\Tools\WebFetch;

use HaoCode\Support\Net\SsrfGuard;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebFetchTool extends BaseTool
{
    use WebFetchToolConstructConcern;
    use WebFetchToolNormalizePathConcern;

    /**
     * Shared per-process cache. The key includes the security policy so a
     * response fetched under a permissive policy is never served to a strict
     * caller (and vice versa).
     *
     * @var array<string, array{content: string, time: int, final_url: ?string}>
     */
    private static array $cache = [];

    /** Maximum entries retained; oldest evicted first. */
    private const CACHE_TTL = 900; // 15 minutes

    private const CACHE_MAX_ENTRIES = 128;

    private const CACHE_MAX_BYTES = 33_554_432; // 32 MiB

    private const MAX_CONTENT_SIZE = 100000;

    /**
     * Hard cap on decompressed bytes pulled into memory per request. Five MiB
     * matches the default HaoCodeConfig::$webfetchMaxBytes.
     */
    private const DEFAULT_MAX_BYTES = 5_242_880;

    private const MAX_REDIRECTS = 3;

    private ?HttpClientInterface $client = null;

    /** @var list<string> */
    private array $ssrfAllowList;

    private int $maxBytes;

    private bool $allowPrivateNetworks;
}

/**
 * @internal
 */
final class WebFetchAbortedException extends \RuntimeException
{
}
