<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\OpenAiProvider;

class OpenAiResponsesMatrixTest extends AbstractMatrixTest
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
}
