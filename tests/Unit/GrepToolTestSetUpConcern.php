<?php

namespace Tests\Unit;

use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait GrepToolTestSetUpConcern
{

    protected function setUp(): void
    {
        $this->tool = new GrepTool;
        $this->tmpDir = sys_get_temp_dir() . '/grep_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);
        $this->context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test-session',
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Call grepWithPhp directly to test it independently of rg availability.
     */
    private function grepPhp(
        string $pattern,
        ?string $path = null,
        string $outputMode = 'content',
        ?string $glob = null,
        bool $caseInsensitive = false,
        int $afterLines = 0,
        int $beforeLines = 0,
        int $headLimit = 250,
        int $offset = 0,
        ?string $type = null,
        bool $multiline = false,
        ?callable $shouldAbort = null,
    ): ToolResult {
        $ref = new \ReflectionClass(GrepTool::class);
        $method = $ref->getMethod('grepWithPhp');
        $method->setAccessible(true);

        return $method->invoke(
            $this->tool,
            $pattern,
            $path ?? $this->tmpDir,
            $outputMode,
            $glob,
            $caseInsensitive,
            $afterLines,
            $beforeLines,
            $headLimit,
            $offset,
            $type,
            $multiline,
            $shouldAbort,
        );
    }

    private function grepRg(
        string $pattern,
        string $path,
        string $outputMode = 'content',
        ?string $glob = null,
        ?string $type = null,
        bool $caseInsensitive = false,
        bool $multiline = false,
        int $afterLines = 0,
        int $beforeLines = 0,
        int $headLimit = 250,
        int $offset = 0,
    ): ToolResult {
        $method = (new \ReflectionClass(GrepTool::class))->getMethod('grepWithRipgrep');
        $method->setAccessible(true);

        return $method->invoke(
            $this->tool,
            $pattern,
            $path,
            $outputMode,
            $glob,
            $type,
            $caseInsensitive,
            $multiline,
            $afterLines,
            $beforeLines,
            $headLimit,
            $offset,
        );
    }

    private function hasRipgrep(): bool
    {
        $method = (new \ReflectionClass(GrepTool::class))->getMethod('hasRipgrep');
        $method->setAccessible(true);

        return $method->invoke($this->tool);
    }

    public function test_ripgrep_discovery_does_not_shell_out(): void
    {
        $source = file_get_contents((new \ReflectionClass(GrepTool::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('command -v rg', $source);
        $this->assertStringNotContainsString('where rg', $source);
    }

    public function test_it_finds_matching_lines_in_content_mode(): void
    {
        $this->writeFile('a.txt', "hello world\nfoo bar\nhello again\n");

        $result = $this->grepPhp('hello');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('hello world', $result->output);
        $this->assertStringNotContainsString('foo bar', $result->output);
    }

    public function test_it_returns_no_matches_message_when_nothing_found(): void
    {
        $this->writeFile('b.txt', "nothing here\n");

        $result = $this->grepPhp('zzzmatchnothing');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No matches', $result->output);
    }

    public function test_it_returns_error_for_invalid_regex(): void
    {
        $result = $this->grepPhp('(unclosed[');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid regex', $result->output);
    }

    public function test_validate_input_bounds_search_work_and_result_limits(): void
    {
        $this->assertStringContainsString(
            'head_limit',
            (string) $this->tool->validateInput(['pattern' => 'needle', 'head_limit' => 1_001], $this->context),
        );
        $this->assertStringContainsString(
            '-a',
            strtolower((string) $this->tool->validateInput(['pattern' => 'needle', '-A' => 1_001], $this->context)),
        );
        $this->assertStringContainsString(
            'pattern',
            (string) $this->tool->validateInput(['pattern' => str_repeat('x', 16_385)], $this->context),
        );
    }

    public function test_files_with_matches_mode_returns_only_file_paths(): void
    {
        $this->writeFile('match.txt', "needle\n");
        $this->writeFile('nomatch.txt', "haystack\n");

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertStringContainsString('match.txt', $result->output);
        $this->assertStringNotContainsString('nomatch.txt', $result->output);
    }

    public function test_count_mode_returns_match_counts(): void
    {
        $this->writeFile('counted.txt', "foo\nfoo\nbar\nfoo\n");

        $result = $this->grepPhp('foo', outputMode: 'count');

        $this->assertStringContainsString('counted.txt:3', $result->output);
    }

    public function test_case_insensitive_matches_regardless_of_case(): void
    {
        $this->writeFile('case.txt', "HELLO\nWorld\nhello\n");

        $result = $this->grepPhp('hello', caseInsensitive: true);

        $this->assertStringContainsString('HELLO', $result->output);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_glob_filter_excludes_non_matching_extensions(): void
    {
        $this->writeFile('code.php', "needle\n");
        $this->writeFile('doc.md', "needle\n");

        $result = $this->grepPhp('needle', glob: '*.php', outputMode: 'files_with_matches');

        $this->assertStringContainsString('code.php', $result->output);
        $this->assertStringNotContainsString('doc.md', $result->output);
    }

    public function test_glob_filter_works_with_subdirectory_path_pattern(): void
    {
        // Create a nested structure: src/code.php and docs/code.php
        // A glob of 'src/*.php' should match only the one in src/
        $srcDir = $this->tmpDir . '/src';
        $docsDir = $this->tmpDir . '/docs';
        mkdir($srcDir, 0755, true);
        mkdir($docsDir, 0755, true);
        file_put_contents($srcDir . '/code.php', "needle\n");
        file_put_contents($docsDir . '/code.php', "needle\n");

        $result = $this->grepPhp('needle', glob: 'src/*.php', outputMode: 'files_with_matches');

        $this->assertStringContainsString('src/code.php', $result->output);
        $this->assertStringNotContainsString('docs/code.php', $result->output,
            'docs/code.php should be excluded by glob src/*.php');

        // Cleanup
        unlink($srcDir . '/code.php');
        unlink($docsDir . '/code.php');
        rmdir($srcDir);
        rmdir($docsDir);
    }

    public function test_head_limit_caps_number_of_matches(): void
    {
        $lines = implode("\n", array_fill(0, 20, 'match'));
        $this->writeFile('many.txt', $lines . "\n");

        $result = $this->grepPhp('match', headLimit: 3);

        $matchCount = substr_count($result->output, 'match');
        $this->assertLessThanOrEqual(3, $matchCount);
    }

    public function test_php_fallback_rejects_pathological_single_line_files(): void
    {
        $this->writeFile('huge-line.txt', str_repeat('x', 1_000_001)."\n");

        $result = $this->grepPhp('needle');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('line larger than', $result->output);
    }

    public function test_php_fallback_bounds_large_context_windows(): void
    {
        $line = str_repeat('x', 20_000);
        $this->writeFile('large-context.txt', implode("\n", array_fill(0, 60, $line))."\nneedle\n");

        $result = $this->grepPhp('needle', beforeLines: 60);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('retaining more than', $result->output);
    }

    public function test_php_fallback_applies_global_offset_and_head_limit_across_files(): void
    {
        $this->writeFile('a.txt', "match-a\nmatch-b\n");
        $this->writeFile('b.txt', "match-c\nmatch-d\n");

        $result = $this->grepPhp('match', headLimit: 2, offset: 1);

        $this->assertSame(
            "a.txt:2:match-b\nb.txt:1:match-c",
            $result->output,
        );
    }

    public function test_head_limit_zero_uses_consistent_no_match_result(): void
    {
        $this->writeFile('a.txt', "match\n");

        $result = $this->grepPhp('match', headLimit: 0);

        $this->assertSame('No matches found for pattern: match', $result->output);
    }

    public function test_it_searches_a_single_file_directly(): void
    {
        $file = $this->writeFile('single.txt', "apple\nbanana\napricot\n");

        $result = $this->grepPhp('apple', path: $file);

        $this->assertStringContainsString('apple', $result->output);
        $this->assertStringNotContainsString('banana', $result->output);
    }

    public function test_after_context_lines_are_included(): void
    {
        $this->writeFile('ctx.txt', "before\nmatch_line\nafter1\nafter2\nunrelated\n");

        $result = $this->grepPhp('match_line', afterLines: 2);

        $this->assertStringContainsString('match_line', $result->output);
        $this->assertStringContainsString('after1', $result->output);
        $this->assertStringContainsString('after2', $result->output);
        $this->assertStringNotContainsString('unrelated', $result->output);
    }

    public function test_before_context_lines_are_included(): void
    {
        $this->writeFile('bctx.txt', "unrelated\nbefore2\nbefore1\nmatch_line\nafter\n");

        $result = $this->grepPhp('match_line', beforeLines: 2);

        $this->assertStringContainsString('match_line', $result->output);
        $this->assertStringContainsString('before2', $result->output);
        $this->assertStringContainsString('before1', $result->output);
        $this->assertStringNotContainsString('unrelated', $result->output);
    }

    public function test_context_lines_do_not_duplicate_when_matches_are_adjacent(): void
    {
        $this->writeFile('adj.txt', "match1\nmatch2\nother\n");

        $result = $this->grepPhp('match', afterLines: 1);

        // match1 and match2 are adjacent; match1's after-context is match2,
        // which is itself a match — it should appear exactly once
        $count = substr_count($result->output, 'match2');
        $this->assertSame(1, $count);
        $this->assertStringContainsString('adj.txt:2:match2', $result->output);
    }

    public function test_no_context_lines_without_flags(): void
    {
        $this->writeFile('noctx.txt', "before\nmatch_here\nafter\n");

        $result = $this->grepPhp('match_here');

        $this->assertStringContainsString('match_here', $result->output);
        $this->assertStringNotContainsString('before', $result->output);
        $this->assertStringNotContainsString('after', $result->output);
    }

    public function test_pattern_with_forward_slash_is_not_rejected_as_invalid_regex(): void
    {
        // Patterns like `app/Services` or `foo/bar` contain `/` which is the PHP
        // regex delimiter. Before the fix this would cause grepWithPhp() to report
        // an invalid regex error instead of searching for the literal slash.
        $this->writeFile('paths.txt', "app/Services/Foo.php\napp/Models/Bar.php\n");

        $result = $this->grepPhp('app/Services');

        $this->assertFalse($result->isError, 'Pattern with / should not be treated as invalid regex');
        $this->assertStringContainsString('app/Services/Foo.php', $result->output);
    }

    public function test_pattern_with_forward_slash_matches_correctly(): void
    {
        $this->writeFile('urls.txt', "https://example.com/api/v1\nhttps://example.com/home\n");

        $result = $this->grepPhp('api/v1');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('api/v1', $result->output);
        $this->assertStringNotContainsString('home', $result->output);
    }

    public function test_relative_search_path_is_resolved_against_tool_context(): void
    {
        mkdir($this->tmpDir.'/sub', 0700);
        file_put_contents($this->tmpDir.'/sub/file.txt', "needle\n");

        $result = $this->tool->call(
            ['pattern' => 'needle', 'path' => 'sub', 'output_mode' => 'content'],
            $this->context,
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('file.txt:1:needle', $result->output);
    }

    public function test_windows_drive_and_unc_paths_are_normalized_case_insensitively(): void
    {
        $ref = new \ReflectionClass(GrepTool::class);
        $relative = $ref->getMethod('relativePath');
        $relative->setAccessible(true);
        $normalize = $ref->getMethod('normalizeOutputPath');
        $normalize->setAccessible(true);
        $stripFile = $ref->getMethod('stripFilePathPrefix');
        $stripFile->setAccessible(true);

        $this->assertSame(
            'src/Services/App.php',
            $relative->invoke(
                $this->tool,
                'c:\\workspace\\project\\src\\Services\\App.php',
                'C:\\Workspace\\Project',
            ),
        );
        $this->assertSame(
            'nested/File.php:7:needle',
            $normalize->invoke(
                $this->tool,
                '\\\\server\\share\\project\\nested\\File.php:7:needle',
                '\\\\SERVER\\SHARE\\project',
            ),
        );
        $this->assertSame(
            ':3:needle',
            $stripFile->invoke(
                $this->tool,
                'c:\\workspace\\project\\App.php:3:needle',
                'C:\\Workspace\\Project\\App.php',
            ),
        );
    }

    public function test_backfill_canonicalizes_symlink_before_sensitive_path_check(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $outside = $this->tmpDir.'_outside/.ssh';
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/id_rsa', 'private-key');
        symlink($outside, $this->tmpDir.'/linked');

        try {
            $input = $this->tool->backfillObservableInput(
                ['pattern' => 'private-key', 'path' => 'linked'],
                $this->context,
            );

            $this->assertSame(realpath($outside), $input['path']);
            $this->assertSame(
                'SSH directory',
                SensitivePathGuard::check('Grep', $input),
            );
        } finally {
            @unlink($this->tmpDir.'/linked');
            @unlink($outside.'/id_rsa');
            @rmdir($outside);
            @rmdir(dirname($outside));
        }
    }
}
