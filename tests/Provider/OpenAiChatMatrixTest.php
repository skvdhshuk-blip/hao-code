<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\OpenAiChatProvider;

class OpenAiChatMatrixTest extends AbstractMatrixTest
{
    protected function providerName(): string
    {
        return 'openai-chat';
    }

    protected function createMockProvider(string $sseFixturePath): LlmProvider
    {
        return new OpenAiChatProvider(
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
            httpClient: self::buildMockClient($sseFixturePath),
        );
    }
}
