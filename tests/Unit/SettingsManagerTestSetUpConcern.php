<?php

namespace Tests\Unit;

use HaoCode\Services\Settings\SettingsManager;
use Tests\TestCase;

trait SettingsManagerTestSetUpConcern
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate tests from real global/project settings
        config([
            'haocode.global_settings_path' => sys_get_temp_dir() . '/haocode_test_nonexistent_' . uniqid() . '/settings.json',
        ]);
    }

    public function test_it_maps_claude_models_to_kimi_for_kimi_coding_endpoint(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.kimi.com/coding/',
            'haocode.model' => 'claude-sonnet-4-6',
        ]);

        $settings = new SettingsManager;

        $this->assertSame('kimi-for-coding', $settings->getModel());
        $this->assertContains('kimi-for-coding', SettingsManager::getAvailableModels());
    }

    public function test_it_keeps_the_configured_model_for_non_kimi_endpoints(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-6',
        ]);

        $settings = new SettingsManager;

        $this->assertSame('claude-sonnet-4-6', $settings->getModel());
    }

    public function test_non_anthropic_provider_requires_an_explicit_or_provider_model(): void
    {
        config([
            'haocode.model' => null,
            'haocode.provider_type' => 'openai',
        ]);

        $settings = new SettingsManager;
        $settings->set('provider_type', 'openai');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A model is required for provider type "openai"');

        $settings->getModel();
    }

    public function test_non_anthropic_provider_uses_its_selected_provider_model(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'openai-main',
            'provider' => [
                'openai-main' => [
                    'type' => 'openai',
                    'model' => 'gpt-5.2',
                ],
            ],
        ]);

        $this->assertSame('gpt-5.2', $settings->getModel());
    }

    public function test_explicit_openai_provider_does_not_reuse_active_anthropic_credentials(): void
    {
        $originalOpenAiKey = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $settings = new SettingsManager;
            $reflection = new \ReflectionObject($settings);
            $reflection->getProperty('cachedSettings')->setValue($settings, [
                'active_provider' => 'anthropic-main',
                'api_key' => 'legacy-anthropic-key',
                'provider' => [
                    'anthropic-main' => [
                        'type' => 'anthropic',
                        'api_key' => 'active-anthropic-key',
                        'api_base_url' => 'https://api.anthropic.com',
                        'model' => 'claude-opus-4-8',
                    ],
                ],
            ]);
            $settings->set('provider_type', 'openai');
            $settings->set('model', 'gpt-5.2');

            $resolved = $settings->resolveProviderConfig();

            $this->assertSame('openai', $resolved->providerType);
            $this->assertNull($resolved->providerName);
            $this->assertSame('', $resolved->apiKey);
            $this->assertSame('gpt-5.2', $resolved->model);
            $this->assertSame('https://api.openai.com', $resolved->baseUrl);
        } finally {
            $originalOpenAiKey === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$originalOpenAiKey);
        }
    }

    public function test_explicit_provider_type_selects_the_only_matching_provider_as_one_unit(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-opus-4-8',
                    'max_tokens' => 8192,
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'api_base_url' => 'https://openai.example.test',
                    'model' => 'gpt-5.2',
                    'max_tokens' => 32768,
                    'context_window' => 128000,
                ],
            ],
        ]);
        $settings->set('provider_type', 'openai');

        $resolved = $settings->resolveProviderConfig();

        $this->assertSame('openai-main', $resolved->providerName);
        $this->assertSame('openai-key', $resolved->apiKey);
        $this->assertSame('https://openai.example.test', $resolved->baseUrl);
        $this->assertSame('gpt-5.2', $resolved->model);
        $this->assertSame(32768, $resolved->maxTokens);
        $this->assertSame(128000, $resolved->contextWindow);
        $this->assertSame(128000, $settings->getContextWindow());
    }

    public function test_explicit_provider_switch_uses_the_matching_provider_context_window(): void
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
                    'context_window' => 200000,
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'model' => 'gpt-5.2',
                    'context_window' => 128000,
                ],
            ],
        ]);
        $settings->set('provider_type', 'openai');

        $this->assertSame('openai-main', $settings->resolveProviderConfig()->providerName);
        $this->assertSame(128000, $settings->getContextWindow());
    }

    public function test_explicit_connection_does_not_require_selecting_between_matching_providers(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'provider' => [
                'openai-primary' => [
                    'type' => 'openai',
                    'api_key' => 'provider-one-key',
                    'model' => 'provider-one-model',
                ],
                'openai-secondary' => [
                    'type' => 'openai',
                    'api_key' => 'provider-two-key',
                    'model' => 'provider-two-model',
                ],
            ],
        ]);
        $settings->set('provider_type', 'openai');
        $settings->set('api_key', 'explicit-key');
        $settings->set('model', 'explicit-model');

        $resolved = $settings->resolveProviderConfig();

        $this->assertNull($resolved->providerName);
        $this->assertSame('explicit-key', $resolved->apiKey);
        $this->assertSame('explicit-model', $resolved->model);
        $this->assertSame('https://api.openai.com', $resolved->baseUrl);
    }

    public function test_explicit_openai_provider_rejects_active_anthropic_model_fallback(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'model' => 'claude-opus-4-8',
                ],
            ],
        ]);
        $settings->set('provider_type', 'openai');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A model is required for provider type "openai"');

        $settings->resolveProviderConfig();
    }

    public function test_active_openai_provider_does_not_reuse_legacy_anthropic_credentials(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'openai-main',
            'api_key' => 'legacy-anthropic-key',
            'model' => 'claude-opus-4-8',
            'provider' => [
                'openai-main' => [
                    'type' => 'openai',
                    'model' => 'gpt-5.2',
                ],
            ],
        ]);
        $originalOpenAiKey = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $resolved = $settings->resolveProviderConfig();

            $this->assertSame('openai', $resolved->providerType);
            $this->assertSame('', $resolved->apiKey);
            $this->assertSame('gpt-5.2', $resolved->model);
            $this->assertNotSame('legacy-anthropic-key', $resolved->apiKey);
        } finally {
            $originalOpenAiKey === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$originalOpenAiKey);
        }
    }

    public function test_set_runtime_override_affects_get_model(): void
    {
        config(['haocode.api_base_url' => 'https://api.anthropic.com']);

        $settings = new SettingsManager;
        $settings->set('model', 'claude-haiku-4-5-20251001');

        $this->assertSame('claude-haiku-4-5-20251001', $settings->getModel());
    }

    public function test_set_ignores_unknown_keys(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-6',
        ]);

        $settings = new SettingsManager;
        $settings->set('unknown_key', 'anything');

        // Should not affect model
        $this->assertSame('claude-sonnet-4-6', $settings->getModel());
    }

    public function test_get_base_url_returns_configured_value(): void
    {
        config(['haocode.api_base_url' => 'https://custom.api.com']);

        $settings = new SettingsManager;

        $this->assertSame('https://custom.api.com', $settings->getBaseUrl());
    }

    public function test_set_runtime_override_affects_base_url(): void
    {
        $settings = new SettingsManager;
        $settings->set('api_base_url', 'https://override.api.com');

        $this->assertSame('https://override.api.com', $settings->getBaseUrl());
    }

    public function test_get_max_tokens_returns_configured_value(): void
    {
        config(['haocode.max_tokens' => 8192]);

        $settings = new SettingsManager;

        $this->assertSame(8192, $settings->getMaxTokens());
    }

    public function test_context_window_supports_config_and_runtime_override(): void
    {
        config(['haocode.context_window' => 128000]);
        $settings = new SettingsManager;

        $this->assertSame(128000, $settings->getContextWindow());

        $settings->set('context_window', 64000);
        $this->assertSame(64000, $settings->getContextWindow());
    }

    public function test_non_anthropic_provider_uses_configured_context_window(): void
    {
        config([
            'haocode.context_window' => 1_000_000,
            'haocode.model' => 'deepseek-v4-flash',
            'haocode.provider_type' => 'openai_chat',
        ]);

        $settings = new SettingsManager;
        $settings->set('provider_type', 'openai_chat');
        $settings->set('model', 'deepseek-v4-flash');

        $resolved = $settings->resolveProviderConfig();

        $this->assertSame('openai_chat', $resolved->providerType);
        $this->assertSame(1_000_000, $resolved->contextWindow);
    }

    public function test_context_window_can_be_defined_per_provider(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'active_provider' => 'small-window',
            'provider' => [
                'small-window' => [
                    'type' => 'anthropic',
                    'context_window' => 64000,
                ],
            ],
        ]);

        $this->assertSame(64000, $settings->getContextWindow());
    }

    public function test_get_permission_mode_returns_default(): void
    {
        config(['haocode.permission_mode' => 'default']);

        $settings = new SettingsManager;

        $this->assertSame(\HaoCode\Services\Permissions\PermissionMode::Default, $settings->getPermissionMode());
    }

    public function test_modern_approval_policy_maps_to_accept_edits_permission_mode(): void
    {
        $settings = new SettingsManager;
        $settings->set('approval_policy', 'on-failure');

        $this->assertSame(\HaoCode\Services\Permissions\PermissionMode::AcceptEdits, $settings->getPermissionMode());
        $this->assertSame('on-failure', $settings->getApprovalPolicy());
        $this->assertSame('workspace-write', $settings->getSandboxMode());
    }

    public function test_modern_sandbox_mode_maps_to_plan_permission_mode(): void
    {
        $settings = new SettingsManager;
        $settings->set('sandbox_mode', 'read-only');

        $this->assertSame(\HaoCode\Services\Permissions\PermissionMode::Plan, $settings->getPermissionMode());
        $this->assertSame('read-only', $settings->getSandboxMode());
        $this->assertSame('on-request', $settings->getApprovalPolicy());
    }

    public function test_project_settings_reject_invalid_explicit_sandbox_mode(): void
    {
        $projectDir = sys_get_temp_dir().'/haocode_invalid_sandbox_'.bin2hex(random_bytes(6));
        $settingsDir = $projectDir.'/.haocode';
        mkdir($settingsDir, 0700, true);
        file_put_contents(
            $settingsDir.'/settings.json',
            json_encode([
                'permission_mode' => 'default',
                'sandbox_mode' => 'read_only',
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid sandbox mode');
            (new SettingsManager($projectDir))->getPermissionMode();
        } finally {
            @unlink($settingsDir.'/settings.json');
            @unlink($settingsDir.'/settings.json.lock');
            @rmdir($settingsDir);
            @rmdir($projectDir);
        }
    }

    public function test_runtime_legacy_mode_does_not_hide_invalid_project_modern_mode(): void
    {
        $settings = new SettingsManager;
        $reflection = new \ReflectionObject($settings);
        $reflection->getProperty('cachedSettings')->setValue($settings, [
            'sandbox_mode' => 'read_only',
        ]);
        $settings->set('permission_mode', 'default');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sandbox mode');
        $settings->getPermissionMode();
    }

    public function test_model_provider_alias_tracks_active_provider(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_model_provider_' . getmypid() . '_' . uniqid();
        mkdir($tmpDir . '/.haocode', 0755, true);

        file_put_contents($tmpDir . '/.haocode/settings.json', json_encode([
            'provider' => [
                'anthropic' => [
                    'api_key' => 'fake-ant-test-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'zai' => [
                    'api_key' => 'fake-zai-test-key',
                    'api_base_url' => 'https://api.z.ai/api/anthropic',
                    'model' => 'glm-5.1',
                ],
            ],
        ]));

        $origDir = getcwd();
        chdir($tmpDir);

        try {
            $settings = new SettingsManager;
            $settings->set('model_provider', 'zai');

            $this->assertSame('zai', $settings->getModelProvider());
            $this->assertSame('zai', $settings->getActiveProviderName());
        } finally {
            chdir($origDir);
            @unlink($tmpDir . '/.haocode/settings.json');
            @rmdir($tmpDir . '/.haocode');
            @rmdir($tmpDir);
        }
    }
}
