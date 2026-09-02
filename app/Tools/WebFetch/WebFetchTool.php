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
    use WebFetchToolResolveRedirectConcern;

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

    /**
     * Presented to origins as a current desktop Chrome. Declared here rather
     * than in a trait because trait constants need PHP 8.2 and this package
     * supports 8.1.
     */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** Bytes of an error response echoed back so the failure reason survives. */
    private const ERROR_PREVIEW_BYTES = 1024;

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
