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
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly DenialTracker $denialTracker,
    ) {}

    public function check(ToolInterface $tool, array $input, ToolUseContext $context): PermissionDecision
    {
        $mode = $this->settings->getPermissionMode();

        // Bypass mode: allow everything
        if ($mode === PermissionMode::BypassPermissions) {
            return PermissionDecision::allow();
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

        // Policy layer: check DSL rules before deny (fail-closed by default)
        $policyDecision = $this->checkPolicy($tool, $input);
        if ($policyDecision !== null) {
            return $policyDecision;
        }

        // Check explicit deny rules first — deny always takes precedence
        foreach ($this->settings->getDenyRules() as $rule) {
            if ($this->matchesRule($rule, $tool, $input)) {
                $this->denialTracker->record($tool->name(), $this->summarizeInput($input), "rule: {$rule}");

                return PermissionDecision::deny("Denied by rule: {$rule}");
            }
        }

        // Check explicit allow rules
        foreach ($this->settings->getAllowRules() as $rule) {
            if ($this->matchesRule($rule, $tool, $input)) {
                return PermissionDecision::allow();
            }
        }

        // Check Bash-specific dangerous patterns
        if ($tool->name() === 'Bash' && isset($input['command'])) {
            $command = $input['command'];

            // Check shell obfuscation
            $obfuscation = DangerousPatterns::checkObfuscation($command);
            if ($obfuscation !== null) {
                return PermissionDecision::ask($obfuscation);
            }

            // Check dangerous patterns
            foreach (DangerousPatterns::getBashDangerPatterns() as $pattern => $message) {
                if (preg_match($pattern, $command)) {
                    return PermissionDecision::ask($message);
                }
            }

            // Check code exec commands
            if (DangerousPatterns::isCodeExecCommand($command)) {
                return PermissionDecision::ask('Command executes code — requires approval.');
            }
        }

        // Read-only tools auto-approve
        if ($tool->isReadOnly($input)) {
            return PermissionDecision::allow();
        }

        // Default: needs user approval
        return PermissionDecision::ask();
    }

    private function checkPolicy(ToolInterface $tool, array $input): ?PermissionDecision
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
        $cmd = $input['command'] ?? '';
        // Extract the first token as the command binary, pass rest as args
        $parts = preg_split('/\s+/', trim($cmd), 2);
        $binary = $parts[0] ?? $cmd;
        $args = $parts[1] ?? '';

        $decision = $matcher->match($tool->name(), $binary, ['args' => $args]);

        return match ($decision->kind) {
            PolicyDecisionKind::Allow => null, // let normal flow continue
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
