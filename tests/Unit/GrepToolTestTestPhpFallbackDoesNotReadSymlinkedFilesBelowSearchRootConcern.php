<?php

namespace Tests\Unit;

use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait GrepToolTestTestPhpFallbackDoesNotReadSymlinkedFilesBelowSearchRootConcern
{

    public function test_php_fallback_does_not_read_symlinked_files_below_search_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $outside = tempnam(sys_get_temp_dir(), 'grep-sensitive-');
        file_put_contents($outside, 'private-key-material');
        symlink($outside, $this->tmpDir.'/linked-secret.txt');

        try {
            $result = $this->grepPhp('private-key-material');

            $this->assertFalse($result->isError);
            $this->assertSame(
                'No matches found for pattern: private-key-material',
                $result->output,
            );
        } finally {
            @unlink($this->tmpDir.'/linked-secret.txt');
            @unlink($outside);
        }
    }

    public function test_php_fallback_skips_default_ignored_directories(): void
    {
        mkdir($this->tmpDir.'/.claude/worktrees/demo', 0755, true);
        mkdir($this->tmpDir.'/vendor/package', 0755, true);
        $this->writeFile('src.txt', "needle\n");
        file_put_contents($this->tmpDir.'/.claude/worktrees/demo/ignored.txt', "needle\n");
        file_put_contents($this->tmpDir.'/vendor/package/ignored.txt', "needle\n");

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src.txt', $result->output);
        $this->assertStringNotContainsString('.claude/worktrees', $result->output);
        $this->assertStringNotContainsString('vendor/package', $result->output);
    }

    public function test_php_fallback_respects_root_gitignore(): void
    {
        file_put_contents($this->tmpDir.'/.gitignore', "ignored-dir/\n*.log\n!important.log\n");
        mkdir($this->tmpDir.'/ignored-dir', 0755, true);
        $this->writeFile('src.txt', "needle\n");
        $this->writeFile('debug.log', "needle\n");
        $this->writeFile('important.log', "needle\n");
        file_put_contents($this->tmpDir.'/ignored-dir/hidden.txt', "needle\n");

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src.txt', $result->output);
        $this->assertStringContainsString('important.log', $result->output);
        $this->assertStringNotContainsString('debug.log', $result->output);
        $this->assertStringNotContainsString('ignored-dir/hidden.txt', $result->output);
    }

    public function test_php_fallback_respects_gitignore_from_repository_ancestor_when_searching_subdirectory(): void
    {
        $repo = $this->tmpDir.'/repo';
        $searchRoot = $repo.'/src';
        mkdir($searchRoot, 0755, true);
        mkdir($repo.'/.git', 0755, true);
        file_put_contents($repo.'/.gitignore', "*.log\n");
        file_put_contents($searchRoot.'/ignored.log', "needle\n");
        file_put_contents($searchRoot.'/kept.txt', "needle\n");

        $result = $this->grepPhp('needle', path: $searchRoot, outputMode: 'files_with_matches');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('kept.txt', $result->output);
        $this->assertStringNotContainsString('ignored.log', $result->output);
    }

    public function test_php_fallback_descends_ignored_directories_for_negated_gitignore_entries(): void
    {
        file_put_contents($this->tmpDir.'/.gitignore', "ignored-dir/\n!ignored-dir/keep.txt\n");
        mkdir($this->tmpDir.'/ignored-dir', 0755, true);
        file_put_contents($this->tmpDir.'/ignored-dir/hidden.txt', "needle\n");
        file_put_contents($this->tmpDir.'/ignored-dir/keep.txt', "needle\n");

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('ignored-dir/keep.txt', $result->output);
        $this->assertStringNotContainsString('ignored-dir/hidden.txt', $result->output);
    }

    public function test_php_fallback_rejects_an_unbounded_gitignore_file(): void
    {
        file_put_contents($this->tmpDir.'/.gitignore', str_repeat("ignored-*.tmp\n", 80_000));

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('.gitignore', $result->output);
    }

    public function test_php_fallback_prunes_default_ignored_directories_before_recursing(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission coverage.');
        }

        $this->writeFile('src.txt', "needle\n");
        mkdir($this->tmpDir.'/vendor', 0755, true);
        $locked = $this->tmpDir.'/vendor/locked';
        mkdir($locked, 0000);

        try {
            $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

            $this->assertFalse($result->isError);
            $this->assertSame('src.txt', $result->output);

            $source = file_get_contents((new \ReflectionMethod(GrepTool::class, 'grepWithPhp'))->getFileName());
            $this->assertIsString($source);
            $this->assertStringContainsString('RecursiveCallbackFilterIterator', $source);
            $this->assertStringContainsString('CATCH_GET_CHILD', $source);
        } finally {
            @chmod($locked, 0700);
        }
    }

    public function test_call_returns_aborted_when_context_requests_cancel(): void
    {
        $this->writeFile('src.txt', "needle\n");
        $context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test-session',
            shouldAbort: static fn (): bool => true,
        );

        $result = $this->tool->call(
            ['pattern' => 'needle', 'output_mode' => 'files_with_matches'],
            $context,
        );

        $this->assertSame(ToolOutcome::Aborted, $result->outcome());
    }

    public function test_php_fallback_honors_abort_during_scan(): void
    {
        $this->writeFile('src.txt', str_repeat("needle\n", 20));
        $checks = 0;

        $result = $this->grepPhp(
            'needle',
            shouldAbort: static function () use (&$checks): bool {
                $checks++;

                return $checks > 2;
            },
        );

        $this->assertSame(ToolOutcome::Aborted, $result->outcome());
    }

    public function test_ripgrep_skips_default_ignored_directories(): void
    {
        if (! $this->hasRipgrep()) {
            $this->markTestSkipped('ripgrep is not installed.');
        }

        mkdir($this->tmpDir.'/.claude/worktrees/demo', 0755, true);
        mkdir($this->tmpDir.'/vendor/package', 0755, true);
        $this->writeFile('src.txt', "needle\n");
        file_put_contents($this->tmpDir.'/.claude/worktrees/demo/ignored.txt', "needle\n");
        file_put_contents($this->tmpDir.'/vendor/package/ignored.txt', "needle\n");

        $result = $this->grepRg('needle', $this->tmpDir, outputMode: 'files_with_matches');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src.txt', $result->output);
        $this->assertStringNotContainsString('.claude/worktrees', $result->output);
        $this->assertStringNotContainsString('vendor/package', $result->output);
    }

    public function test_ripgrep_rejects_an_unbounded_single_output_line(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Executable shim coverage is POSIX-focused.');
        }

        $fakeRg = $this->tmpDir.'/rg';
        $script = 'exec '.escapeshellarg(PHP_BINARY).' -r '
            .escapeshellarg('echo str_repeat("x", 1_000_001);');
        file_put_contents($fakeRg, "#!/bin/sh\n{$script}\n");
        chmod($fakeRg, 0700);
        $previousPath = getenv('PATH');
        putenv('PATH='.$this->tmpDir.':'.($previousPath === false ? '' : $previousPath));

        try {
            $result = $this->grepRg('needle', $this->tmpDir, outputMode: 'content', headLimit: 1);

            $this->assertTrue($result->isError, $result->output);
            $this->assertStringContainsString('output line exceeded', $result->output);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$previousPath);
            }
        }
    }

    public function test_php_fallback_explicitly_rejects_unsupported_type_filter(): void
    {
        $this->writeFile('a.php', "<?php\n");

        $result = $this->grepPhp('php', type: 'php');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('requires ripgrep', $result->output);
    }

    public function test_php_fallback_explicitly_rejects_multiline_search(): void
    {
        $this->writeFile('a.txt', "one\ntwo\n");

        $result = $this->grepPhp('one.*two', multiline: true);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('requires ripgrep', $result->output);
    }

    public function test_ripgrep_and_php_fallback_return_identical_order_paths_context_and_limits(): void
    {
        if (! $this->hasRipgrep()) {
            $this->markTestSkipped('ripgrep is not installed.');
        }

        $this->writeFile('b.txt', "before-c\nneedle-c\nafter-c\n");
        $this->writeFile('a.txt', "before-a\nneedle-a\nafter-a\ngap\nneedle-b\ntail\n");

        foreach (['content', 'files_with_matches', 'count'] as $mode) {
            $after = $mode === 'content' ? 1 : 0;
            $before = $mode === 'content' ? 1 : 0;
            $offset = $mode === 'content' ? 1 : 0;
            $head = $mode === 'content' ? 5 : 10;
            $php = $this->grepPhp(
                'needle',
                outputMode: $mode,
                afterLines: $after,
                beforeLines: $before,
                headLimit: $head,
                offset: $offset,
            );
            $rg = $this->grepRg(
                'needle',
                $this->tmpDir,
                outputMode: $mode,
                afterLines: $after,
                beforeLines: $before,
                headLimit: $head,
                offset: $offset,
            );

            $this->assertSame($php->output, $rg->output, "backend mismatch for {$mode}");
        }

        $this->assertSame(
            $this->grepPhp('missing', headLimit: 0)->output,
            $this->grepRg('missing', $this->tmpDir, headLimit: 0)->output,
        );
    }

    public function test_grep_tool_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }
}
