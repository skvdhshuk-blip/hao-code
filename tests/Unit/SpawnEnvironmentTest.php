<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Support\Runtime\SpawnEnvironment;
use PHPUnit\Framework\TestCase;

class SpawnEnvironmentTest extends TestCase
{
    public function test_required_env_deny_keys_are_stripped(): void
    {
        $previous = [];
        foreach (PolicyLoader::REQUIRED_ENV_DENY as $key) {
            $previous[$key] = getenv($key);
            putenv($key.'=injection-payload');
        }

        try {
            $env = SpawnEnvironment::build();
            foreach (PolicyLoader::REQUIRED_ENV_DENY as $key) {
                $this->assertArrayNotHasKey($key, $env, "{$key} must be stripped");
            }
            $this->assertNotSame('', (string) ($env['TERM'] ?? ''));
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$value);
                }
            }
        }
    }

    public function test_extra_deny_is_applied(): void
    {
        putenv('HAOCODE_TEST_DENY=secret');
        try {
            $env = SpawnEnvironment::build(['HAOCODE_TEST_DENY']);
            $this->assertArrayNotHasKey('HAOCODE_TEST_DENY', $env);
        } finally {
            putenv('HAOCODE_TEST_DENY');
        }
    }

    public function test_matching_policy_custom_env_deny_is_applied(): void
    {
        $policy = tempnam(sys_get_temp_dir(), 'haocode-policy-');
        $this->assertIsString($policy);
        file_put_contents($policy, <<<'YAML'
rules:
  - name: bash-env-test
    tool: Bash
    cmd: env
    env_deny:
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT
      - HAOCODE_CUSTOM_DENY
YAML);
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPolicyFiles')->willReturn([$policy]);
        putenv('HAOCODE_CUSTOM_DENY=secret');

        try {
            $env = SpawnEnvironment::forCommand(
                $settings,
                'Bash',
                'env',
                sys_get_temp_dir(),
            );
            $this->assertArrayNotHasKey('HAOCODE_CUSTOM_DENY', $env);
        } finally {
            putenv('HAOCODE_CUSTOM_DENY');
            @unlink($policy);
        }
    }

    public function test_scrub_command_unsets_only_valid_environment_names(): void
    {
        $command = SpawnEnvironment::scrubCommand('env', [
            'HAOCODE_CUSTOM_DENY',
            'invalid-name',
        ]);

        $this->assertStringStartsWith('unset HAOCODE_CUSTOM_DENY;', $command);
        $this->assertStringNotContainsString('invalid-name', $command);
    }
}
