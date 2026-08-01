<?php

namespace Tests\Unit;

use HaoCode\Tools\FileEdit\FileEditTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class FileEditToolTest extends TestCase
{
    private FileEditTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new FileEditTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    private function makeTmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edit_test_');
        file_put_contents($path, $content);
        // Record file as read so edit read-before-write enforcement passes
        $this->context->recordFileRead($path);
        return $path;
    }

    // ─── Basic edits ───────────────────────────────────────────────────────

    public function test_it_replaces_old_string_with_new_string(): void
    {
        $file = $this->makeTmpFile("hello world\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'hello',
            'new_string' => 'goodbye',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("goodbye world\n", file_get_contents($file));

        unlink($file);
    }

    public function test_it_returns_error_when_file_does_not_exist(): void
    {
        $result = $this->tool->call([
            'file_path' => '/tmp/definitely_nonexistent_haocode.txt',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    public function test_it_returns_error_when_old_string_not_found(): void
    {
        $file = $this->makeTmpFile("line one\nline two\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'line three',
            'new_string' => 'replaced',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);

        unlink($file);
    }

    public function test_it_returns_error_when_old_string_not_unique(): void
    {
        $file = $this->makeTmpFile("foo\nfoo\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'foo',
            'new_string' => 'bar',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not unique', $result->output);
        $this->assertStringContainsString('2', $result->output); // reports count
        // File must NOT be changed
        $this->assertSame("foo\nfoo\n", file_get_contents($file));

        unlink($file);
    }

    public function test_it_returns_error_when_old_and_new_strings_are_identical(): void
    {
        $file = $this->makeTmpFile("same content\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'same content',
            'new_string' => 'same content',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('identical', $result->output);

        unlink($file);
    }

    public function test_replace_all_replaces_every_occurrence(): void
    {
        $file = $this->makeTmpFile("foo bar foo baz foo\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'foo',
            'new_string' => 'qux',
            'replace_all' => true,
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("qux bar qux baz qux\n", file_get_contents($file));

        unlink($file);
    }

    public function test_replace_all_false_replaces_only_first_occurrence(): void
    {
        // Use a string that appears exactly once (uniqueness check enforced when replace_all=false)
        $file = $this->makeTmpFile("alpha beta gamma\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'alpha',
            'new_string' => 'ALPHA',
            'replace_all' => false,
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("ALPHA beta gamma\n", file_get_contents($file));

        unlink($file);
    }

    // ─── Multi-line edits ──────────────────────────────────────────────────

    public function test_it_replaces_multiline_old_string(): void
    {
        $content = "function foo() {\n    return 1;\n}\n";
        $file = $this->makeTmpFile($content);

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => "return 1;\n}",
            'new_string' => "return 2;\n}",
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('return 2;', file_get_contents($file));

        unlink($file);
    }

    // ─── validateInput (sensitive file blocking) ───────────────────────────

    public function test_validate_input_blocks_env_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/var/www/.env',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
        $this->assertStringContainsString('sensitive', $error);
    }

    public function test_validate_input_blocks_pem_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/home/user/server.pem',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
    }

    public function test_validate_input_blocks_key_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/home/user/id_rsa',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
    }

    public function test_validate_input_allows_regular_php_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/var/www/app/Controllers/UserController.php',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNull($error);
    }

    public function test_validate_input_allows_large_file_when_replacement_is_targeted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'edit_large_');
        file_put_contents($file, str_repeat('padding line'."\n", 100_000).'needle'."\n");

        try {
            $error = $this->tool->validateInput([
                'file_path' => $file,
                'old_string' => 'needle',
                'new_string' => 'replacement',
            ], $this->context);

            $this->assertNull($error);
        } finally {
            @unlink($file);
        }
    }

    public function test_large_file_edit_streams_replacement_without_loading_the_original(): void
    {
        $file = $this->makeTmpFile(str_repeat("padding\n", 200_000).'needle\n');

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'needle',
            'new_string' => 'replacement',
        ], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('large file', $result->output);
        $this->assertStringEndsWith('replacement\n', (string) file_get_contents($file));
        @unlink($file);
    }

    public function test_large_file_edit_rejects_a_binary_byte_beyond_the_prefix_sample(): void
    {
        $file = $this->makeTmpFile(str_repeat("padding\n", 150_000).'needle\n'."tail\0binary\n");
        $before = file_get_contents($file);

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'needle',
            'new_string' => 'replacement',
        ], $this->context);

        $this->assertTrue($result->isError, $result->output);
        $this->assertStringContainsString('binary', strtolower($result->output));
        $this->assertSame($before, file_get_contents($file));

        @unlink($file);
    }

    public function test_validate_input_rejects_huge_replacement_payloads(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/tmp/regular.txt',
            'old_string' => 'needle',
            'new_string' => str_repeat('x', 512_001),
        ], $this->context);

        $this->assertNotNull($error);
        $this->assertStringContainsString('smaller targeted replacement', $error);
    }

    public function test_it_reports_recovery_hint_when_edit_requires_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edit_guardrail_');
        file_put_contents($path, "hello world\n");

        $freshContext = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );

        $result = $this->tool->call([
            'file_path' => $path,
            'old_string' => 'hello',
            'new_string' => 'goodbye',
        ], $freshContext);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Read tool first', $result->output);
        $this->assertStringContainsString($path, $result->output);
        $this->assertStringContainsString('Next step: call Read', $result->output);

        unlink($path);
    }

    public function test_it_rejects_edit_after_external_change(): void
    {
        $file = $this->makeTmpFile("revision one\n");
        file_put_contents($file, "external revision two\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'revision one',
            'new_string' => 'agent revision',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('changed since it was read', $result->output);
        $this->assertSame("external revision two\n", file_get_contents($file));
        unlink($file);
    }

    public function test_it_refuses_to_edit_binary_files_with_null_bytes(): void
    {
        $file = $this->makeTmpFile("hello\0world");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'hello',
            'new_string' => 'goodbye',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('binary', strtolower($result->output));

        unlink($file);
    }

    public function test_looks_binary_guards_mime_content_type_with_function_exists(): void
    {
        // Structural: Edit must not call mime_content_type unconditionally
        // (ext-fileinfo is optional; missing it previously fatals).
        $src = file_get_contents(dirname(__DIR__, 2).'/app/Tools/FileEdit/FileEditTool.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString("function_exists('mime_content_type')", $src);
        $this->assertMatchesRegularExpression(
            '/function_exists\(\'mime_content_type\'\)\s*\?\s*@?mime_content_type/',
            $src,
        );
    }
}
