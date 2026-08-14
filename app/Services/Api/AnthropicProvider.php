<?php

namespace HaoCode\Services\Api;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Services\Settings\ModelCatalog;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Anthropic Messages API streaming provider.
 *
 * This is the original StreamingClient implementation; kept verbatim in
 * behaviour (retries, rate-limit header capture, Z.ai WebFetch workaround,
 * prompt-caching breakpoints, abort handling, idle timeout). Extracted
 * behind {@see LlmProvider} so a second wire format (OpenAI Responses) can
 * coexist without touching the call sites.
 */
class AnthropicProvider implements ApiKeyAwareProvider, SettingsAwareProvider
{
    use AnthropicProviderConstructConcern;
    use AnthropicProviderShouldRetryConcern;

    private const MAX_SSE_LINE_BYTES = 4 * 1024 * 1024;
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;

    private HttpClientInterface $httpClient;
    private int $maxRetries = 3;
    private array $lastRateLimitHeaders = [];
    /** @var array<string, string> */
    private array $headers;
    /** @var callable(): float */
    private $timeProvider;
}
