<?php

namespace Tests\Unit;

use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\ForkSafeProvider;
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

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
