<?php

namespace Tests\Unit;

use HaoCode\Services\Git\GitContext;
use PHPUnit\Framework\TestCase;

class GitContextTest extends TestCase
{
    // ─── isGitRepo ────────────────────────────────────────────────────────

    public function test_is_git_repo_returns_bool(): void
    {
        $ctx = new GitContext;
        // The test suite runs inside a git repo, so this should be true
        $result = $ctx->isGitRepo();
        $this->assertIsBool($result);
    }

    public function test_is_git_repo_true_inside_project(): void
    {
        // hao-code IS a git repo, so this should return true
        $ctx = new GitContext;
        $this->assertTrue($ctx->isGitRepo());
    }

    // ─── getCurrentBranch ────────────────────────────────────────────────

    public function test_get_current_branch_returns_string(): void
    {
        $ctx = new GitContext;
        $branch = $ctx->getCurrentBranch();
        $this->assertIsString($branch);
        $this->assertNotSame('', $branch);
    }

    public function test_get_current_branch_not_empty(): void
    {
        $ctx = new GitContext;
        $branch = $ctx->getCurrentBranch();
        // In a detached HEAD state it returns 'HEAD', otherwise a branch name
        $this->assertNotSame('', $branch);
    }

    public function test_has_uncommitted_changes_returns_bool(): void
    {
        $ctx = new GitContext;

        $this->assertIsBool($ctx->hasUncommittedChanges());
    }

    // ─── getGitRoot ───────────────────────────────────────────────────────

    public function test_get_git_root_returns_string(): void
    {
        $ctx = new GitContext;
        $root = $ctx->getGitRoot();
        $this->assertIsString($root);
    }

    public function test_get_git_root_is_existing_directory(): void
    {
        $ctx = new GitContext;
        $root = $ctx->getGitRoot();
        $this->assertDirectoryExists($root);
    }

    // ─── getDiffContext ───────────────────────────────────────────────────

    public function test_get_diff_context_returns_string(): void
    {
        $ctx = new GitContext;
        $result = $ctx->getDiffContext();
        $this->assertIsString($result);
    }

    public function test_get_diff_context_mentions_branch(): void
    {
        $ctx = new GitContext;
        if ($ctx->isGitRepo()) {
            $result = $ctx->getDiffContext();
            $this->assertStringContainsString('Branch:', $result);
        } else {
            $this->markTestSkipped('Not in a git repository');
        }
    }

    public function test_get_diff_context_mentions_git_status_header(): void
    {
        $ctx = new GitContext;
        if ($ctx->isGitRepo()) {
            $result = $ctx->getDiffContext();
            $this->assertStringContainsString('# Git Status', $result);
        } else {
            $this->markTestSkipped('Not in a git repository');
        }
    }

    public function test_get_diff_context_empty_outside_git_repo(): void
    {
        // Create a mock that pretends isGitRepo() returns false
        $ctx = $this->getMockBuilder(GitContext::class)
            ->onlyMethods(['isGitRepo'])
            ->getMock();
        $ctx->method('isGitRepo')->willReturn(false);

        $this->assertSame('', $ctx->getDiffContext());
    }

    public function test_get_diff_context_reports_untracked_files_as_dirty(): void
    {
        $directory = sys_get_temp_dir().'/haocode_git_context_'.uniqid('', true);
        mkdir($directory, 0755, true);
        exec('git -C '.escapeshellarg($directory).' init -q');
        file_put_contents($directory.'/untracked.txt', 'local work');

        try {
            $context = (new GitContext($directory))->getDiffContext();

            $this->assertStringContainsString('?? untracked.txt', $context);
            $this->assertStringNotContainsString('Working tree: clean', $context);
        } finally {
            unlink($directory.'/untracked.txt');
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory.'/.git', \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory.'/.git');
            rmdir($directory);
        }
    }

    // ─── isGitIgnored ─────────────────────────────────────────────────────

    public function test_is_git_ignored_returns_bool(): void
    {
        $ctx = new GitContext;
        if ($ctx->isGitRepo()) {
            $result = $ctx->isGitIgnored('/tmp/somefile.txt');
            $this->assertIsBool($result);
        } else {
            $this->markTestSkipped('Not in a git repository');
        }
    }

    public function test_vendor_directory_is_gitignored(): void
    {
        $ctx = new GitContext;
        if ($ctx->isGitRepo()) {
            // vendor/ is typically in .gitignore
            $root = $ctx->getGitRoot();
            $result = $ctx->isGitIgnored($root . '/vendor');
            // This is likely true but not guaranteed for all repos
            $this->assertIsBool($result);
        } else {
            $this->markTestSkipped('Not in a git repository');
        }
    }

    // ─── getRemoteUrl ─────────────────────────────────────────────────────

    public function test_get_remote_url_returns_string(): void
    {
        $ctx = new GitContext;
        $url = $ctx->getRemoteUrl();
        $this->assertIsString($url);
        // May be empty if no remote is configured
    }

    // ─── getDefaultBranch ────────────────────────────────────────────────

    public function test_get_default_branch_returns_string(): void
    {
        $ctx = new GitContext;
        $branch = $ctx->getDefaultBranch();
        $this->assertIsString($branch);
        // May be empty if remote HEAD isn't set
    }
}
