<?php

namespace Tests\Unit;

use Tests\TestCase;

trait SettingsManagerTestTestAllReturnsExpectedKeysConcern
{

    public function test_all_returns_expected_keys(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-6',
        ]);

        $settings = new SettingsManager;
        $all = $settings->all();

        $this->assertArrayHasKey('model', $all);
        $this->assertArrayHasKey('model_identifier', $all);
        $this->assertArrayHasKey('active_provider', $all);
        $this->assertArrayHasKey('configured_providers', $all);
        $this->assertArrayHasKey('api_base_url', $all);
        $this->assertArrayHasKey('max_tokens', $all);
        $this->assertArrayHasKey('permission_mode', $all);
        $this->assertArrayHasKey('output_style', $all);
        $this->assertArrayHasKey('api_key_set', $all);
    }

    public function test_permissions_from_global_and_project_are_accumulated_not_overwritten(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_merge_' . getmypid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Bash(git:*)'], 'deny' => []],
        ]));

        // Project only sets deny rules — should not lose global allow rules
        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'permissions' => ['deny' => ['Bash(rm -rf /)']],
        ]));

        config(['haocode.global_settings_path' => $globalDir . '/settings.json']);

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;

            // Global allow rule must survive despite project only having deny rules
            $this->assertContains('Bash(git:*)', $settings->getAllowRules(),
                'Global allow rule was lost when project settings define only deny rules (array_merge clobber bug)');
            $this->assertContains('Bash(rm -rf /)', $settings->getDenyRules(),
                'Project deny rule should be present');
        } finally {
            chdir($origDir);
            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }

    public function test_add_allow_rule_does_not_create_duplicates(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_dedup_' . getmypid();
        $projectSettingsDir = $tmpDir . '/.haocode';
        mkdir($projectSettingsDir, 0755, true);

        config(['haocode.global_settings_path' => '/nonexistent/path/settings.json']);

        $origDir = getcwd();
        chdir($tmpDir);

        try {
            $settings = new SettingsManager;

            // Add same rule twice
            $settings->addAllowRule('Bash(git:*)');
            $settings->addAllowRule('Bash(git:*)');

            $settings2 = new SettingsManager;
            $allow = $settings2->getAllowRules();

            $count = count(array_filter($allow, fn($r) => $r === 'Bash(git:*)'));
            $this->assertSame(1, $count, 'Same allow rule should not be added twice');
        } finally {
            chdir($origDir);
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($projectSettingsDir);
            @rmdir($tmpDir);
        }
    }

    public function test_persistent_rule_uses_configured_working_directory_not_process_cwd(): void
    {
        $tmpDir = sys_get_temp_dir().'/smtest_configured_cwd_'.bin2hex(random_bytes(4));
        $configuredProject = $tmpDir.'/configured';
        $processProject = $tmpDir.'/process';
        mkdir($configuredProject, 0755, true);
        mkdir($processProject, 0755, true);
        config(['haocode.global_settings_path' => $tmpDir.'/missing-global.json']);
        $originalCwd = getcwd();
        chdir($processProject);

        try {
            $settings = new SettingsManager($configuredProject);
            $settings->addAllowRule('Bash(git:*)');

            $this->assertFileExists($configuredProject.'/.haocode/settings.json');
            $this->assertFileDoesNotExist($processProject.'/.haocode/settings.json');
            $this->assertContains(
                'Bash(git:*)',
                (new SettingsManager($configuredProject))->getAllowRules(),
            );
        } finally {
            chdir($originalCwd);
            @unlink($configuredProject.'/.haocode/settings.json');
            @unlink($configuredProject.'/.haocode/settings.json.lock');
            @rmdir($configuredProject.'/.haocode');
            @rmdir($configuredProject);
            @rmdir($processProject);
            @rmdir($tmpDir);
        }
    }

    public function test_invalid_project_settings_are_not_treated_as_empty(): void
    {
        $tmpDir = sys_get_temp_dir().'/smtest_invalid_json_'.bin2hex(random_bytes(4));
        $settingsDir = $tmpDir.'/.haocode';
        mkdir($settingsDir, 0755, true);
        $path = $settingsDir.'/settings.json';
        file_put_contents($path, '{"permissions":');
        config(['haocode.global_settings_path' => $tmpDir.'/missing-global.json']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid JSON in settings file');
            (new SettingsManager($tmpDir))->getAllowRules();
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            @rmdir($settingsDir);
            @rmdir($tmpDir);
        }
    }

    public function test_both_global_and_project_allow_rules_are_present_after_load(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_combined_' . getmypid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Bash(git:*)']],
        ]));

        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Read(*:*)']],
        ]));

        config(['haocode.global_settings_path' => $globalDir . '/settings.json']);

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;
            $allow = $settings->getAllowRules();

            $this->assertContains('Bash(git:*)', $allow, 'Global allow rule should be present');
            $this->assertContains('Read(*:*)', $allow, 'Project allow rule should be present');
        } finally {
            chdir($origDir);
            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }

    public function test_global_and_project_settings_are_used_for_runtime_configuration(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_runtime_' . getmypid() . '_' . uniqid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'api_key' => 'global-api-key',
            'api_base_url' => 'https://global.api.example',
            'max_tokens' => 4096,
            'permission_mode' => 'plan',
        ]));

        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'model' => 'claude-opus-4-8',
            'max_tokens' => 8192,
        ]));

        config([
            'haocode.api_key' => '',
            'haocode.api_base_url' => 'https://config.api.example',
            'haocode.max_tokens' => 1024,
            'haocode.model' => 'claude-sonnet-4-6',
            'haocode.permission_mode' => 'default',
            'haocode.global_settings_path' => $globalDir . '/settings.json',
        ]);

        $originalApiKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=env-api-key');

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;

            $this->assertSame('global-api-key', $settings->getApiKey());
            $this->assertSame('https://global.api.example', $settings->getBaseUrl());
            $this->assertSame(8192, $settings->getMaxTokens());
            $this->assertSame('claude-opus-4-8', $settings->getModel());
            $this->assertSame(\HaoCode\Services\Permissions\PermissionMode::Plan, $settings->getPermissionMode());
        } finally {
            chdir($origDir);

            if ($originalApiKey === false) {
                putenv('ANTHROPIC_API_KEY');
            } else {
                putenv("ANTHROPIC_API_KEY={$originalApiKey}");
            }

            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }

    public function test_active_provider_uses_provider_specific_configuration(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_provider_' . getmypid() . '_' . uniqid();
        $globalDir = $tmpDir . '/global/.haocode';
        mkdir($globalDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'active_provider' => 'zai',
            'model' => 'anthropic/claude-sonnet-4-6',
            'provider' => [
                'anthropic' => [
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'zai' => [
                    'api_key' => 'zai-key',
                    'api_base_url' => 'https://api.z.ai/api/anthropic',
                    'model' => 'glm-5.1',
                    'max_tokens' => 12000,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        config([
            'haocode.api_key' => '',
            'haocode.api_base_url' => 'https://config.api.example',
            'haocode.max_tokens' => 1024,
            'haocode.model' => 'claude-sonnet-4-6',
            'haocode.global_settings_path' => $globalDir . '/settings.json',
        ]);

        try {
            $settings = new SettingsManager;

            $this->assertSame('zai', $settings->getActiveProviderName());
            $this->assertSame('zai-key', $settings->getApiKey());
            $this->assertSame('https://api.z.ai/api/anthropic', $settings->getBaseUrl());
            $this->assertSame(12000, $settings->getMaxTokens());
            $this->assertSame('glm-5.1', $settings->getModel());
            $this->assertSame('zai/glm-5.1', $settings->getResolvedModelIdentifier());
        } finally {
            @unlink($globalDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir(dirname($globalDir));
            @rmdir($tmpDir);
        }
    }

    public function test_runtime_model_prefix_selects_configured_provider(): void
    {
        config([
            'haocode.api_key' => '',
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-6',
        ]);

        $settings = new SettingsManager;

        $settings->useCachedSettings([
            'provider' => [
                'anthropic' => [
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'zai' => [
                    'api_key' => 'zai-key',
                    'api_base_url' => 'https://api.z.ai/api/anthropic',
                    'model' => 'glm-5.1',
                    'max_tokens' => 16384,
                ],
            ],
            'permissions' => ['allow' => [], 'deny' => []],
        ]);

        $settings->set('model', 'zai/glm-5.1');

        $this->assertSame('zai', $settings->getActiveProviderName());
        $this->assertSame('zai-key', $settings->getApiKey());
        $this->assertSame('https://api.z.ai/api/anthropic', $settings->getBaseUrl());
        $this->assertSame('glm-5.1', $settings->getModel());
    }

    public function test_thinking_defaults_to_disabled(): void
    {
        $settings = new SettingsManager;

        $this->assertFalse($settings->isThinkingEnabled());
        $this->assertSame(10000, $settings->getThinkingBudget());
    }

    public function test_thinking_can_be_enabled_via_runtime_override(): void
    {
        $settings = new SettingsManager;
        $settings->set('thinking_enabled', true);
        $settings->set('thinking_budget', 32000);

        $this->assertTrue($settings->isThinkingEnabled());
        $this->assertSame(32000, $settings->getThinkingBudget());
    }

    public function test_effort_level_defaults_to_auto(): void
    {
        $settings = new SettingsManager;

        $this->assertSame('auto', $settings->getEffortLevel());
    }

    public function test_effort_level_can_be_set_via_runtime_override(): void
    {
        $settings = new SettingsManager;
        $settings->set('effort_level', 'max');

        $this->assertSame('max', $settings->getEffortLevel());
    }

    public function test_global_and_project_provider_maps_are_merged(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_provider_merge_' . getmypid() . '_' . uniqid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'provider' => [
                'anthropic' => [
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'provider' => [
                'zai' => [
                    'api_key' => 'zai-key',
                    'api_base_url' => 'https://api.z.ai/api/anthropic',
                    'model' => 'glm-5.1',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        config(['haocode.global_settings_path' => $globalDir . '/settings.json']);

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;

            $this->assertSame(['anthropic', 'zai'], array_keys($settings->getConfiguredProviders()));
        } finally {
            chdir($origDir);
            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }

    public function test_active_provider_switch_replaces_the_complete_connection_identity(): void
    {
        $settings = new SettingsManager;
        $settings->useCachedSettings([
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'configured-anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'configured-openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                    'max_tokens' => 8192,
                ],
            ],
        ]);
        $settings->set('provider_type', 'anthropic');
        $settings->set('api_key', 'runtime-anthropic-key');
        $settings->set('model', 'runtime-anthropic-model');
        $settings->set('api_base_url', 'https://anthropic-proxy.example.test');
        $settings->set('oauth_bearer', true);
        $settings->set('headers', ['X-Anthropic-Only' => 'yes']);

        $settings->set('active_provider', 'openai-main');
        $resolved = $settings->resolveProviderConfig();

        $this->assertSame('openai', $resolved->providerType);
        $this->assertSame('openai-main', $resolved->providerName);
        $this->assertSame('configured-openai-key', $resolved->apiKey);
        $this->assertSame('https://api.openai.com', $resolved->baseUrl);
        $this->assertSame('gpt-5.2', $resolved->model);
        $this->assertSame(8192, $resolved->maxTokens);
        $this->assertTrue($settings->isOauthBearer());
        $this->assertSame(['X-Anthropic-Only' => 'yes'], $settings->getHeaders());
    }
}
