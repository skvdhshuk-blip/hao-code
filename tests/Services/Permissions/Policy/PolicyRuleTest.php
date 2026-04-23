<?php

namespace Tests\Services\Permissions\Policy;

use HaoCode\Services\Permissions\Policy\PolicyRule;
use PHPUnit\Framework\TestCase;

class PolicyRuleTest extends TestCase
{
    public function test_from_array_with_all_fields(): void
    {
        $rule = PolicyRule::fromArray([
            'name' => 'test-rule',
            'tool' => 'Bash',
            'cmd' => 'git',
            'args_match' => ['/push.*/'],
            'env_allow' => ['HOME'],
            'env_deny' => ['LD_PRELOAD', 'DYLD_INSERT_LIBRARIES', 'DYLD_LIBRARY_PATH', 'PYTHONPATH', 'NODE_OPTIONS', 'PERL5OPT'],
            'risk' => 'high',
            'allow_chain' => true,
            'approval_ttl' => 300,
            'cwd_restriction' => '/var/www',
            'note' => 'Test note',
            'allow_auto' => false,
        ]);

        $this->assertSame('test-rule', $rule->name);
        $this->assertSame('Bash', $rule->tool);
        $this->assertSame('git', $rule->cmd);
        $this->assertSame(['/push.*/'], $rule->argsMatch);
        $this->assertSame(['HOME'], $rule->envAllow);
        $this->assertSame('high', $rule->risk);
        $this->assertTrue($rule->allowChain);
        $this->assertSame(300, $rule->approvalTtl);
        $this->assertSame('/var/www', $rule->cwdRestriction);
        $this->assertSame('Test note', $rule->note);
        $this->assertFalse($rule->allowAuto);
    }

    public function test_from_array_with_defaults(): void
    {
        $rule = PolicyRule::fromArray([
            'name' => 'minimal',
            'tool' => 'Bash',
            'cmd' => 'ls',
        ]);

        $this->assertSame([], $rule->argsMatch);
        $this->assertSame([], $rule->envAllow);
        $this->assertSame([], $rule->envDeny);
        $this->assertSame('normal', $rule->risk);
        $this->assertFalse($rule->allowChain);
        $this->assertSame(0, $rule->approvalTtl);
        $this->assertNull($rule->cwdRestriction);
        $this->assertNull($rule->note);
        $this->assertFalse($rule->allowAuto);
    }

    public function test_is_high_risk(): void
    {
        $high = PolicyRule::fromArray(['name' => 'h', 'tool' => 'Bash', 'cmd' => 'x', 'risk' => 'high']);
        $normal = PolicyRule::fromArray(['name' => 'n', 'tool' => 'Bash', 'cmd' => 'x']);

        $this->assertTrue($high->isHighRisk());
        $this->assertFalse($normal->isHighRisk());
    }
}
