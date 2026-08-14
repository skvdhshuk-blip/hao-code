<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\OpenAiProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\Support\MockAnthropicSse;

class StreamingClientTest extends TestCase
{
    use StreamingClientTestMakeChunkConcern;
    use StreamingClientTestTestItSwallowsTransportExceptionAfterAbortIsRequestedMidStreamConcern;
    use StreamingClientTestTestModelAndMaxTokensResolvedFromSettingsConcern;
    use StreamingClientTestTestItForcesHttp11ForZaiStreamingRequestsConcern;


    // ─── retry logic for specific error types ─────────────────────────────

    // ─── SSE error event throws ApiErrorException ─────────────────────────

    // ─── data line with leading space ─────────────────────────────────────

    // ─── multiline data accumulation ──────────────────────────────────────

    // ─── empty data event is ignored ──────────────────────────────────────

    // ─── extended thinking payload ────────────────────────────────────────

    // ─── settings manager integration ─────────────────────────────────────

    // ─── OAuth bearer token mode ──────────────────────────────────────────

    // ─── cache_control on tools ───────────────────────────────────────────

    // ─── HTTP error with non-JSON body ────────────────────────────────────

    // ─── HTTP error with empty body ───────────────────────────────────────
}
