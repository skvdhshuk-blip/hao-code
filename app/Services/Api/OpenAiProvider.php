<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI Responses API (/v1/responses) streaming provider.
 *
 * Translates the caller-facing Anthropic-shaped request into a Responses
 * API payload, and maps the Responses event stream back into the
 * Anthropic SSE events StreamProcessor consumes, so the rest of the
 * agent loop is unaware of the wire format swap.
 *
 * Mapping notes:
 *   - Each Responses "output item" (message / reasoning / function_call)
 *     is surfaced as a single Anthropic content block, keyed by
 *     output_index.
 *   - response.output_text.delta           → text_delta
 *   - response.reasoning_summary_text.delta
 *     (or response.reasoning_text.delta)   → thinking_delta
 *   - response.function_call_arguments.delta → input_json_delta
 *   - stop_reason: tool_use when any output item is a function_call,
 *     max_tokens when response.incomplete_details.reason says so,
 *     end_turn otherwise.
 *
 * Prompt-caching is NOT advertised here: the Responses API has no
 * equivalent to Anthropic's cache_control breakpoints, so caller-supplied
 * cache_control hints are stripped during translation.
 */
class OpenAiProvider implements ApiKeyAwareProvider, SettingsAwareProvider
{
    use OpenAiProviderConstructConcern;
    use OpenAiProviderTranslateOpenAiEventConcern;
    use OpenAiProviderBuildRequestHeadersConcern;

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

/**
 * Mutable per-turn translator state. Not part of the public API.
 */
class OpenAiTranslatorState
{
    public bool $messageStartEmitted = false;
    public bool $hasFunctionCall = false;
    /** @var array<int, array{type: string, call_id?: string, text_started?: bool}> */
    public array $contentBlocks = [];
}
