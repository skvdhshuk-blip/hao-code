<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\OpenAiProvider;
use Symfony\Component\HttpClient\MockHttpClient;

class OpenAiResponsesMatrixTest extends ProviderMatrixContract
{
    protected function providerName(): string
    {
        return 'openai-responses';
    }

    protected function createMockProvider(string $sseFixturePath): LlmProvider
    {
        return new OpenAiProvider(
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
            httpClient: self::buildMockClient($sseFixturePath),
        );
    }

    protected function createMockProviderMulti(MockHttpClient $client): LlmProvider
    {
        return new OpenAiProvider(
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
            httpClient: $client,
        );
    }
}
