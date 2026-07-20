<?php

namespace HaoCode\Services\Permissions;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Permissions\Policy\PolicyDecisionKind;
use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;

class PermissionChecker
{
    private bool $nonInteractive = false;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly DenialTracker $denialTracker,
    ) {}

    /**
     * Enable non-interactive mode: any ask() decision is downgraded to deny().
     * Must be set before running daemon or any unattended process — prevents
     * permission prompts from blocking an unattended process indefinitely.
     */
    public function nonInteractive(bool $flag = true): void
    {
        $this->nonInteractive = $flag;
    }

    public function isNonInteractive(): bool
    {
        return $this->nonInteractive;
    }

    public function check(ToolInterface $tool, array $input, ToolUseContext $context): PermissionDecision
    {
        $mode = $this->settings->getPermissionMode();

        // Bypass mode: allow everything
        if ($mode === PermissionMode::BypassPermissions) {
            return PermissionDecision::allow();
        }

        // Sensitive-path hard red line (chatgpt 3rd review #3): applies to
        // every tool's path-like inputs regardless of permission mode (except
        // BypassPermissions above). Read/Grep/Glob of ~/.ssh/id_rsa, .env,
        // ~/.aws/credentials etc. is denied before any policy or tool-specific
        // check can allow it. This used to be enforced only inside
        // HitlPolicy::classifyAction, which never runs for tools that reached
        // the isReadOnly → allow fast path — so the red line was effectively
        // bypassable. Also covers Bash commands like `cat ~/.ssh/id_rsa` via
        // the `command` key in PATH_LIKE_KEYS.
        $sensitiveHit = SensitivePathGuard::check($tool->name(), $input);
        if ($sensitiveHit !== null) {
            $this->denialTracker->record(
                $tool->name(),
                $this->summarizeInput($input),
                "sensitive-path: {$sensitiveHit}",
            );

            return PermissionDecision::deny("Blocked by sensitive-path guard: {$sensitiveHit}");
        }

        // Plan mode: deny write operations
        if ($mode === PermissionMode::Plan && ! $tool->isReadOnly($input)) {
            $this->denialTracker->record($tool->name(), $this->summarizeInput($input), 'plan mode');

            return PermissionDecision::deny('Write operations not allowed in plan mode');
        }

        // Accept edits: auto-approve file tools
        if ($mode === PermissionMode::AcceptEdits) {
            if (in_array($tool->name(), ['Read', 'Glob', 'Grep', 'Edit', 'Write'])) {
                return PermissionDecision::allow();
            }
        }

        // Decision precedence (deny always wins over allow_auto):
        //   1. Policy deny / approval-required       (hard, short-circuits)
        //   2. Explicit deny rules                    (hard, short-circuits)
        //   3. Bash dangerous patterns / obfuscation  (hard, short-circuits to ask)
        //   4. Policy allow_auto                      (soft, runs only if nothing above blocked)
        //   5. Explicit allow rules
        //   6. Read-only tools
        //   7. Default ask
        //
        // allow_auto previously short-circuited to allow() right after checkPolicy,
        // which let `composer install && curl evil` bypass the deny rules and
        // dangerous-pattern checks (the chain operator hides in args after the
        // matcher sees only the binary). allow_auto now defers until every hard
        // gate below has cleared.
        $policyAutoAllow = false;
        $policyDecision = $this->checkPolicy($tool, $input, $context, $policyAutoAllow);
        if ($policyDecision !== null) {
            return $this->maybeDowngradeAsk($policyDecision);
        }

        // Tool-specific permission gate (chatgpt 3rd review #2):
        // ToolInterface::checkPermissions() was effectively dead on the main
        // path — only the MCP server-side ToolAdapter called it. MCP dynamic
        // tools use it to force ask() (overriding the readOnlyHint annotation
        // that would otherwise let PermissionChecker auto-allow). A tool deny
        // or ask short-circuits here; a tool allow falls through so explicit
        // deny rules and dangerous-pattern checks still apply below.
        $toolDecision = $tool->checkPermissions($input, $context);
        if (! $toolDecision->allowed) {
            return $this->maybeDowngradeAsk($toolDecision);
        }

        // Check explicit deny rules first — deny always takes precedence
        foreach ($this->settings->getDenyRules() as $rule) {
            if ($this->matchesRule($rule, $tool, $input)) {
                $this->denialTracker->record($tool->name(), $this->summarizeInput($input), "rule: {$rule}");

                return PermissionDecision::deny("Denied by rule: {$rule}");
            }
        }

        // Check Bash-specific dangerous patterns
        if ($tool->name() === 'Bash' && isset($input['command'])) {
            $command = $input['command'];

            // Check shell obfuscation
            $obfuscation = DangerousPatterns::checkObfuscation($command);
            if ($obfuscation !== null) {
                return $this->maybeDowngradeAsk(PermissionDecision::ask($obfuscation));
            }

            // Check dangerous patterns
            foreach (DangerousPatterns::getBashDangerPatterns() as $pattern => $message) {
                if (preg_match($pattern, $command)) {
                    return $this->maybeDowngradeAsk(PermissionDecision::ask($message));
                }
            }

            // Check code exec commands
            if (DangerousPatterns::isCodeExecCommand($command)) {
                return $this->maybeDowngradeAsk(PermissionDecision::ask('Command executes code — requires approval.'));
            }
        }

        // Policy allow_auto — only honored once deny rules and dangerous patterns
        // have cleared. Bypasses the human-approval prompt but never overrides
        // an explicit deny or a dangerous-command classification.
        if ($policyAutoAllow) {
            return PermissionDecision::allow();
        }

        // Check explicit allow rules
        foreach ($this->settings->getAllowRules() as $rule) {
            if ($this->matchesRule($rule, $tool, $input)) {
                return PermissionDecision::allow();
            }
        }

        // Read-only tools auto-approve
        if ($tool->isReadOnly($input)) {
            return PermissionDecision::allow();
        }

        // Default: needs user approval (downgraded to deny in non-interactive mode)
        return $this->maybeDowngradeAsk(PermissionDecision::ask());
    }

    /**
     * In non-interactive mode, convert ask() to deny() so the daemon never
     * blocks waiting for a prompt that will never arrive.
     */
    private function maybeDowngradeAsk(PermissionDecision $decision): PermissionDecision
    {
        if ($this->nonInteractive && $decision->needsPrompt) {
            return PermissionDecision::deny('Non-interactive mode: approval prompt suppressed and treated as deny');
        }

        return $decision;
    }

    /**
     * Run the Policy DSL against the tool call.
     *
     * Returns a non-null PermissionDecision for HARD policy outcomes
     * (Deny, ApprovalRequired, broken-policy fail-closed) — these
     * short-circuit the rest of {@see check()}.
     *
     * For SOFT outcomes (plain Allow, or AllowAuto), returns null so the
     * caller can still apply explicit deny rules and Bash dangerous-pattern
     * checks. When the policy matched an `allow_auto: true` rule, the
     * by-reference `$policyAutoAllow` flag is set so the caller can honor it
     * as a deferred allow AFTER every hard gate has cleared. This closes the
     * chain-operator bypass where `composer install && curl evil` would
     * otherwise short-circuit to allow() and skip the deny / dangerous
     * pipeline entirely.
     *
     * @param-out bool $policyAutoAllow
     */
    private function checkPolicy(ToolInterface $tool, array $input, ToolUseContext $context, bool &$policyAutoAllow = false): ?PermissionDecision
    {
        $policyFiles = $this->settings->getPolicyFiles();
        if (empty($policyFiles)) {
            return null;
        }

        $loader = new PolicyLoader;
        $rules = [];
        foreach ($policyFiles as $file) {
            try {
                $rules = array_merge($rules, $loader->load($file));
            } catch (\Throwable $e) {
                // Fail-closed: a broken policy file must not silently allow all actions
                return PermissionDecision::deny('Policy file could not be loaded: '.$e->getMessage());
            }
        }

        if (empty($rules)) {
            return null;
        }

        $matcher = new PolicyMatcher($rules);
        $rawCommand = $input['command'] ?? '';
        // Extract the first token as the command binary, pass rest as args.
        // Forward the raw (unsplit) command as raw_command so the matcher's
        // chain-operator check sees `&&`, `||`, `$()` etc. hidden inside the
        // args — otherwise a rule with allow_chain=false is bypassable by
        // hiding operators in the second token onward.
        $parts = preg_split('/\s+/', trim($rawCommand), 2);
        $binary = $parts[0] ?? $rawCommand;
        $args = $parts[1] ?? '';

        // Forward cwd so rules with cwd_restriction are actually enforced on
        // the PermissionChecker path. env and stdin_size are intentionally not
        // forwarded: the PHP process env is not the spawned command's env, and
        // stdin is not visible at permission-check time. Those checks only
        // apply on the JobStore path that constructs the command's real env.
        $decision = $matcher->match($tool->name(), $binary, [
            'args' => $args,
            'cwd' => $context->workingDirectory,
            'raw_command' => $rawCommand,
        ]);

        // AllowAuto is a SOFT outcome: flag it for the caller and fall through
        // (return null) so deny rules and dangerous-pattern checks still run.
        // The caller honors $policyAutoAllow only after every hard gate clears.
        if ($decision->kind === PolicyDecisionKind::AllowAuto) {
            $policyAutoAllow = true;

            return null;
        }

        return match ($decision->kind) {
            PolicyDecisionKind::Allow => null, // soft: let normal flow continue
            PolicyDecisionKind::Deny => PermissionDecision::deny($decision->reason ?? 'Denied by policy'),
            PolicyDecisionKind::ApprovalRequired => PermissionDecision::ask($decision->reason ?? 'Policy requires approval'),
        };
    }

    private function matchesRule(string $rule, ToolInterface $tool, array $input): bool
    {
        if (! preg_match('/^(\w+)(?:\((.+)\))?$/', $rule, $m)) {
            return false;
        }

        $toolName = $m[1];
        if ($toolName !== $tool->name()) {
            return false;
        }

        if (isset($m[2])) {
            $pattern = $m[2];
            $matchField = match ($toolName) {
                'Bash' => $input['command'] ?? '',
                'Read', 'Edit', 'Write' => $input['file_path'] ?? '',
                'Glob' => $input['pattern'] ?? '',
                'Grep' => $input['pattern'] ?? '',
                default => (string) reset($input),
            };

            if (is_string($matchField)) {
                if (str_ends_with($pattern, ':*')) {
                    $prefix = substr($pattern, 0, -2);

                    // Require exact match or a space after the prefix to avoid
                    // partial-word false positives (e.g. "git:*" must not match "gitlint")
                    return $matchField === $prefix
                        || str_starts_with($matchField, $prefix.' ');
                }
                if (str_contains($pattern, '*')) {
                    return fnmatch($pattern, $matchField);
                }

                return $matchField === $pattern;
            }
        }

        return true;
    }

    private function summarizeInput(array $input): string
    {
        return $input['command'] ?? $input['file_path'] ?? $input['pattern'] ?? json_encode($input);
    }
}
