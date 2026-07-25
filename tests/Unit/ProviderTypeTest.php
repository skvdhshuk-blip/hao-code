<?php

namespace Tests\Unit;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Settings\ProviderType;
use HaoCode\Services\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;

class ProviderTypeTest extends TestCase
{
    public function test_normalize_explicit_accepts_aliases(): void
    {
        $this->assertNull(ProviderType::normalizeExplicit(null));
        $this->assertNull(ProviderType::normalizeExplicit(''));
        $this->assertSame('openai', ProviderType::normalizeExplicit('openai_responses'));
        $this->assertSame('openai_chat', ProviderType::normalizeExplicit('chat_completions'));
        $this->assertSame('anthropic', ProviderType::normalizeExplicit('anthropic'));
    }

    public function test_normalize_explicit_rejects_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider type: open_ai');
        ProviderType::normalizeExplicit('open_ai');
    }

    public function test_haocode_config_rejects_unknown_provider_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider type');
        new HaoCodeConfig(providerType: 'open_ai', apiKey: 'sk-test', model: 'gpt-5.2');
    }

    public function test_haocode_config_normalizes_provider_aliases(): void
    {
        $config = new HaoCodeConfig(providerType: 'openai_responses');
        $this->assertSame('openai', $config->providerType);
    }

    public function test_settings_manager_rejects_unknown_runtime_provider_type(): void
    {
        $settings = new SettingsManager;
        $settings->set('provider_type', 'not_a_provider');

        $this->expectException(\InvalidArgumentException::class);
        $settings->getProviderType();
    }

    public function test_settings_manager_rejects_unknown_provider_entry_type(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'broken',
            'provider' => [
                'broken' => [
                    'type' => 'open_ai',
                    'api_key' => 'k',
                    'model' => 'm',
                ],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $settings->getProviderType();
    }
}
