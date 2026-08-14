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

trait StreamingClientTestTestItForcesHttp11ForZaiStreamingRequestsConcern
{

    public function test_it_forces_http_1_1_for_zai_streaming_requests(): void
    {
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'glm-5.1',
            baseUrl: 'https://api.z.ai/api/anthropic',
            httpClient: $httpClient,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('1.1', $capturedOptions['http_version'] ?? null);
        $this->assertTrue($capturedOptions['verify_peer'] ?? false);
        $this->assertTrue($capturedOptions['verify_host'] ?? false);
    }

    public function test_dispatcher_reads_the_current_provider_type_on_every_request(): void
    {
        $settings = new SettingsManager;
        $settings->set('provider_type', 'anthropic');
        $settings->set('api_key', 'test-key');
        $settings->set('model', 'claude-sonnet-4-6');
        $anthropic = $this->createMock(AnthropicProvider::class);
        $anthropic->expects($this->once())
            ->method('streamMessages')
            ->willReturnCallback(static function (): \Generator {
                yield new StreamEvent('anthropic-selected', []);
            });
        $openAi = $this->createMock(OpenAiProvider::class);
        $openAi->expects($this->once())
            ->method('streamMessages')
            ->willReturnCallback(static function (): \Generator {
                yield new StreamEvent('openai-selected', []);
            });
        $openAiChat = $this->createMock(OpenAiChatProvider::class);
        $openAiChat->expects($this->never())->method('streamMessages');
        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            settingsManager: $settings,
            anthropicProvider: $anthropic,
            openAiProvider: $openAi,
            openAiChatProvider: $openAiChat,
        );

        $first = iterator_to_array($client->streamMessages([], [], []));
        $settings->set('provider_type', 'openai');
        $settings->set('model', 'gpt-5.2');
        $second = iterator_to_array($client->streamMessages([], [], []));

        $this->assertSame('anthropic-selected', $first[0]->type);
        $this->assertSame('openai-selected', $second[0]->type);
    }

    public function test_with_api_key_snapshots_run_scoped_provider_settings(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getProviderType')->willReturn('openai');
        $settings->method('getModel')->willReturn('project-model');
        $settings->method('getBaseUrl')->willReturn('https://project.example.com');
        $settings->method('getMaxTokens')->willReturn(3210);
        $settings->method('isThinkingEnabled')->willReturn(true);
        $settings->method('getThinkingBudget')->willReturn(4321);

        $client = new StreamingClient(
            apiKey: 'fallback-key',
            model: 'fallback-model',
            settingsManager: $settings,
            httpClient: new MockHttpClient([]),
        );

        $pooledClient = $client->withApiKey('pool-key');
        $clientReflection = new \ReflectionObject($pooledClient);
        $this->assertSame('openai', $clientReflection->getProperty('defaultProviderType')->getValue($pooledClient));

        $openAi = $clientReflection->getProperty('openai')->getValue($pooledClient);
        $providerReflection = new \ReflectionObject($openAi);
        $this->assertSame('pool-key', $providerReflection->getProperty('apiKey')->getValue($openAi));
        $this->assertSame('project-model', $providerReflection->getProperty('model')->getValue($openAi));
        $this->assertSame('https://project.example.com', $providerReflection->getProperty('baseUrl')->getValue($openAi));
        $this->assertSame(3210, $providerReflection->getProperty('maxTokens')->getValue($openAi));
        $this->assertNull($providerReflection->getProperty('settingsManager')->getValue($openAi));

        $anthropic = $clientReflection->getProperty('anthropic')->getValue($pooledClient);
        $anthropicReflection = new \ReflectionObject($anthropic);
        $this->assertSame(
            'fallback-key',
            $anthropicReflection->getProperty('apiKey')->getValue($anthropic),
            'An OpenAI pool key must not be copied into an unused Anthropic adapter.',
        );
    }
}
