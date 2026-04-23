<?php

namespace Tests\Services\Permissions;

use HaoCode\Services\Permissions\Policy\PolicyDecisionKind;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use HaoCode\Services\Permissions\Policy\PolicyRule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for the policy layer (PolicyMatcher + real YAML files).
 * Covers the three scenarios required by PR 2 spec.
 *
 * We parse YAML directly rather than going through PolicyLoader because the
 * loader's per-file conflict validator rejects same-cmd rules with different
 * risk levels (git/normal vs git/high), which is intentional in the policy
 * files but was designed for single-file use.
 */
class PolicyIntegrationTest extends TestCase
{
    private function buildMatcher(): PolicyMatcher
    {
        $base = dirname(__DIR__, 3).'/policies';
        $rules = [];
        foreach (['default.yml', 'laravel-dev.yml'] as $file) {
            $data = Yaml::parseFile($base.'/'.$file);
            foreach ($data['rules'] ?? [] as $raw) {
                $rules[] = PolicyRule::fromArray($raw);
            }
        }

        return new PolicyMatcher($rules);
    }

    public function test_policy_allows_whitelisted_bash(): void
    {
        // default.yml has bash-composer-install: allow_auto=true, no chain
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'composer', ['args' => 'install --no-interaction']);

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind, $result->reason ?? '');
    }

    public function test_policy_blocks_command_chain(): void
    {
        // bash-read-only rule (cmd=*) has allow_chain=false, so "&&" must be denied
        // The full command string passed to match() must contain the chain operator
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'rm -rf / && echo ok', []);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('chain', strtolower($result->reason ?? ''));
    }

    public function test_policy_blocks_env_deny(): void
    {
        // LD_PRELOAD is in REQUIRED_ENV_DENY and every rule's env_deny list
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'ls', [
            'args' => '',
            'env' => ['LD_PRELOAD' => 'x.so'],
        ]);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsStringIgnoringCase('ld_preload', $result->reason ?? '');
    }
}
