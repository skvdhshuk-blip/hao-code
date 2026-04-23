<?php

namespace Tests\Services\Permissions\Policy;

use HaoCode\Services\Permissions\Policy\PolicyDecisionKind;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use HaoCode\Services\Permissions\Policy\PolicyRule;
use PHPUnit\Framework\TestCase;

class PolicyMatcherTest extends TestCase
{
    private function makeRule(array $overrides = []): PolicyRule
    {
        return PolicyRule::fromArray(array_merge([
            'name' => 'test-rule',
            'tool' => 'Bash',
            'cmd' => 'git',
            'risk' => 'normal',
            'allow_chain' => false,
            'allow_auto' => false,
            'env_deny' => ['LD_PRELOAD', 'DYLD_INSERT_LIBRARIES', 'DYLD_LIBRARY_PATH', 'PYTHONPATH', 'NODE_OPTIONS', 'PERL5OPT'],
        ], $overrides));
    }

    private function matcher(array $rules): PolicyMatcher
    {
        return new PolicyMatcher($rules);
    }

    // Test point 1a: match by tool+cmd exact
    public function test_matches_tool_and_cmd(): void
    {
        $m = $this->matcher([$this->makeRule()]);
        $result = $m->match('Bash', 'git');

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind);
    }

    // Test point 1b: args regex mode
    public function test_args_regex_match(): void
    {
        $rule = $this->makeRule(['args_match' => ['/push.*origin/']]);
        $m = $this->matcher([$rule]);

        $allow = $m->match('Bash', 'git', ['args' => 'push origin main']);
        $deny = $m->match('Bash', 'git', ['args' => 'pull origin main']);

        $this->assertSame(PolicyDecisionKind::Allow, $allow->kind);
        $this->assertSame(PolicyDecisionKind::Deny, $deny->kind);
    }

    // Test point 1c: args exact mode
    public function test_args_exact_match(): void
    {
        $rule = $this->makeRule(['args_match' => ['status']]);
        $m = $this->matcher([$rule]);

        $allow = $m->match('Bash', 'git', ['args' => 'status']);
        $deny = $m->match('Bash', 'git', ['args' => 'status --short']);

        $this->assertSame(PolicyDecisionKind::Allow, $allow->kind);
        $this->assertSame(PolicyDecisionKind::Deny, $deny->kind);
    }

    // Test point 1d: args wildcard mode
    public function test_args_wildcard_match(): void
    {
        $rule = $this->makeRule(['args_match' => ['status*']]);
        $m = $this->matcher([$rule]);

        $allow = $m->match('Bash', 'git', ['args' => 'status --short']);
        $deny = $m->match('Bash', 'git', ['args' => 'push origin']);

        $this->assertSame(PolicyDecisionKind::Allow, $allow->kind);
        $this->assertSame(PolicyDecisionKind::Deny, $deny->kind);
    }

    // Test point 2: command chain default deny, allow_chain: true permits
    public function test_chain_default_deny(): void
    {
        $rule = $this->makeRule(['allow_chain' => false]);
        $m = $this->matcher([$rule]);

        $result = $m->match('Bash', 'git && echo', []);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('chain', $result->reason);
    }

    public function test_chain_operators_all_denied_by_default(): void
    {
        $rule = $this->makeRule(['allow_chain' => false]);
        $m = $this->matcher([$rule]);

        foreach (['git | grep foo', 'git ; ls', 'git $(whoami)', 'git `pwd`', 'git || true'] as $cmd) {
            $result = $m->match('Bash', $cmd);
            $this->assertSame(PolicyDecisionKind::Deny, $result->kind, "Expected deny for: $cmd");
        }
    }

    public function test_chain_allowed_when_allow_chain_true(): void
    {
        $rule = $this->makeRule(['allow_chain' => true]);
        $m = $this->matcher([$rule]);

        $result = $m->match('Bash', 'git && echo done');

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind);
    }

    // Test point 3: env_deny hit → deny
    public function test_env_deny_hit_returns_deny(): void
    {
        $rule = $this->makeRule();
        $m = $this->matcher([$rule]);

        $result = $m->match('Bash', 'git', ['env' => ['LD_PRELOAD' => '/evil.so']]);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('LD_PRELOAD', $result->reason);
    }

    public function test_env_deny_default_entries_always_applied(): void
    {
        // Rule with no env_deny still blocks default entries
        $rule = PolicyRule::fromArray([
            'name' => 'no-env-deny',
            'tool' => 'Bash',
            'cmd' => 'ls',
        ]);
        $m = $this->matcher([$rule]);

        foreach (['LD_PRELOAD', 'DYLD_INSERT_LIBRARIES', 'DYLD_LIBRARY_PATH', 'PYTHONPATH', 'NODE_OPTIONS', 'PERL5OPT'] as $var) {
            $result = $m->match('Bash', 'ls', ['env' => [$var => 'val']]);
            $this->assertSame(PolicyDecisionKind::Deny, $result->kind, "Expected deny for env var: $var");
        }
    }

    // Test point 4: cwd_restriction out of bounds → deny
    public function test_cwd_outside_restriction_denied(): void
    {
        $rule = $this->makeRule(['cwd_restriction' => '/var/www']);
        $m = $this->matcher([$rule]);

        $result = $m->match('Bash', 'git', ['cwd' => '/tmp/evil']);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('outside restriction', $result->reason);
    }

    public function test_cwd_inside_restriction_allowed(): void
    {
        $rule = $this->makeRule(['cwd_restriction' => '/var/www']);
        $m = $this->matcher([$rule]);

        // Use a path under /tmp which realpath can resolve (or just /var/www itself)
        $result = $m->match('Bash', 'git', ['cwd' => '/var/www/myapp']);

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind);
    }

    // Test point 5: risk=high → approval_required + no cache
    public function test_high_risk_returns_approval_required(): void
    {
        $rule = $this->makeRule(['risk' => 'high']);
        $m = $this->matcher([$rule]);

        $result = $m->match('Bash', 'git');

        $this->assertSame(PolicyDecisionKind::ApprovalRequired, $result->kind);
        $this->assertSame('test-rule', $result->ruleName);
        $this->assertFalse($result->cacheDecision);
        $this->assertStringContainsString('test-rule', $result->reason);
    }

    // No rule matched → fail-closed deny
    public function test_no_matching_rule_denies(): void
    {
        $m = $this->matcher([]);

        $result = $m->match('Bash', 'unknown-cmd');

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('No matching policy rule', $result->reason);
    }

    // stdin_size limit
    public function test_stdin_size_exceeds_limit_denied(): void
    {
        $m = $this->matcher([$this->makeRule()]);

        $result = $m->match('Bash', 'git', ['stdin_size' => 1048577]);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('stdin exceeds', $result->reason);
    }

    public function test_stdin_size_at_limit_ok(): void
    {
        $m = $this->matcher([$this->makeRule()]);

        $result = $m->match('Bash', 'git', ['stdin_size' => 1048576]);

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind);
    }
}
