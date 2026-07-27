<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\Policy\PolicyLoader;
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
}
