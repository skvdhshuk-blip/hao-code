<?php

namespace Tests\Services\Permissions;

use HaoCode\Services\Permissions\Policy\PolicyDecisionKind;
use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end Policy DSL coverage: the bundled policies/*.yml flow through the
 * real PolicyLoader (which runs all 5 startup validators) into PolicyMatcher.
 *
 * This is the regression that previously failed — the loader rejected the
 * bundled files because `git status` (risk=normal) and `git push --force`
 * (risk=high) shared `tool=Bash, cmd=git`. After the args_signature fix to
 * validateNoConflicts(), both bundled files load cleanly and the matcher's
 * specificity sort lets targeted rules win over the `cmd: "*"` catch-all.
 */
class PolicyIntegrationTest extends TestCase
{
    private function buildMatcher(): PolicyMatcher
    {
        $base = dirname(__DIR__, 3).'/policies';
        $loader = new PolicyLoader;
        $rules = [];
        foreach (['default.yml', 'laravel-dev.yml'] as $file) {
            // Real loader path — fails the test outright if either bundled
            // policy file regresses and cannot pass the validators.
            $rules = array_merge($rules, $loader->load($base.'/'.$file));
        }

        return new PolicyMatcher($rules);
    }

    public function test_bundled_policies_load_through_real_loader(): void
    {
        // Guard: if this throws, the bundled YAML regressed past the loader's
        // validators. The individual match tests below all depend on this.
        $matcher = $this->buildMatcher();
        $this->assertNotEmpty($matcher->getRules());
    }

    public function test_policy_allows_whitelisted_bash(): void
    {
        // default.yml has bash-composer-install: allow_auto=true → AllowAuto.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'composer', ['args' => 'install --no-interaction']);

        $this->assertSame(PolicyDecisionKind::AllowAuto, $result->kind, $result->reason ?? '');
        $this->assertSame('bash-composer-install', $result->ruleName);
    }

    public function test_policy_blocks_command_chain(): void
    {
        // bash-read-only rule (cmd=*) has allow_chain=false, so "&&" must be
        // denied. The matcher's checkChain runs against the full command string.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'rm -rf / && echo ok', []);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsString('chain', strtolower($result->reason ?? ''));
    }

    public function test_policy_blocks_env_deny(): void
    {
        // LD_PRELOAD is in REQUIRED_ENV_DENY and every rule's env_deny list.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'ls', [
            'args' => '',
            'env' => ['LD_PRELOAD' => 'x.so'],
        ]);

        $this->assertSame(PolicyDecisionKind::Deny, $result->kind);
        $this->assertStringContainsStringIgnoringCase('ld_preload', $result->reason ?? '');
    }

    public function test_specific_rule_beats_wildcard_for_git_status(): void
    {
        // default.yml declares bash-read-only (cmd=*) FIRST, then bash-git-status.
        // Without specificity sorting, `git status` would hit the wildcard and
        // never reach the targeted rule.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'git', ['args' => 'status']);

        $this->assertSame('bash-git-status', $result->ruleName);
        $this->assertSame(PolicyDecisionKind::AllowAuto, $result->kind, 'git status rule has allow_auto=true');
    }

    public function test_git_force_push_routes_to_high_risk_approval(): void
    {
        // bash-git-push-force is risk=high. Specificity sort must surface it
        // ahead of the bash-read-only wildcard, and the matcher must return
        // ApprovalRequired (never Allow/AllowAuto) regardless of allow_auto.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'git', ['args' => 'push --force origin main']);

        $this->assertSame(PolicyDecisionKind::ApprovalRequired, $result->kind);
        $this->assertSame('bash-git-push-force', $result->ruleName);
    }

    public function test_uncovered_command_falls_back_to_wildcard(): void
    {
        // `ls` is not covered by any specific rule in the bundled policies.
        // The bash-read-only wildcard (cmd=*) must still catch it.
        $matcher = $this->buildMatcher();
        $result = $matcher->match('Bash', 'ls');

        $this->assertSame(PolicyDecisionKind::Allow, $result->kind, 'wildcard rule has allow_auto=false');
        $this->assertSame('bash-read-only', $result->ruleName);
    }
}
