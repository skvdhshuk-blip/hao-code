<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiChatProviderTest extends TestCase
{
    use OpenAiChatProviderTestTestBuildPayloadTranslatesMessagesToolsAndSystemConcern;
    use OpenAiChatProviderTestTestNativeWrapperFixtureCapturesRateLimitHeadersAndFinalStatusConcern;


    // ─── custom request headers ─────────────────────────────────────────
}
