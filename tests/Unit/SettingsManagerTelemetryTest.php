<?php

namespace Tests\Unit;

use HaoCode\Services\Settings\SettingsManager;
use Tests\TestCase;

class SettingsManagerTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate from the user's real HOME — SettingsManager reads
        // $HOME/.haocode/settings.json which might pollute test config.
        config([
            'haocode.global_settings_path' => sys_get_temp_dir() . '/haocode_tel_test_' . uniqid() . '/settings.json',
        ]);
        putenv('HAOCODE_PHOENIX_ENABLED');
        putenv('HAOCODE_PHOENIX_ENDPOINT');
        putenv('HAOCODE_PHOENIX_API_KEY');
        putenv('HAOCODE_PHOENIX_PROJECT');
        putenv('HAOCODE_PHOENIX_REDACT');
    }

    public function test_env_vars_override_everything_else(): void
    {
        putenv('HAOCODE_PHOENIX_ENABLED=true');
        putenv('HAOCODE_PHOENIX_ENDPOINT=https://phoenix.example.com');
        putenv('HAOCODE_PHOENIX_API_KEY=envkey123');
        putenv('HAOCODE_PHOENIX_PROJECT=my-project');
        putenv('HAOCODE_PHOENIX_REDACT=1');

        $settings = new SettingsManager();
        $config = $settings->getTelemetryConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('https://phoenix.example.com', $config['endpoint']);
        $this->assertSame('envkey123', $config['api_key']);
        $this->assertSame('my-project', $config['project_name']);
        $this->assertTrue($config['redact_messages']);
    }

    public function test_defaults_when_nothing_is_configured(): void
    {
        $settings = new SettingsManager();
        $config = $settings->getTelemetryConfig();

        $this->assertFalse($config['enabled']);
        $this->assertSame('hao-code', $config['project_name']);
        $this->assertFalse($config['redact_messages']);
    }

    protected function tearDown(): void
    {
        putenv('HAOCODE_PHOENIX_ENABLED');
        putenv('HAOCODE_PHOENIX_ENDPOINT');
        putenv('HAOCODE_PHOENIX_API_KEY');
        putenv('HAOCODE_PHOENIX_PROJECT');
        putenv('HAOCODE_PHOENIX_REDACT');
        parent::tearDown();
    }
}
