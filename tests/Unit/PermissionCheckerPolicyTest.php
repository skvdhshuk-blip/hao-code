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
    use PermissionCheckerPolicyTestSetUpConcern;
    use PermissionCheckerPolicyTestTestApprovalRequiredDoesNotShortCircuitExplicitDenyConcern;

    private string $tmpDir;

    private ToolUseContext $context;

    private const ENV_DENY_BLOCK = <<<'YAML'
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT

YAML;

    // ─── allow_auto: true → short-circuit to allow() ──────────────────────

    // ─── allow_auto: false → falls through to the default ask() ───────────

    // ─── risk=high → ApprovalRequired → ask() ─────────────────────────────

    // ─── broken policy file → fail-closed deny (the reliable hard-deny path) ──

    // The dedicated broken-file test below covers the same fail-closed
    // contract; kept for the explicit assertion message about load errors.

    // ─── cwd_restriction is now actually enforced ─────────────────────────

    // ─── broken policy file → fail-closed deny ────────────────────────────

    // ─── specificity ordering reaches PermissionChecker ───────────────────

    // ─── chain-operator bypass regression (chatgpt second-review P0) ──────
    //
    // Before the fix, PermissionChecker split the command into binary + args
    // and forwarded only the binary to PolicyMatcher. The matcher's chain
    // check then saw no operators, a `composer install*` rule with
    // allow_auto=true matched via fnmatch on the args tail, and the whole
    // check short-circuited to allow() — letting `composer install && curl
    // evil` run without a prompt. The chain check now scans raw_command, and
    // allow_auto no longer short-circuits before the deny/dangerous gates.

    // ─── SensitivePathGuard (chatgpt 3rd review #3) ─────────────────────
    //
    // The guard runs in PermissionChecker before any policy or read-only
    // fast path. Read of ~/.ssh/id_rsa must be denied even though Read is
    // isReadOnly()=true and would otherwise hit the auto-allow branch.
    // Bash `cat ~/.ssh/id_rsa` must also be denied (via the `command`
    // PATH_LIKE_KEY), even though bash commands normally go through the
    // dangerous-pattern path which does not include credential paths.

    // ─── NotApplicable + ApprovalRequired precedence (chatgpt 5.1 + 5.4a) ──

    // ─── tool.checkPermissions() hookup (chatgpt 3rd review #2) ──────────
    //
    // ToolInterface::checkPermissions() used to be dead on the main path.
    // PermissionChecker now calls it after policy hard decisions and before
    // explicit deny rules. Tool deny/ask short-circuits; tool allow falls
    // through so the rest of the pipeline still applies.
}
