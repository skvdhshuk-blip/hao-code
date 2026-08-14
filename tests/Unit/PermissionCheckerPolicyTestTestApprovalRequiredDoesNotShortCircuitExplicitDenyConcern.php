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

trait PermissionCheckerPolicyTestTestApprovalRequiredDoesNotShortCircuitExplicitDenyConcern
{

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
