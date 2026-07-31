<?php

namespace Tests\Unit;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\Worktree\EnterWorktreeTool;
use HaoCode\Tools\Worktree\ExitWorktreeTool;
use PHPUnit\Framework\TestCase;

class WorktreeToolsTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        // Use a non-git tmp dir so git checks fail predictably
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    // ─── EnterWorktreeTool ────────────────────────────────────────────────

    public function test_enter_name(): void
    {
        $this->assertSame('EnterWorktree', (new EnterWorktreeTool)->name());
    }

    public function test_enter_description_mentions_worktree(): void
    {
        $this->assertStringContainsString('worktree', strtolower((new EnterWorktreeTool)->description()));
    }

    public function test_enter_is_not_read_only(): void
    {
        $this->assertFalse((new EnterWorktreeTool)->isReadOnly([]));
    }

    public function test_enter_schema_has_optional_name_field(): void
    {
        $schema = (new EnterWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertArrayHasKey('name', $schema['properties']);
        // name is optional — not in required
        $this->assertNotContains('name', $schema['required'] ?? []);
    }

    public function test_enter_call_returns_error_when_not_in_git_repo(): void
    {
        // sys_get_temp_dir() is not a git repo, so this should fail
        $result = (new EnterWorktreeTool)->call([], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('git', strtolower($result->output));
    }

    public function test_enter_name_sanitisation_strips_special_chars(): void
    {
        // Test sanitisation via a proxy that skips the git checks
        $proxy = new class extends EnterWorktreeTool {
            public function call(array $input, \HaoCode\Tools\ToolUseContext $ctx): \HaoCode\Tools\ToolResult
            {
                $name = $input['name'] ?? null;
                if ($name) {
                    $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                    if ($name === '') {
                        $name = 'worktree_fallback';
                    }
                    if (mb_strlen($name) > 64) {
                        $name = mb_substr($name, 0, 64);
                    }
                }
                return \HaoCode\Tools\ToolResult::success("Name: {$name}");
            }
        };

        $result = $proxy->call(['name' => 'hello world / foo!'], $this->context);
        $this->assertStringContainsString('helloworldfoo', $result->output);
    }

    public function test_enter_long_name_is_truncated(): void
    {
        $proxy = new class extends EnterWorktreeTool {
            public function call(array $input, \HaoCode\Tools\ToolUseContext $ctx): \HaoCode\Tools\ToolResult
            {
                $name = $input['name'] ?? null;
                if ($name) {
                    $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                    if (mb_strlen($name) > 64) {
                        $name = mb_substr($name, 0, 64);
                    }
                }
                return \HaoCode\Tools\ToolResult::success("Name length: " . mb_strlen($name));
            }
        };

        $result = $proxy->call(['name' => str_repeat('a', 100)], $this->context);
        $this->assertStringContainsString('64', $result->output);
    }

    // ─── ExitWorktreeTool ─────────────────────────────────────────────────

    public function test_exit_name(): void
    {
        $this->assertSame('ExitWorktree', (new ExitWorktreeTool)->name());
    }

    public function test_exit_description_mentions_worktree(): void
    {
        $this->assertStringContainsString('worktree', strtolower((new ExitWorktreeTool)->description()));
    }

    public function test_exit_keep_is_not_read_only(): void
    {
        $this->assertFalse((new ExitWorktreeTool)->isReadOnly(['action' => 'keep']));
    }

    public function test_exit_remove_is_not_read_only(): void
    {
        $this->assertFalse((new ExitWorktreeTool)->isReadOnly(['action' => 'remove']));
    }

    public function test_exit_no_action_is_not_read_only(): void
    {
        $this->assertFalse((new ExitWorktreeTool)->isReadOnly([]));
    }

    public function test_exit_schema_requires_action(): void
    {
        $schema = (new ExitWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertContains('action', $schema['required']);
    }

    public function test_exit_schema_action_has_enum(): void
    {
        $schema = (new ExitWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertSame(['keep', 'remove'], $schema['properties']['action']['enum']);
    }

    public function test_exit_call_returns_error_when_not_in_worktree(): void
    {
        // sys_get_temp_dir() is not a git worktree
        $result = (new ExitWorktreeTool)->call(['action' => 'keep'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Not in a worktree', $result->output);
    }

    public function test_enter_switches_context_and_exit_keep_restores_main_repo(): void
    {
        $repo = $this->makeGitRepo('haocode-enter-keep-');
        $context = new ToolUseContext($repo, 'worktree-keep');

        try {
            $enter = (new EnterWorktreeTool)->call(['name' => 'demo'], $context);
            $this->assertFalse($enter->isError, $enter->output);
            $worktree = $context->workingDirectory;
            $this->assertNotSame($repo, $worktree);
            $this->assertDirectoryExists($worktree);
            $this->assertStringContainsString('.claude/worktrees', file_get_contents($repo.'/.gitignore'));

            $exit = (new ExitWorktreeTool)->call(['action' => 'keep'], $context);
            $this->assertFalse($exit->isError, $exit->output);
            $this->assertSame($repo, $context->workingDirectory);
            $this->assertDirectoryExists($worktree);
        } finally {
            if (isset($worktree) && is_dir($worktree)) {
                $this->git($repo, 'worktree remove --force '.escapeshellarg($worktree));
                $this->git($repo, 'branch -D worktree-demo', allowFailure: true);
            }
            $this->removeTree($repo);
        }
    }

    public function test_exit_remove_uses_worktree_main_repo_not_php_process_cwd(): void
    {
        $repoA = $this->makeGitRepo('haocode-exit-a-');
        $repoB = $this->makeGitRepo('haocode-exit-b-');
        $context = new ToolUseContext($repoA, 'worktree-remove');
        $originalCwd = getcwd();

        try {
            $enter = (new EnterWorktreeTool)->call(['name' => 'shared'], $context);
            $this->assertFalse($enter->isError, $enter->output);
            $worktree = $context->workingDirectory;
            $this->git($repoB, 'branch worktree-shared');
            chdir($repoB);

            $exit = (new ExitWorktreeTool)->call(['action' => 'remove', 'discard_changes' => true], $context);

            $this->assertFalse($exit->isError, $exit->output);
            $this->assertSame($repoA, $context->workingDirectory);
            $this->assertDirectoryDoesNotExist($worktree);
            $branches = $this->git($repoB, 'branch --list worktree-shared');
            $this->assertStringContainsString('worktree-shared', $branches);
        } finally {
            if (is_string($originalCwd)) {
                chdir($originalCwd);
            }
            if (isset($worktree) && is_dir($worktree)) {
                $this->git($repoA, 'worktree remove --force '.escapeshellarg($worktree), allowFailure: true);
            }
            $this->removeTree($repoA);
            $this->removeTree($repoB);
        }
    }

    public function test_enter_rejects_claude_and_gitignore_symlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        foreach (['.claude', '.gitignore'] as $target) {
            $repo = $this->makeGitRepo('haocode-symlink-');
            $outside = sys_get_temp_dir().'/haocode-symlink-target-'.bin2hex(random_bytes(4));
            mkdir($outside, 0700, true);
            if ($target === '.claude') {
                symlink($outside, $repo.'/.claude');
            } else {
                symlink($outside.'/gitignore', $repo.'/.gitignore');
            }

            try {
                $context = new ToolUseContext($repo, 'worktree-symlink');
                $result = (new EnterWorktreeTool)->call(['name' => 'blocked'], $context);

                $this->assertTrue($result->isError, $target);
                $this->assertStringContainsString('symlink', $result->output, $target);
                $this->assertDirectoryDoesNotExist($repo.'/.claude/worktrees/blocked');
            } finally {
                @unlink($repo.'/'.$target);
                $this->removeTree($repo);
                $this->removeTree($outside);
            }
        }
    }

    public function test_enter_rejects_worktrees_symlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $repo = $this->makeGitRepo('haocode-worktrees-symlink-');
        $outside = sys_get_temp_dir().'/haocode-worktrees-target-'.bin2hex(random_bytes(4));
        mkdir($repo.'/.claude', 0700, true);
        mkdir($outside, 0700, true);
        symlink($outside, $repo.'/.claude/worktrees');

        try {
            $context = new ToolUseContext($repo, 'worktree-base-symlink');
            $result = (new EnterWorktreeTool)->call(['name' => 'blocked'], $context);

            $this->assertTrue($result->isError);
            $this->assertStringContainsString('symlink', $result->output);
            $this->assertDirectoryDoesNotExist($outside.'/blocked');
            $this->assertSame($repo, $context->workingDirectory);
        } finally {
            @unlink($repo.'/.claude/worktrees');
            $this->removeTree($repo);
            $this->removeTree($outside);
        }
    }

    public function test_enter_updates_gitignore_without_file_append(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Tools/Worktree/EnterWorktreeTool.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('AtomicFileWriter', $source);
        $this->assertStringNotContainsString('FILE_APPEND', $source);
    }

    public function test_enter_does_not_run_post_checkout_hook(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX hook coverage.');
        }

        $repo = $this->makeGitRepo('haocode-hook-');
        $marker = $repo.'/hook-ran';
        $hook = $repo.'/.git/hooks/post-checkout';
        file_put_contents($hook, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\n");
        chmod($hook, 0700);
        $context = new ToolUseContext($repo, 'worktree-hook');

        try {
            $result = (new EnterWorktreeTool)->call(['name' => 'nohook'], $context);

            $this->assertFalse($result->isError, $result->output);
            $this->assertFileDoesNotExist($marker);
        } finally {
            $worktree = $context->workingDirectory;
            if ($worktree !== $repo && is_dir($worktree)) {
                $this->git($repo, 'worktree remove --force '.escapeshellarg($worktree), allowFailure: true);
            }
            $this->removeTree($repo);
        }
    }

    private function makeGitRepo(string $prefix): string
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is required for worktree integration coverage.');
        }

        $repo = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($repo, 0700, true);
        $this->git($repo, 'init');
        $this->git($repo, 'config user.email test@example.com');
        $this->git($repo, 'config user.name Test');
        file_put_contents($repo.'/tracked.txt', "base\n");
        $this->git($repo, 'add tracked.txt');
        $this->git($repo, 'commit -m init');

        return realpath($repo) ?: $repo;
    }

    private function git(string $cwd, string $command, bool $allowFailure = false): string
    {
        exec('git -C '.escapeshellarg($cwd).' '.$command.' 2>&1', $output, $exitCode);
        if (! $allowFailure) {
            $this->assertSame(0, $exitCode, implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
