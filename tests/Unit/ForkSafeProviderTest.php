<?php

namespace Tests\Unit;

use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\ForkSafeProvider;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use PHPUnit\Framework\TestCase;

class ForkSafeProviderTest extends TestCase
{
    public function test_streaming_client_rebuilds_provider_transports_after_fork(): void
    {
        $client = new StreamingClient(
            apiKey: 'explicit-key',
            model: 'parent-model',
            baseUrl: 'https://example.test',
            maxTokens: 1234,
        );
        $settings = new SettingsManager();
        $settings->set('api_key', 'explicit-key');
        $settings->set('model', 'child-model');
        $settings->set('api_base_url', 'https://child.example.test');

        $fresh = $client->freshAfterFork($settings);

        $this->assertInstanceOf(ForkSafeProvider::class, $fresh);
        $this->assertNotSame($client, $fresh);
        $this->assertNotSame(
            $this->readPrivate($client, 'anthropic'),
            $this->readPrivate($fresh, 'anthropic'),
        );
        $this->assertSame('child-model', $this->readPrivate($fresh, 'connectionConfig')['model']);
        $this->assertSame('explicit-key', $this->readPrivate($fresh, 'connectionConfig')['apiKey']);
    }

    public function test_pooled_provider_rebinds_a_settings_aware_inner_provider_after_fork(): void
    {
        $inner = new AnthropicProvider(
            apiKey: 'parent-key',
            model: 'parent-model',
            baseUrl: 'https://example.test',
        );
        $pool = new CredentialPool;
        $pool->add('anthropic', new Credential('pool-key'));
        $provider = new PooledProvider($inner, $pool, 'anthropic');
        $settings = new SettingsManager;
        $settings->set('model', 'child-model');

        $fresh = $provider->freshAfterFork($settings);
        $freshInner = $this->readPrivate($fresh, 'inner');

        $this->assertNotSame($inner, $freshInner);
        $this->assertSame($settings, $this->readPrivate($freshInner, 'settingsManager'));
    }

    public function test_pooled_provider_rejects_scoped_settings_for_unaware_inner_provider(): void
    {
        $inner = new class implements LlmProvider {
            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                if (false) {
                    yield;
                }
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };
        $provider = (new PooledProvider($inner, new CredentialPool, 'anthropic'))
            ->requiringScopedSettings();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inner provider is not settings-aware');

        $provider->withSettingsManager(new SettingsManager);
    }

    public function test_pooled_provider_keeps_unaware_inner_without_scoped_override(): void
    {
        $inner = new class implements LlmProvider {
            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                if (false) {
                    yield;
                }
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };
        $provider = new PooledProvider($inner, new CredentialPool, 'anthropic');

        $rebound = $provider->withSettingsManager(new SettingsManager);

        $this->assertSame($inner, $this->readPrivate($rebound, 'inner'));
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
