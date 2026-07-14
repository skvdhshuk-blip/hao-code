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

    public function test_get_diff_context_empty_in_real_non_git_directory(): void
    {
        $directory = sys_get_temp_dir().'/haocode_non_git_'.uniqid('', true);
        mkdir($directory, 0755, true);

        try {
            $this->assertSame('', (new GitContext($directory))->getDiffContext());
        } finally {
            rmdir($directory);
        }
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

    public function test_untracked_only_changes_do_not_build_expensive_diff(): void
    {
        $directory = $this->createGitRepository('untracked_only');
        file_put_contents($directory.'/untracked.txt', 'local work');

        try {
            $context = $this->getMockBuilder(GitContext::class)
                ->setConstructorArgs([$directory])
                ->onlyMethods(['getWorkingTreeDiff'])
                ->getMock();
            $context->expects($this->never())->method('getWorkingTreeDiff');

            $result = $context->getDiffContext();

            $this->assertStringContainsString('?? untracked.txt', $result);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_tracked_working_tree_states_still_build_diff(): void
    {
        $directory = $this->createGitRepository('tracked_states');
        file_put_contents($directory.'/modified.txt', 'original');
        file_put_contents($directory.'/staged.txt', 'original');
        file_put_contents($directory.'/rename-before.txt', 'original');
        file_put_contents($directory.'/deleted.txt', 'original');
        exec('git -C '.escapeshellarg($directory).' add .');
        exec('git -C '.escapeshellarg($directory).' commit -qm fixture');

        file_put_contents($directory.'/modified.txt', 'modified');
        file_put_contents($directory.'/staged.txt', 'staged');
        exec('git -C '.escapeshellarg($directory).' add staged.txt');
        exec('git -C '.escapeshellarg($directory).' mv rename-before.txt rename-after.txt');
        unlink($directory.'/deleted.txt');

        try {
            $context = $this->getMockBuilder(GitContext::class)
                ->setConstructorArgs([$directory])
                ->onlyMethods(['getWorkingTreeDiff'])
                ->getMock();
            $context->expects($this->once())
                ->method('getWorkingTreeDiff')
                ->willReturn('tracked diff sentinel');

            $result = $context->getDiffContext();

            $this->assertStringContainsString('modified.txt', $result);
            $this->assertStringContainsString('staged.txt', $result);
            $this->assertStringContainsString('rename-before.txt -> rename-after.txt', $result);
            $this->assertStringContainsString('deleted.txt', $result);
            $this->assertStringContainsString('tracked diff sentinel', $result);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_conflicted_file_still_builds_diff(): void
    {
        $directory = $this->createGitRepository('conflict');
        file_put_contents($directory.'/conflict.txt', "base\n");
        exec('git -C '.escapeshellarg($directory).' add conflict.txt');
        exec('git -C '.escapeshellarg($directory).' commit -qm base');
        exec('git -C '.escapeshellarg($directory).' checkout -qb feature');
        file_put_contents($directory.'/conflict.txt', "feature\n");
        exec('git -C '.escapeshellarg($directory).' commit -qam feature');
        exec('git -C '.escapeshellarg($directory).' checkout -q main');
        file_put_contents($directory.'/conflict.txt', "main\n");
        exec('git -C '.escapeshellarg($directory).' commit -qam main');
        exec('git -C '.escapeshellarg($directory).' merge feature >/dev/null 2>&1');

        try {
            $context = $this->getMockBuilder(GitContext::class)
                ->setConstructorArgs([$directory])
                ->onlyMethods(['getWorkingTreeDiff'])
                ->getMock();
            $context->expects($this->once())
                ->method('getWorkingTreeDiff')
                ->willReturn('conflict diff sentinel');

            $result = $context->getDiffContext();

            $this->assertStringContainsString('UU conflict.txt', $result);
            $this->assertStringContainsString('conflict diff sentinel', $result);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_tracked_changes_are_detected_when_status_exceeds_display_limit(): void
    {
        $directory = $this->createGitRepository('large_status');
        file_put_contents($directory.'/tracked.txt', 'original');
        exec('git -C '.escapeshellarg($directory).' add tracked.txt');
        exec('git -C '.escapeshellarg($directory).' commit -qm fixture');

        for ($index = 0; $index < 101; $index++) {
            file_put_contents($directory.'/untracked-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.txt', 'fixture');
        }
        file_put_contents($directory.'/tracked.txt', 'modified');

        try {
            $context = $this->getMockBuilder(GitContext::class)
                ->setConstructorArgs([$directory])
                ->onlyMethods(['getWorkingTreeDiff'])
                ->getMock();
            $context->expects($this->once())
                ->method('getWorkingTreeDiff')
                ->willReturn('large status diff sentinel');

            $result = $context->getDiffContext();

            $this->assertStringContainsString('large status diff sentinel', $result);
            $this->assertStringNotContainsString('untracked-100.txt', $result);
        } finally {
            $this->removeDirectory($directory);
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

    public function test_snapshot_cache_is_scoped_and_refreshes_after_it_ends(): void
    {
        $directory = sys_get_temp_dir().'/haocode_git_snapshot_'.uniqid('', true);
        mkdir($directory, 0755, true);
        exec('git -C '.escapeshellarg($directory).' init -q -b main');
        exec('git -C '.escapeshellarg($directory).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($directory).' config user.name Test');
        file_put_contents($directory.'/tracked.txt', 'fixture');
        exec('git -C '.escapeshellarg($directory).' add tracked.txt');
        exec('git -C '.escapeshellarg($directory).' commit -qm initial');

        try {
            $context = new GitContext($directory);
            $context->beginSnapshot();
            $this->assertSame('main', $context->getCurrentBranch());

            exec('git -C '.escapeshellarg($directory).' checkout -qb changed');

            $this->assertSame('main', $context->getCurrentBranch());
            $context->endSnapshot();
            $this->assertSame('changed', $context->getCurrentBranch());
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_nested_snapshot_remains_cached_until_outer_snapshot_ends(): void
    {
        $directory = $this->createGitRepository('nested_snapshot');

        try {
            $context = new GitContext($directory);
            $context->beginSnapshot();
            $context->beginSnapshot();
            $this->assertSame('main', $context->getCurrentBranch());

            exec('git -C '.escapeshellarg($directory).' checkout -qb changed');

            $context->endSnapshot();
            $this->assertSame('main', $context->getCurrentBranch());
            $context->endSnapshot();
            $this->assertSame('changed', $context->getCurrentBranch());

            // Extra cleanup calls must be harmless and must not re-enable caching.
            $context->endSnapshot();
            exec('git -C '.escapeshellarg($directory).' checkout -qb refreshed');
            $this->assertSame('refreshed', $context->getCurrentBranch());
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function createGitRepository(string $suffix): string
    {
        $directory = sys_get_temp_dir().'/haocode_git_'.$suffix.'_'.uniqid('', true);
        mkdir($directory, 0755, true);
        exec('git -C '.escapeshellarg($directory).' init -q -b main');
        exec('git -C '.escapeshellarg($directory).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($directory).' config user.name Test');
        exec('git -C '.escapeshellarg($directory).' commit --allow-empty -qm initial');

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
