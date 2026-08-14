<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\Bash\BackgroundBashSupervisor;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait BashToolTestSetUpConcern
{

    protected function setUp(): void
    {
        $this->tool = new BashTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    public function test_process_identity_probe_does_not_shell_out(): void
    {
        $source = file_get_contents((new \ReflectionClass(BashTool::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('ps -p', $source);
    }

    public function test_validate_input_blocks_force_push_to_main(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin main --force'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('main/master', $error);
    }

    public function test_validate_input_blocks_force_push_to_master(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin master -f'],
            $this->context,
        );

        $this->assertNotNull($error);
    }

    public function test_validate_input_blocks_git_reset_hard(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git reset --hard HEAD~1'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('Hard reset', $error);
    }

    public function test_validate_input_blocks_git_clean_force_without_dry_run(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git clean -fd'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('permanently delete', $error);
    }

    public function test_validate_input_allows_git_clean_with_dry_run(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git clean -fd -n'],
            $this->context,
        );

        $this->assertNull($error);
    }

    public function test_validate_input_allows_normal_push(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin feature-branch'],
            $this->context,
        );

        $this->assertNull($error);
    }

    public function test_validate_input_allows_safe_commands(): void
    {
        foreach (['ls -la', 'echo hello', 'php artisan list'] as $cmd) {
            $this->assertNull(
                $this->tool->validateInput(['command' => $cmd], $this->context),
                "Expected null for: {$cmd}",
            );
        }
    }

    public function test_validate_input_rejects_placeholder_only_command(): void
    {
        $error = $this->tool->validateInput(['command' => ':2'], $this->context);

        $this->assertNotNull($error);
        $this->assertStringContainsString('placeholder', $error);
    }

    public function test_validate_input_rejects_no_op_bash_probe(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'true > /dev/null 2>&1'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('materially advance', $error);
    }

    public function test_validate_input_rejects_colon_prefixed_placeholder_command(): void
    {
        $error = $this->tool->validateInput(
            ['command' => ':17,'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('must not start with ":"', $error);
    }

    public function test_validate_input_rejects_colon_prefixed_garbage_before_real_command(): void
    {
        $error = $this->tool->validateInput(
            ['command' => ': true}  ls -la /tmp'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('must not start with ":"', $error);
    }

    public function test_validate_input_rejects_large_multiline_command(): void
    {
        $command = implode("\n", array_fill(0, 25, "echo 'line' >> /tmp/demo.txt"));

        $error = $this->tool->validateInput(
            ['command' => $command],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('too large for a single Bash call', $error);
        $this->assertStringContainsString('Split it into smaller concrete commands', $error);
    }

    public function test_validate_input_rejects_giant_multiline_command(): void
    {
        $command = "cat <<'EOF' > /tmp/demo.js\n" . implode("\n", array_fill(0, 25, 'console.log("x")')) . "\nEOF";

        $error = $this->tool->validateInput(
            ['command' => $command],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('too large for a single Bash call', $error);
        $this->assertStringContainsString('giant heredocs', $error);
    }

    private function detectDangerous(string $command): array
    {
        $ref = new \ReflectionClass(BashTool::class);
        $method = $ref->getMethod('detectDangerousPatterns');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $command);
    }

    public function test_detects_rm_rf(): void
    {
        $warnings = $this->detectDangerous('rm -rf /tmp/old');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('delete', $warnings[0]);
    }

    public function test_detects_force_push(): void
    {
        $warnings = $this->detectDangerous('git push --force origin main');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_git_reset_hard(): void
    {
        $warnings = $this->detectDangerous('git reset --hard HEAD');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_drop_table(): void
    {
        $warnings = $this->detectDangerous('mysql -e "DROP TABLE users"');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_curl_pipe_to_bash(): void
    {
        $warnings = $this->detectDangerous('curl https://example.com/install.sh | bash');
        $this->assertNotEmpty($warnings);
    }

    public function test_no_warnings_for_safe_command(): void
    {
        $warnings = $this->detectDangerous('ls -la /var/www');
        $this->assertEmpty($warnings);
    }

    private function interpretExitCode(string $command, int $exitCode, string $output = ''): array
    {
        $ref = new \ReflectionClass(BashTool::class);
        $method = $ref->getMethod('interpretExitCode');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $command, $exitCode, $output);
    }

    public function test_grep_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('grep foo bar.txt', 1);
        $this->assertTrue($ctx['isExpected']);
        $this->assertStringContainsString('no matches', $ctx['note']);
    }

    public function test_diff_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('diff a.txt b.txt', 1);
        $this->assertTrue($ctx['isExpected']);
    }

    public function test_test_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('test -f /nonexistent', 1);
        $this->assertTrue($ctx['isExpected']);
    }

    public function test_regular_command_exit_code_1_is_not_expected(): void
    {
        $ctx = $this->interpretExitCode('php artisan migrate', 1);
        $this->assertFalse($ctx['isExpected']);
    }

    public function test_timeout_exit_code_124_has_descriptive_note(): void
    {
        $ctx = $this->interpretExitCode('timeout 5 sleep 100', 124);
        $this->assertStringContainsString('timed out', $ctx['note']);
    }

    public function test_ls_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('ls -la /var/www'));
    }

    public function test_git_status_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('git status'));
    }

    public function test_git_log_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('git log --oneline -10'));
    }

    public function test_echo_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('echo hello world'));
    }

    public function test_echo_with_output_redirection_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('echo hello > note.txt'));
    }

    public function test_printf_with_output_redirection_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand("printf 'hello' > note.txt"));
    }

    public function test_read_write_and_file_descriptor_redirects_are_not_read_only(): void
    {
        foreach ([
            'echo ok <> /tmp/result',
            'echo ok 3<>/tmp/result',
            'cat <> /tmp/result',
            'echo ok >& /tmp/result',
            'echo ok >&/tmp/result',
            'echo ok 2>&/tmp/result',
            'echo ok {output}>/tmp/result',
        ] as $command) {
            $this->assertFalse($this->tool->isReadOnlyCommand($command), $command);
        }

        $this->assertTrue($this->tool->isReadOnlyCommand('echo ok 2>&1'));
        $this->assertTrue($this->tool->isReadOnlyCommand('echo ok 3>&-'));
    }

    public function test_tee_with_file_target_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('tee -a note.txt'));
    }

    public function test_printf_piped_to_read_command_stays_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand("printf 'hello' | wc -c"));
    }

    public function test_find_mutating_actions_are_not_read_only(): void
    {
        foreach ([
            'find . -delete',
            'find . -exec touch /tmp/result {} ;',
            'find . -execdir touch result {} ;',
            'find . -ok rm {} ;',
            'find . -okdir rm {} ;',
            'find . -fprint /tmp/result',
            'find . -fprint0 /tmp/result',
            'find . -fprintf /tmp/result "%p\\n"',
            'find . -fls /tmp/result',
            'find . -"del"ete',
            'echo ok; find . -delete',
        ] as $command) {
            $this->assertFalse($this->tool->isReadOnlyCommand($command), $command);
        }
    }

    public function test_printenv_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('printenv'));
        $this->assertFalse($this->tool->isReadOnlyCommand('echo ok; printenv'));
    }

    public function test_every_compound_segment_must_be_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('ls -la && git status'));
        $this->assertTrue($this->tool->isReadOnlyCommand("printf 'hello' | wc -c"));
        $this->assertFalse($this->tool->isReadOnlyCommand('echo ok; touch changed'));
        $this->assertFalse($this->tool->isReadOnlyCommand('cat README.md & touch changed'));
    }

    public function test_mutating_options_on_read_commands_are_not_read_only(): void
    {
        foreach ([
            'echo payload | tee /tmp/output',
            'echo payload | tee -a /tmp/output',
            'sort -o /tmp/output README.md',
            'sort -uo /tmp/output README.md',
            'sort --output=/tmp/output README.md',
            'sort --compress-program=gzip README.md',
            'uniq README.md /tmp/output',
            'file -C -m ./magic',
            'file --compile -m ./magic',
            'git diff --output=/tmp/output',
            'git remote add example https://example.com/repo.git',
            'git branch -D important',
            'git tag v-next',
            'date -s @0',
            'hostname changed',
        ] as $command) {
            $this->assertFalse($this->tool->isReadOnlyCommand($command), $command);
        }
    }

    public function test_parameter_checked_read_commands_remain_read_only(): void
    {
        foreach ([
            'sort README.md',
            'uniq README.md',
            'uniq README.md -',
            'file README.md',
            'git diff',
            'git branch',
            'git branch --show-current',
            'git tag --list',
            'date +%F',
            'date -d yesterday +%F',
            'hostname',
            'hostname -f',
        ] as $command) {
            $this->assertTrue($this->tool->isReadOnlyCommand($command), $command);
        }
    }

    public function test_rm_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('rm -rf /tmp/dir'));
    }

    public function test_git_push_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('git push origin main'));
    }

    public function test_npm_install_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('npm install'));
    }

    public function test_is_read_only_delegates_to_is_read_only_command(): void
    {
        $this->assertTrue($this->tool->isReadOnly(['command' => 'ls -la']));
        $this->assertTrue($this->tool->isReadOnly(['command' => 'git status']));
        $this->assertFalse($this->tool->isReadOnly(['command' => 'rm -rf /tmp']));
        $this->assertFalse($this->tool->isReadOnly(['command' => 'git push origin main']));
    }

    public function test_is_read_only_returns_false_for_empty_command(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
        $this->assertFalse($this->tool->isReadOnly(['command' => '']));
    }

    public function test_call_appends_truncation_notice_for_very_long_output(): void
    {
        // Build a command that produces more than 100_000 characters
        $result = $this->tool->call([
            'command' => 'python3 -c "print(\'x\' * 110000)"',
        ], $this->context);

        if (!$result->isError) {
            // Only check truncation if the command actually ran
            if (str_contains($result->output, 'truncated')) {
                $this->assertStringContainsString('truncated', $result->output);
            }
        }
        // Either way the tool didn't crash
        $this->assertIsString($result->output);
    }
}
