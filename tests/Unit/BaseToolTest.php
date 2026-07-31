<?php

namespace Tests\Unit;

use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class BaseToolTest extends TestCase
{
    private BaseTool $tool;
    private ToolUseContext $context;
    private string $testDir;

    protected function setUp(): void
    {
        // Concrete subclass for testing
        $this->tool = new class extends BaseTool {
            public function name(): string { return 'TestTool'; }
            public function description(): string { return 'A test tool'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []], []);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };

        $this->testDir = sys_get_temp_dir().'/base_tool_'.bin2hex(random_bytes(6));
        mkdir($this->testDir, 0700, true);
        $this->context = new ToolUseContext($this->testDir, 'test');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->testDir.'/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->testDir);
    }

    // ─── defaults ─────────────────────────────────────────────────────────

    public function test_is_read_only_defaults_to_false(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
    }

    public function test_is_concurrency_safe_mirrors_is_read_only(): void
    {
        $this->assertFalse($this->tool->isConcurrencySafe([]));
    }

    public function test_read_only_tool_is_concurrency_safe(): void
    {
        $tool = new class extends BaseTool {
            public function name(): string { return 'T'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make([], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success(''); }
            public function isReadOnly(array $input): bool { return true; }
        };

        $this->assertTrue($tool->isConcurrencySafe([]));
    }

    public function test_is_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function test_user_facing_name_defaults_to_name(): void
    {
        $this->assertSame('TestTool', $this->tool->userFacingName([]));
    }

    public function test_check_permissions_allows_by_default(): void
    {
        $decision = $this->tool->checkPermissions([], $this->context);
        $this->assertTrue($decision->allowed);
    }

    public function test_validate_input_returns_null_by_default(): void
    {
        $this->assertNull($this->tool->validateInput([], $this->context));
    }

    public function test_max_result_size_is_50000(): void
    {
        $this->assertSame(50000, $this->tool->maxResultSizeChars());
    }

    public function test_backfill_observable_input_returns_input_unchanged(): void
    {
        $input = ['key' => 'value'];
        $this->assertSame($input, $this->tool->backfillObservableInput($input, $this->context));
    }

    // ─── resolvePath ──────────────────────────────────────────────────────

    private function resolvePath(string $path, string $wd): string
    {
        $m = (new \ReflectionClass($this->tool))->getMethod('resolvePath');
        $m->setAccessible(true);
        return $m->invoke($this->tool, $path, $wd);
    }

    public function test_resolve_absolute_path_unchanged(): void
    {
        $result = $this->resolvePath('/absolute/path', '/working');
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertMatchesRegularExpression('/^[A-Za-z]:\\\\absolute\\\\path$/', $result);
        } else {
            $this->assertStringStartsWith('/absolute', $result);
        }
    }

    public function test_resolve_relative_path_prepends_working_dir(): void
    {
        // Use sys_get_temp_dir() as working dir, create a real path to resolve
        $tmpDir = sys_get_temp_dir();
        // relative path that exists — just use the tmp dir itself
        $result = $this->resolvePath('', $tmpDir);
        // Empty relative resolves to workdir
        $this->assertSame(realpath($tmpDir), $result);
    }

    public function test_resolve_tilde_path_expands_home(): void
    {
        $home = $this->homeDirectory();
        $result = $this->resolvePath('~/somesubdir', $home);
        $this->assertStringStartsWith((string) realpath($home), $result);
        $this->assertStringNotContainsString('~', $result);
    }

    public function test_resolve_tilde_only_expands_home(): void
    {
        $home = $this->homeDirectory();
        $result = $this->resolvePath('~', $home);
        $this->assertSame(realpath($home), $result);
        $this->assertStringNotContainsString('~', $result);
    }

    public function test_resolve_nonexistent_target_through_existing_symlink_parent(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $outside = $this->testDir.'_outside';
        mkdir($outside, 0700, true);
        symlink($outside, $this->testDir.'/link');

        try {
            $result = $this->resolvePath('link/new-file.txt', $this->testDir);

            $this->assertSame(
                realpath($outside).'/new-file.txt',
                $result,
            );
        } finally {
            @unlink($this->testDir.'/link');
            @rmdir($outside);
        }
    }

    public function test_resolve_preserves_physical_dot_dot_semantics_after_symlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlink coverage.');
        }

        $outside = $this->testDir.'_outside';
        mkdir($outside.'/inner', 0700, true);
        mkdir($outside.'/sensitive', 0700, true);
        symlink($outside.'/inner', $this->testDir.'/link');

        try {
            $result = $this->resolvePath('link/../sensitive/new.txt', $this->testDir);

            $this->assertSame(
                realpath($outside.'/sensitive').'/new.txt',
                $result,
            );
        } finally {
            @unlink($this->testDir.'/link');
            @rmdir($outside.'/inner');
            @rmdir($outside.'/sensitive');
            @rmdir($outside);
        }
    }

    public function test_resolve_normalizes_dot_segments_for_nonexistent_path(): void
    {
        $result = $this->resolvePath('one/../two/./new.txt', $this->testDir);

        $this->assertSame(
            realpath($this->testDir).DIRECTORY_SEPARATOR.'two'.DIRECTORY_SEPARATOR.'new.txt',
            $result,
        );
    }

    public function test_resolve_does_not_expand_unsupported_named_tilde(): void
    {
        $result = $this->resolvePath('~another-user/file.txt', $this->testDir);

        $this->assertSame(
            realpath($this->testDir).DIRECTORY_SEPARATOR.'~another-user'.DIRECTORY_SEPARATOR.'file.txt',
            $result,
        );
    }

    public function test_resolve_normalizes_windows_drive_path_without_treating_it_as_relative(): void
    {
        $result = $this->resolvePath('C:\\work\\one\\..\\two.txt', $this->testDir);

        $this->assertSame('C:\\work\\two.txt', $result);
    }

    public function test_resolve_normalizes_windows_unc_path(): void
    {
        $result = $this->resolvePath('\\\\server\\share\\one\\..\\two.txt', $this->testDir);

        $this->assertSame('\\\\server\\share\\two.txt', strtolower($result));
    }

    public function test_resolve_windows_root_relative_path_uses_working_drive(): void
    {
        $this->assertSame(
            'D:\\rooted\\note.txt',
            CanonicalPathResolver::resolve('\\rooted\\note.txt', 'D:\\workspace\\repo'),
        );
    }

    public function test_windows_containment_is_case_insensitive_and_boundary_aware(): void
    {
        $this->assertTrue(CanonicalPathResolver::isWithin(
            'c:\\WORKSPACE\\repo\\src\\File.php',
            'C:\\workspace\\repo',
        ));
        $this->assertFalse(CanonicalPathResolver::isWithin(
            'C:\\workspace\\repository\\File.php',
            'C:\\workspace\\repo',
        ));
        $this->assertFalse(CanonicalPathResolver::isWithin(
            'D:\\workspace\\repo\\File.php',
            'C:\\workspace\\repo',
        ));
        $this->assertTrue(CanonicalPathResolver::isWithin(
            '\\\\Server\\Share\\Repo\\src\\File.php',
            '\\\\server\\share\\repo',
        ));
        $this->assertFalse(CanonicalPathResolver::isWithin(
            '\\\\server\\share-two\\repo\\File.php',
            '\\\\server\\share\\repo',
        ));
    }

    private function homeDirectory(): string
    {
        foreach ([
            getenv('HOME'),
            getenv('USERPROFILE'),
            (getenv('HOMEDRIVE') ?: '').(getenv('HOMEPATH') ?: ''),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_dir($candidate)) {
                return $candidate;
            }
        }

        return sys_get_temp_dir();
    }
}
