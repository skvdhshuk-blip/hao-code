<?php

namespace Tests\Unit;

use HaoCode\Sdk\Sandbox\SandboxRuntime;
use PHPUnit\Framework\TestCase;

class AgentRunLeaseTest extends TestCase
{
    public function test_config_from_lease_requires_resolved_sandbox_id_and_redacts_secrets(): void
    {
        $lease = [
            'version' => 1,
            'provider' => 'agentrun',
            'identity' => [
                'sandbox_id' => 'sbx-real-123',
                'remote_cwd' => '/workspace',
            ],
            'mode' => 'filesystem',
            'remote_cwd' => '/workspace',
            'sync' => 'none',
            'cleanup' => 'never',
            'options' => [
                'sandboxId' => 'sbx-real-123',
                'apiKey' => 'SECRET-KEY-MUST-NOT-SURVIVE',
                'templateName' => 'should-be-dropped',
                'region' => 'cn-hangzhou',
            ],
        ];

        $redacted = (new \ReflectionClass(SandboxRuntime::class))
            ->getMethod('redactLeaseSecrets');
        $redacted->setAccessible(true);
        $clean = $redacted->invoke(null, $lease);
        $this->assertArrayNotHasKey('apiKey', $clean['options']);

        $config = SandboxRuntime::configFromLease($clean);
        $this->assertSame('agentrun', $config->provider);
        $this->assertSame('sbx-real-123', $config->options['sandboxId'] ?? null);
        $this->assertArrayNotHasKey('apiKey', $config->options);
        $this->assertArrayNotHasKey('templateName', $config->options);
    }

    public function test_config_from_lease_rejects_missing_agentrun_identity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sandbox_id/');
        SandboxRuntime::configFromLease([
            'provider' => 'agentrun',
            'identity' => [],
            'options' => ['templateName' => 'only-template'],
        ]);
    }

    public function test_caller_can_tighten_network_policy_on_resume(): void
    {
        $lease = [
            'provider' => 'native',
            'identity' => [
                'root' => sys_get_temp_dir().'/haocode-sandbox-fake',
                'owns_root' => false,
                'remote_cwd' => '/workspace',
            ],
            'mode' => 'full',
            'remote_cwd' => '/workspace',
            'options' => ['network' => 'allow-all', 'engine' => 'seatbelt'],
        ];
        $caller = \HaoCode\Sdk\Sandbox\SandboxConfig::native(
            network: 'blocked',
            root: $lease['identity']['root'],
        );
        $config = SandboxRuntime::configFromLease($lease, $caller);
        $this->assertSame('blocked', $config->options['network'] ?? null);
    }
}
