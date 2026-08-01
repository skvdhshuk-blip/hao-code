<?php

namespace Tests\Unit;

use HaoCode\Tools\FileEdit\DiffGenerator;
use HaoCode\Services\Git\HardenedGitRunner;
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

    public function test_unified_diff_uses_php_fallback_without_shell_exec(): void
    {
        $source = file_get_contents((new \ReflectionClass(DiffGenerator::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('diff -u', $source);
    }

    public function test_unified_diff_handles_insertions_and_deletions(): void
    {
        $diff = DiffGenerator::unifiedDiff("alpha\nbeta\ngamma\n", "alpha\ngamma\ndelta\n", 'demo.txt');

        $this->assertStringContainsString("--- demo.txt\n", $diff);
        $this->assertStringContainsString("+++ demo.txt\n", $diff);
        $this->assertStringContainsString('-beta', $diff);
        $this->assertStringContainsString('+delta', $diff);
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

    public function test_hardened_git_runner_disables_repository_fsmonitor(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git and POSIX shell are required for fsmonitor coverage.');
        }

        $root = sys_get_temp_dir().'/haocode-git-fsmonitor-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $marker = $root.'/fsmonitor-ran';
        $script = $root.'/fsmonitor.sh';

        try {
            file_put_contents($script, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\nexit 0\n");
            chmod($script, 0700);

            $this->runGit($root, 'init');
            $this->runGit($root, 'config core.fsmonitor '.escapeshellarg($script));

            $result = (new HardenedGitRunner())->runGit($root, ['status', '--porcelain']);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertFileDoesNotExist($marker, 'Repository fsmonitor commands must not run during internal Git queries.');
        } finally {
            $this->removeTree($root);
        }
    }

    public function test_git_diff_does_not_run_repository_filter_driver(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git and POSIX shell are required for filter coverage.');
        }

        $root = sys_get_temp_dir().'/haocode-git-filter-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $file = $root.'/tracked.txt';
        $attributes = $root.'/.gitattributes';
        $marker = $root.'/filter-ran';
        $script = $root.'/filter.sh';

        try {
            file_put_contents($file, "old\n");
            file_put_contents($attributes, "*.txt filter=evil\n");
            file_put_contents($script, "#!/bin/sh\nprintf ran > ".escapeshellarg($marker)."\ncat\n");
            chmod($script, 0700);

            $this->runGit($root, 'init');
            // Stage the initial version before defining the filter command so
            // setup itself cannot create a false positive marker.
            $this->runGit($root, 'add .gitattributes tracked.txt');
            $this->runGit($root, 'config filter.evil.clean '.escapeshellarg($script));
            file_put_contents($file, "new\n");

            $diff = DiffGenerator::gitDiff($file);

            $this->assertSame('', $diff);
            $this->assertFileDoesNotExist($marker, 'Repository filter commands must not run during supplemental Git diffs.');
        } finally {
            $this->removeTree($root);
        }
    }

    public function test_hardened_git_runner_honors_abort_and_kills_delayed_side_effects(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX shell script is used to simulate git abort behavior.');
        }

        $root = sys_get_temp_dir().'/haocode-git-abort-'.bin2hex(random_bytes(6));
        $binDir = $root.'/bin';
        mkdir($binDir, 0700, true);
        $marker = $root.'/marker';
        $fakeGit = $binDir.'/git';
        file_put_contents($fakeGit, "#!/bin/sh\nsleep 1\nprintf leaked > ".escapeshellarg($marker)."\nprintf done\n");
        chmod($fakeGit, 0700);

        $oldPath = getenv('PATH');
        $start = microtime(true);
        try {
            putenv('PATH='.$binDir.PATH_SEPARATOR.(is_string($oldPath) ? $oldPath : ''));

            $result = (new HardenedGitRunner())->runGit(
                $root,
                ['--no-pager', 'diff'],
                5.0,
                static fn (): bool => microtime(true) >= $start + 0.05,
            );

            $this->assertTrue($result['aborted']);
            $this->assertSame(130, $result['exitCode']);

            usleep(1_100_000);
            $this->assertFileDoesNotExist($marker, 'Aborted internal git diff must terminate delayed subprocess side effects');
        } finally {
            if (is_string($oldPath)) {
                putenv('PATH='.$oldPath);
            } else {
                putenv('PATH');
            }
            $this->removeTree($root);
        }
    }

    public function test_file_mutation_tools_pass_abort_signal_to_git_diff(): void
    {
        $editSource = file_get_contents(dirname(__DIR__, 2).'/app/Tools/FileEdit/FileEditTool.php');
        $writeSource = file_get_contents(dirname(__DIR__, 2).'/app/Tools/FileWrite/FileWriteTool.php');

        $this->assertIsString($editSource);
        $this->assertIsString($writeSource);
        $this->assertStringContainsString('DiffGenerator::gitDiff($filePath, $context->isAborted(...))', $editSource);
        $this->assertStringContainsString('DiffGenerator::gitDiff($filePath, $context->isAborted(...))', $writeSource);
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
