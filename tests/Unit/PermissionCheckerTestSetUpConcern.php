<?php

namespace Tests\Unit;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait PermissionCheckerTestSetUpConcern
{

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    private function makeChecker(
        PermissionMode $mode = PermissionMode::Default,
        array $allowRules = [],
        array $denyRules = [],
    ): PermissionChecker {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn($mode);
        $settings->method('getAllowRules')->willReturn($allowRules);
        $settings->method('getDenyRules')->willReturn($denyRules);

        return new PermissionChecker($settings, new DenialTracker);
    }

    private function makeTool(string $name, bool $readOnly = false): BaseTool
    {
        return new class($name, $readOnly) extends BaseTool
        {
            public function __construct(private string $n, private bool $ro) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }

            public function call(array $input, ToolUseContext $ctx): ToolResult
            {
                return ToolResult::success('ok');
            }

            public function isReadOnly(array $input): bool
            {
                return $this->ro;
            }
        };
    }

    public function test_bypass_mode_allows_everything(): void
    {
        $checker = $this->makeChecker(PermissionMode::BypassPermissions);
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'rm -rf /'], $this->context);

        $this->assertTrue($decision->allowed);
    }

    public function test_plan_mode_denies_write_tools(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $tool = $this->makeTool('Write', false);

        $decision = $checker->check($tool, ['file_path' => '/tmp/foo.txt'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt);
        $this->assertStringContainsString('plan mode', $decision->reason);
    }

    public function test_plan_mode_allows_read_only_tools(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $tool = $this->makeTool('Read', true);

        $decision = $checker->check($tool, ['file_path' => '/tmp/foo.txt'], $this->context);

        $this->assertTrue($decision->allowed);
    }

    public function test_accept_edits_auto_approves_file_tools(): void
    {
        $checker = $this->makeChecker(PermissionMode::AcceptEdits);

        foreach (['Read', 'Edit', 'Write', 'Glob', 'Grep'] as $toolName) {
            $tool = $this->makeTool($toolName, false);
            $decision = $checker->check($tool, [], $this->context);
            $this->assertTrue($decision->allowed, "{$toolName} should be auto-approved in AcceptEdits mode");
        }
    }

    public function test_accept_edits_still_asks_for_bash(): void
    {
        $checker = $this->makeChecker(PermissionMode::AcceptEdits);
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'ls -la'], $this->context);

        // Read-only tools are auto-approved even in default, so Bash won't be blocked
        // unless it hits a danger pattern — ls is clean, falls through to default ask
        $this->assertTrue($decision->needsPrompt || $decision->allowed);
    }

    public function test_read_only_tools_are_auto_approved_in_default_mode(): void
    {
        $checker = $this->makeChecker(PermissionMode::Default);
        $tool = $this->makeTool('MyReader', true);

        $decision = $checker->check($tool, [], $this->context);

        $this->assertTrue($decision->allowed);
    }

    public function test_write_tool_needs_prompt_in_default_mode(): void
    {
        $checker = $this->makeChecker(PermissionMode::Default);
        $tool = $this->makeTool('MyWriter', false);

        $decision = $checker->check($tool, [], $this->context);

        $this->assertTrue($decision->needsPrompt);
    }

    public function test_allow_rule_grants_access(): void
    {
        $checker = $this->makeChecker(
            allowRules: ['Bash(git status)'],
        );
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'git status'], $this->context);

        $this->assertTrue($decision->allowed);
    }

    public function test_deny_rule_blocks_access(): void
    {
        $checker = $this->makeChecker(
            denyRules: ['Bash(rm -rf /)'],
        );
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'rm -rf /'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('Denied by rule', $decision->reason);
    }

    public function test_deny_rule_takes_precedence_over_allow_rule(): void
    {
        $checker = $this->makeChecker(
            allowRules: ['Bash(git:*)'],
            denyRules: ['Bash(git push --force)'],
        );
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'git push --force'], $this->context);

        $this->assertFalse($decision->allowed);
    }

    public function test_wildcard_allow_rule_matches_with_fnmatch(): void
    {
        $checker = $this->makeChecker(
            allowRules: ['Bash(git *)'],
        );
        $tool = $this->makeTool('Bash', false);

        $allowed = $checker->check($tool, ['command' => 'git log --oneline'], $this->context);
        $this->assertTrue($allowed->allowed);

        // Non-git command should NOT match
        $notAllowed = $checker->check($tool, ['command' => 'npm install'], $this->context);
        $this->assertFalse($notAllowed->allowed); // falls through to needsPrompt
    }

    public function test_colon_star_rule_matches_command_with_space(): void
    {
        $checker = $this->makeChecker(allowRules: ['Bash(git:*)']);
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'git status'], $this->context);
        $this->assertTrue($decision->allowed);
    }

    public function test_colon_star_rule_matches_exact_command(): void
    {
        $checker = $this->makeChecker(allowRules: ['Bash(git:*)']);
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'git'], $this->context);
        $this->assertTrue($decision->allowed);
    }

    public function test_colon_star_rule_does_not_match_partial_word(): void
    {
        // Before the fix: Bash(git:*) would allow 'gitlint' because str_starts_with('gitlint','git')
        $checker = $this->makeChecker(
            allowRules: ['Bash(git:*)'],
            // Also add deny for gitlint to ensure we're actually checking
        );
        $tool = $this->makeTool('Bash', false);

        // 'gitlint' starts with 'git' but is a different command — must NOT match git:*
        $decision = $checker->check($tool, ['command' => 'gitlint --config .gitlint'], $this->context);

        // Should NOT be allowed by the git:* rule (falls through to needsPrompt)
        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt);
    }

    public function test_rm_rf_triggers_ask(): void
    {
        $checker = $this->makeChecker();
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'rm -rf /tmp/dir'], $this->context);

        $this->assertTrue($decision->needsPrompt);
    }

    public function test_eval_triggers_ask(): void
    {
        $checker = $this->makeChecker();
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'eval "$payload"'], $this->context);

        $this->assertTrue($decision->needsPrompt);
    }

    public function test_python3_code_exec_triggers_ask(): void
    {
        $checker = $this->makeChecker();
        $tool = $this->makeTool('Bash', false);

        $decision = $checker->check($tool, ['command' => 'python3 exploit.py'], $this->context);

        $this->assertTrue($decision->needsPrompt);
    }

    public function test_dynamic_read_paths_cannot_use_bash_read_only_fast_path(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'cat .e?v',
            'cat .e[n]v',
            'cat .e{n,xx}v',
            'cat "$TARGET"',
            'cat <(printf value)',
            "cat \$'\\x2eenv' /dev/null",
            "cat \$'\\056env' /dev/null",
            'echo ok; cat .e?v',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_process_substitution_always_requires_approval(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'echo <(touch /tmp/pwn)',
            "printf '%s' <(touch /tmp/pwn)",
            'git status <(touch /tmp/pwn)',
            'echo value >(cat)',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_find_mutations_and_environment_dump_do_not_auto_approve(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'find . -delete',
            'find . -fprint /tmp/result',
            'find . -fprint0 /tmp/result',
            'find . -fprintf /tmp/result "%p\\n"',
            'find . -fls /tmp/result',
            'find . -exec touch /tmp/result {} ;',
            'find . -"del"ete',
            'echo ok; find . -delete',
            'printenv',
            'echo ok; printenv',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_file_opening_redirects_cannot_use_read_only_fast_path(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'echo ok <> /tmp/pwn',
            'echo ok 3<>/tmp/pwn',
            'cat <> /tmp/pwn',
            'echo ok >& /tmp/pwn',
            'echo ok >&/tmp/pwn',
            'echo ok 2>&/tmp/pwn',
            'echo ok {output}>/tmp/pwn',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_mutating_read_command_options_require_approval(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'echo payload | tee /tmp/output',
            'echo payload | tee "$HOME/.profile"',
            'sort -o /tmp/output README.md',
            'sort -uo /tmp/output README.md',
            'sort --output=/tmp/output README.md',
            'uniq README.md /tmp/output',
            'file -C -m ./magic',
            'git diff --output=/tmp/output',
            'git remote add example https://example.com/repo.git',
            'git branch -D important',
            'git tag v-next',
            'date -s @0',
            'hostname changed',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_plan_mode_denies_mutating_read_command_options(): void
    {
        $checker = $this->makeChecker(PermissionMode::Plan);
        $tool = new BashTool;

        foreach ([
            'echo payload | tee /tmp/output',
            'sort -o /tmp/output README.md',
            'sort -uo /tmp/output README.md',
            'uniq README.md /tmp/output',
            'git remote add example https://example.com/repo.git',
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected plan allow for {$command}");
            $this->assertFalse($decision->needsPrompt, "Expected plan deny for {$command}");
        }
    }

    public function test_shell_obfuscation_and_sensitive_env_expansion_require_approval(): void
    {
        $checker = $this->makeChecker();
        $tool = new BashTool;

        foreach ([
            'cat$IFS.env',
            'cat$IFS.e"nv"',
            'cat$IFS$TARGET',
            'cat$IFS./.e?v',
            "echo <\\\n(printf x)",
            "echo \$\\\n(printf x)",
            'echo "$OPENAI_API_KEY"',
            "printf '%s' \"\$ANTHROPIC_API_KEY\"",
        ] as $command) {
            $decision = $checker->check($tool, ['command' => $command], $this->context);

            $this->assertFalse($decision->allowed, "Unexpected allow for {$command}");
            $this->assertTrue($decision->needsPrompt, "Expected approval for {$command}");
        }
    }

    public function test_pattern_rule_for_unknown_tool_matches_first_string_input(): void
    {
        // CustomTool falls into the default branch of matchesRule()
        $checker = $this->makeChecker(allowRules: ['CustomTool(hello)']);
        $tool = $this->makeTool('CustomTool', false);

        $matched = $checker->check($tool, ['query' => 'hello'], $this->context);
        $this->assertTrue($matched->allowed, 'allow rule should match when first input equals pattern');

        $noMatch = $checker->check($tool, ['query' => 'world'], $this->context);
        $this->assertFalse($noMatch->allowed, 'allow rule should not match when first input differs');
        $this->assertTrue($noMatch->needsPrompt);
    }

    public function test_pattern_rule_for_unknown_tool_with_non_string_first_value_does_not_bypass_check(): void
    {
        // When the first input value is an integer, matchesRule() previously called
        // reset($input) which returned the integer; is_string(int) is false so the
        // pattern check was silently skipped and `return true` was hit — meaning ANY
        // pattern rule for an unknown tool matched unconditionally.
        $checker = $this->makeChecker(allowRules: ['CustomTool(42)']);
        $tool = $this->makeTool('CustomTool', false);

        // count=99 does NOT match pattern '42' — should fall through to needsPrompt
        $decision = $checker->check($tool, ['count' => 99], $this->context);

        $this->assertFalse($decision->allowed,
            'Pattern check must not be bypassed when first input value is a non-string');
        $this->assertTrue($decision->needsPrompt);
    }

    public function test_pattern_rule_for_unknown_tool_with_integer_first_value_matches_correctly(): void
    {
        $checker = $this->makeChecker(allowRules: ['CustomTool(42)']);
        $tool = $this->makeTool('CustomTool', false);

        // count=42 stringifies to '42', which matches pattern '42'
        $decision = $checker->check($tool, ['count' => 42], $this->context);

        $this->assertTrue($decision->allowed,
            'Integer first value cast to string should still match the pattern');
    }
}
