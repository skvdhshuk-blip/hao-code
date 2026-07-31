<?php

namespace Tests\Unit;

use HaoCode\Tools\FileEdit\DiffGenerator;
use PHPUnit\Framework\TestCase;

class DiffGeneratorTest extends TestCase
{
    public function test_unified_diff_shows_changes(): void
    {
        $old = "line1\nline2\nline3\n";
        $new = "line1\nmodified\nline3\n";

        $diff = DiffGenerator::unifiedDiff($old, $new, 'test.txt');
        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('+modified', $diff);
    }

    public function test_unified_diff_empty_for_identical(): void
    {
        $content = "same content\n";
        $diff = DiffGenerator::unifiedDiff($content, $content);
        $this->assertSame('', $diff);
    }

    public function test_structured_patch_returns_hunks(): void
    {
        $old = "a\nb\nc\n";
        $new = "a\nB\nc\n";

        $hunks = DiffGenerator::structuredPatch($old, $new);
        $this->assertNotEmpty($hunks);
        $this->assertArrayHasKey('oldStart', $hunks[0]);
        $this->assertArrayHasKey('newStart', $hunks[0]);
        $this->assertArrayHasKey('lines', $hunks[0]);
    }

    public function test_change_summary_additions(): void
    {
        $old = "a\n";
        $new = "a\nb\nc\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('+2', $summary);
    }

    public function test_change_summary_deletions(): void
    {
        $old = "a\nb\nc\n";
        $new = "a\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('-2', $summary);
    }

    public function test_change_summary_modification(): void
    {
        $old = "line1\n";
        $new = "line2\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('modified', $summary);
    }

    public function test_git_diff_does_not_run_external_diff_driver(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git and POSIX shell are required for external diff coverage.');
        }

        $root = sys_get_temp_dir().'/haocode-git-diff-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $file = $root.'/tracked.txt';
        $marker = $root.'/external-diff-ran';
        $script = $root.'/external-diff.sh';

        try {
            file_put_contents($file, "old\n");
            file_put_contents($script, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\nexit 0\n");
            chmod($script, 0700);

            $this->runGit($root, 'init');
            $this->runGit($root, 'add tracked.txt');
            $this->runGit($root, 'config diff.external '.escapeshellarg($script));
            file_put_contents($file, "new\n");

            $diff = DiffGenerator::gitDiff($file);

            $this->assertStringContainsString('-old', $diff);
            $this->assertStringContainsString('+new', $diff);
            $this->assertFileDoesNotExist($marker);
        } finally {
            $this->removeTree($root);
        }
    }

    public function test_git_diff_ignores_global_external_diff_driver(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git and POSIX shell are required for external diff coverage.');
        }

        $root = sys_get_temp_dir().'/haocode-git-global-diff-'.bin2hex(random_bytes(6));
        $home = sys_get_temp_dir().'/haocode-git-home-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        mkdir($home, 0700, true);
        $file = $root.'/tracked.txt';
        $marker = $root.'/global-external-diff-ran';
        $script = $root.'/global-external-diff.sh';
        $oldHome = getenv('HOME');

        try {
            file_put_contents($file, "old\n");
            file_put_contents($script, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\nexit 0\n");
            chmod($script, 0700);
            file_put_contents($home.'/.gitconfig', "[diff]\n\texternal = {$script}\n");

            $this->runGit($root, 'init');
            $this->runGit($root, 'add tracked.txt');
            file_put_contents($file, "new\n");
            putenv('HOME='.$home);

            $diff = DiffGenerator::gitDiff($file);

            $this->assertStringContainsString('-old', $diff);
            $this->assertStringContainsString('+new', $diff);
            $this->assertFileDoesNotExist($marker);
        } finally {
            if (is_string($oldHome)) {
                putenv('HOME='.$oldHome);
            } else {
                putenv('HOME');
            }
            $this->removeTree($root);
            $this->removeTree($home);
        }
    }

    public function test_git_diff_does_not_run_textconv_driver(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git and POSIX shell are required for textconv coverage.');
        }

        $root = sys_get_temp_dir().'/haocode-git-textconv-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $file = $root.'/tracked.secret';
        $attributes = $root.'/.gitattributes';
        $marker = $root.'/textconv-ran';
        $script = $root.'/textconv.sh';

        try {
            file_put_contents($file, "old\n");
            file_put_contents($attributes, "*.secret diff=secret\n");
            file_put_contents($script, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\ncat \"$1\"\n");
            chmod($script, 0700);

            $this->runGit($root, 'init');
            $this->runGit($root, 'config diff.secret.textconv '.escapeshellarg($script));
            $this->runGit($root, 'add .gitattributes tracked.secret');
            file_put_contents($file, "new\n");

            $diff = DiffGenerator::gitDiff($file);

            $this->assertStringContainsString('-old', $diff);
            $this->assertStringContainsString('+new', $diff);
            $this->assertFileDoesNotExist($marker);
        } finally {
            $this->removeTree($root);
        }
    }

    private function runGit(string $cwd, string $command): void
    {
        exec('git -C '.escapeshellarg($cwd).' '.$command.' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
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
