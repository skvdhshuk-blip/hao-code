<?php

namespace Tests\Unit;

use HaoCode\Services\Settings\SettingsManager;
use Tests\TestCase;

trait SettingsManagerTestTestClearingNamedProviderPreservesExplicitConnectionOverridesConcern
{

    public function test_clearing_named_provider_preserves_explicit_connection_overrides(): void
    {
        $settings = new SettingsManager;
        $settings->set('provider_type', 'anthropic');
        $settings->set('api_key', 'explicit-key');
        $settings->set('model', 'explicit-model');
        $settings->set('api_base_url', 'https://explicit.example.test');

        $settings->set('active_provider', null);
        $resolved = $settings->resolveProviderConfig();

        $this->assertSame('anthropic', $resolved->providerType);
        $this->assertSame('explicit-key', $resolved->apiKey);
        $this->assertSame('explicit-model', $resolved->model);
        $this->assertSame('https://explicit.example.test', $resolved->baseUrl);
    }

    public function test_qualified_model_switch_replaces_the_complete_connection_identity(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'configured-anthropic-key',
                    'model' => 'claude-sonnet-4-6',
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'configured-openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'provider-default-model',
                ],
            ],
        ]);
        $settings->set('api_key', 'runtime-anthropic-key');
        $settings->set('api_base_url', 'https://anthropic-proxy.example.test');
        $settings->set('oauth_bearer', true);

        $settings->set('model', 'openai-main/gpt-5.2');
        $resolved = $settings->resolveProviderConfig();

        $this->assertSame('openai', $resolved->providerType);
        $this->assertSame('openai-main', $resolved->providerName);
        $this->assertSame('configured-openai-key', $resolved->apiKey);
        $this->assertSame('https://api.openai.com', $resolved->baseUrl);
        $this->assertSame('gpt-5.2', $resolved->model);
        $this->assertTrue($settings->isOauthBearer());
    }

    public function test_rejected_runtime_provider_switch_rolls_back_every_override(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'model' => 'claude-sonnet-4-6',
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'model' => 'gpt-5.2',
                ],
            ],
        ]);
        $settings->set('api_key', 'runtime-key');
        $settings->set('model', 'runtime-model');
        $settings->setRuntimeConfigurationValidator(
            static function ($resolvedProvider): void {
                if ($resolvedProvider->providerType === 'openai') {
                    throw new \RuntimeException('openai rejected by run guard');
                }
            },
        );

        try {
            $settings->set('active_provider', 'openai-main');
            $this->fail('Expected the runtime guard to reject the provider switch.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('openai rejected by run guard', $exception->getMessage());
        }

        $resolved = $settings->resolveProviderConfig();
        $this->assertSame('anthropic', $resolved->providerType);
        $this->assertSame('runtime-key', $resolved->apiKey);
        $this->assertSame('runtime-model', $resolved->model);
    }
}
