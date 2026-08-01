<?php

namespace Tests\Unit;

use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Tools\Glob\GlobTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class GlobToolTest extends TestCase
{
    private GlobTool $tool;
    private string $tmpDir;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new GlobTool;
        $this->tmpDir = sys_get_temp_dir() . '/glob_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);
        $this->context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test',
        );
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir) as $item) {
            $this->rmdirRecursive($item->getPathname());
        }
        @rmdir($dir);
    }

    private function touch(string $relative, string $content = ''): void
    {
        $full = $this->tmpDir . '/' . $relative;
        $dir = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($full, $content);
    }

    private function call(array $input): \HaoCode\Tools\ToolResult
    {
        return $this->tool->call($input, $this->context);
    }

    // ─── non-existent directory ───────────────────────────────────────────

    public function test_it_returns_error_for_nonexistent_directory(): void
    {
        $result = $this->call([
            'pattern' => '*.php',
            'path' => '/tmp/no_such_dir_haocode_test',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    // ─── basic patterns ───────────────────────────────────────────────────

    public function test_it_matches_files_by_extension(): void
    {
        $this->touch('foo.php', '<?php');
        $this->touch('bar.js', 'console.log()');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('foo.php', $result->output);
        $this->assertStringNotContainsString('bar.js', $result->output);
    }

    public function test_it_returns_no_match_message_when_nothing_matches(): void
    {
        $this->touch('foo.txt');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No files matched', $result->output);
    }

    public function test_it_matches_files_in_subdirectories_with_double_star(): void
    {
        $this->touch('app/Services/Foo.php', '<?php');
        $this->touch('app/Controllers/Bar.php', '<?php');
        $this->touch('config/app.php', '<?php');

        $result = $this->call(['pattern' => '**/*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Foo.php', $result->output);
        $this->assertStringContainsString('Bar.php', $result->output);
        $this->assertStringContainsString('app.php', $result->output);
    }

    public function test_it_limits_matches_to_specific_subdirectory_with_path(): void
    {
        $this->touch('src/a.php', '<?php');
        $this->touch('lib/b.php', '<?php');

        $result = $this->call([
            'pattern' => '*.php',
            'path' => $this->tmpDir . '/src',
        ]);

        $this->assertStringContainsString('a.php', $result->output);
        $this->assertStringNotContainsString('b.php', $result->output);
    }

    public function test_it_matches_single_character_wildcard(): void
    {
        $this->touch('foo1.txt');
        $this->touch('foo2.txt');
        $this->touch('foobar.txt');

        $result = $this->call(['pattern' => 'foo?.txt']);

        $this->assertStringContainsString('foo1.txt', $result->output);
        $this->assertStringContainsString('foo2.txt', $result->output);
        $this->assertStringNotContainsString('foobar.txt', $result->output);
    }

    public function test_it_counts_matches_correctly(): void
    {
        $this->touch('a.php', '<?php');
        $this->touch('b.php', '<?php');
        $this->touch('c.php', '<?php');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertStringContainsString('3 file(s)', $result->output);
    }

    public function test_it_uses_working_directory_as_default_path(): void
    {
        $this->touch('default.txt');

        $result = $this->call(['pattern' => '*.txt']);

        $this->assertStringContainsString('default.txt', $result->output);
    }

    public function test_relative_path_is_resolved_against_working_directory(): void
    {
        $this->touch('sub/inside.php', '<?php');

        $result = $this->call(['pattern' => '*.php', 'path' => 'sub']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('inside.php', $result->output);
    }

    public function test_backfill_canonicalizes_symlink_before_sensitive_path_check(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $outside = $this->tmpDir.'_outside/.ssh';
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/id_rsa', 'private');
        symlink($outside, $this->tmpDir.'/linked');

        try {
            $input = $this->tool->backfillObservableInput(
                ['pattern' => '*', 'path' => 'linked'],
                $this->context,
            );

            $this->assertSame(realpath($outside), $input['path']);
            $this->assertSame(
                'SSH directory',
                SensitivePathGuard::check('Glob', $input),
            );
        } finally {
            @unlink($this->tmpDir.'/linked');
            @unlink($outside.'/id_rsa');
            @rmdir($outside);
            @rmdir(dirname($outside));
        }
    }

    public function test_it_normalizes_leading_dot_slash_patterns(): void
    {
        $this->touch('main.go', 'package main');

        $result = $this->call(['pattern' => './*.go']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('main.go', $result->output);
    }

    public function test_it_supports_brace_expansion_for_multiple_extensions(): void
    {
        $this->touch('src/App.jsx', 'export default function App() {}');
        $this->touch('src/main.js', 'console.log("ok")');
        $this->touch('backend/package.json', '{}');
        $this->touch('frontend/index.html', '<div id="root"></div>');
        $this->touch('notes.txt', 'skip');

        $result = $this->call(['pattern' => '**/*.{js,jsx,json,md}']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('App.jsx', $result->output);
        $this->assertStringContainsString('main.js', $result->output);
        $this->assertStringContainsString('backend/package.json', $result->output);
        $this->assertStringNotContainsString('notes.txt', $result->output);
    }

    public function test_double_star_star_matches_root_and_nested_files(): void
    {
        $this->touch('README.md', '# notes');
        $this->touch('server.js', 'console.log("ok")');
        $this->touch('frontend/index.html', '<div></div>');

        $result = $this->call(['pattern' => '**/*']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('README.md', $result->output);
        $this->assertStringContainsString('server.js', $result->output);
        $this->assertStringContainsString('frontend/index.html', $result->output);
        $this->assertStringContainsString('3 file(s)', $result->output);
    }

    public function test_it_skips_default_ignored_directories(): void
    {
        $this->touch('src/keep.php', '<?php');
        $this->touch('.git/ignored.php', '<?php');
        $this->touch('.claude/worktrees/demo/ignored.php', '<?php');
        $this->touch('vendor/package/ignored.php', '<?php');

        $result = $this->call(['pattern' => '**/*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src/keep.php', $result->output);
        $this->assertStringNotContainsString('.git/ignored.php', $result->output);
        $this->assertStringNotContainsString('.claude/worktrees/demo/ignored.php', $result->output);
        $this->assertStringNotContainsString('vendor/package/ignored.php', $result->output);
    }

    public function test_it_respects_root_gitignore(): void
    {
        $this->touch('.gitignore', "ignored-dir/\n*.log\n!important.log\n");
        $this->touch('src/keep.php', '<?php');
        $this->touch('ignored-dir/hidden.php', '<?php');
        $this->touch('debug.log', 'log');
        $this->touch('important.log', 'log');

        $result = $this->call(['pattern' => '**/*']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src/keep.php', $result->output);
        $this->assertStringContainsString('important.log', $result->output);
        $this->assertStringNotContainsString('ignored-dir/hidden.php', $result->output);
        $this->assertStringNotContainsString('debug.log', $result->output);
    }

    public function test_it_descends_ignored_directories_for_negated_gitignore_entries(): void
    {
        $this->touch('.gitignore', "ignored-dir/\n!ignored-dir/keep.php\n");
        $this->touch('ignored-dir/hidden.php', '<?php');
        $this->touch('ignored-dir/keep.php', '<?php');

        $result = $this->call(['pattern' => '**/*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('ignored-dir/keep.php', $result->output);
        $this->assertStringNotContainsString('ignored-dir/hidden.php', $result->output);
    }

    public function test_it_rejects_an_unbounded_gitignore_file(): void
    {
        $this->touch('.gitignore', str_repeat("ignored-*.tmp\n", 80_000));

        $result = $this->call(['pattern' => '**/*']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('.gitignore', $result->output);
    }

    public function test_it_prunes_default_ignored_directories_before_recursing(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission coverage.');
        }

        $this->touch('src/keep.php', '<?php');
        mkdir($this->tmpDir.'/vendor', 0755, true);
        $locked = $this->tmpDir.'/vendor/locked';
        mkdir($locked, 0000);

        try {
            $result = $this->call(['pattern' => '**/*.php']);

            $this->assertFalse($result->isError);
            $this->assertStringContainsString('src/keep.php', $result->output);
            $this->assertStringNotContainsString('vendor', $result->output);

            $source = file_get_contents((new \ReflectionClass(GlobTool::class))->getFileName());
            $this->assertIsString($source);
            $this->assertStringContainsString('RecursiveCallbackFilterIterator', $source);
            $this->assertStringContainsString('CATCH_GET_CHILD', $source);
        } finally {
            @chmod($locked, 0700);
        }
    }

    public function test_it_returns_aborted_when_context_requests_cancel(): void
    {
        $this->touch('src/keep.php', '<?php');
        $context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test',
            shouldAbort: static fn (): bool => true,
        );

        $result = $this->tool->call(['pattern' => '**/*.php'], $context);

        $this->assertSame(ToolOutcome::Aborted, $result->outcome());
    }

    public function test_it_rejects_excessive_brace_expansion(): void
    {
        $pattern = str_repeat('{a,b}', 9).'*.php';

        $result = $this->call(['pattern' => $pattern]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('brace expansion', $result->output);
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    // ─── globToRegex (via reflection) ─────────────────────────────────────

    private function globToRegex(string $pattern): string
    {
        $ref = new \ReflectionClass(GlobTool::class);
        $method = $ref->getMethod('globToRegex');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $pattern);
    }

    private function relativePath(string $filePath, string $basePath): string
    {
        $method = (new \ReflectionClass(GlobTool::class))->getMethod('relativePath');
        $method->setAccessible(true);

        return $method->invoke($this->tool, $filePath, $basePath);
    }

    public function test_relative_path_normalizes_windows_drive_and_unc_separators(): void
    {
        $this->assertSame(
            'src/Services/App.php',
            $this->relativePath(
                'C:\\Workspace\\Project\\src\\Services\\App.php',
                'c:\\workspace\\project',
            ),
        );
        $this->assertSame(
            'nested/File.php',
            $this->relativePath(
                '\\\\server\\share\\project\\nested\\File.php',
                '\\\\SERVER\\SHARE\\project',
            ),
        );
    }

    public function test_glob_to_regex_converts_star_to_non_slash_match(): void
    {
        $regex = $this->globToRegex('*.php');
        $this->assertMatchesRegularExpression($regex, 'foo.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'foo/bar.php');
    }

    public function test_glob_to_regex_converts_double_star_to_any(): void
    {
        $regex = $this->globToRegex('**/*.php');
        // **/ matches zero-or-more path segments
        $this->assertMatchesRegularExpression($regex, 'src/Controllers/FooController.php');
        $this->assertMatchesRegularExpression($regex, 'a/b.php');
        $this->assertMatchesRegularExpression($regex, 'foo.php');
    }

    public function test_glob_to_regex_converts_question_mark(): void
    {
        $regex = $this->globToRegex('foo?.txt');
        $this->assertMatchesRegularExpression($regex, 'fooa.txt');
        $this->assertDoesNotMatchRegularExpression($regex, 'fooab.txt');
    }
}
