<?php

namespace Tests\Tools\FileEdit;

use HaoCode\Services\FileEdit\HunkSequencer;
use HaoCode\Services\FileEdit\PatchApplier;
use HaoCode\Services\FileEdit\PatchEnvelopeParser;
use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\FileEdit\ApplyPatchTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ApplyPatchToolTest extends TestCase
{
    private ApplyPatchTool $tool;

    private ToolUseContext $context;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/apply_patch_test_'.uniqid();
        mkdir($this->tmpDir, 0755, true);

        $parser = new PatchEnvelopeParser;
        $applier = new PatchApplier(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session'),
        );

        $this->tool = new ApplyPatchTool($applier, $parser, PhoenixTracer::fromConfig(['enabled' => false]));

        $this->context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test-session',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        ToolUseContext::resetReadState();
    }

    // ─── Scenario 1: Add File ─────────────────────────────────────────────

    public function test_add_file_creates_new_file(): void
    {
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Add File: hello.txt',
            'Hello, world!',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('Added: hello.txt', $result->output);
        $this->assertFileExists($this->tmpDir.'/hello.txt');
        $this->assertSame('Hello, world!', file_get_contents($this->tmpDir.'/hello.txt'));
    }

    // ─── Scenario 2: Update File ──────────────────────────────────────────

    public function test_update_file_applies_hunk(): void
    {
        $filePath = $this->tmpDir.'/greet.txt';
        file_put_contents($filePath, "Hello, world!\nGoodbye!\n");
        $this->context->recordFileRead($filePath);

        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: greet.txt',
            '*** Begin Hunk',
            ' Hello, world!',
            '-Goodbye!',
            '+See you later!',
            '*** End Hunk',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('Updated: greet.txt', $result->output);
        $content = file_get_contents($filePath);
        $this->assertStringContainsString('See you later!', $content);
        $this->assertStringNotContainsString('Goodbye!', $content);
    }

    // ─── Scenario 3: Delete File ──────────────────────────────────────────

    public function test_delete_file_removes_it(): void
    {
        $filePath = $this->tmpDir.'/to_delete.txt';
        file_put_contents($filePath, 'remove me');
        $this->context->recordFileRead($filePath);

        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Delete File: to_delete.txt',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('Deleted: to_delete.txt', $result->output);
        $this->assertFileDoesNotExist($filePath);
    }

    // ─── Scenario 4: Precheck fails — Read-before-Write not satisfied ─────

    public function test_precheck_fails_when_file_not_read(): void
    {
        $filePath = $this->tmpDir.'/unread.txt';
        file_put_contents($filePath, "line1\nline2\n");

        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: unread.txt',
            '*** Begin Hunk',
            ' line1',
            '-line2',
            '+line2 modified',
            '*** End Hunk',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Read-before-Write', $result->output);
        $this->assertSame("line1\nline2\n", file_get_contents($filePath));
    }

    // ─── Scenario 5: Second file staging fails → first file rolled back ───

    public function test_second_file_failure_rolls_back_first_file(): void
    {
        $file1 = $this->tmpDir.'/file1.txt';
        $file2 = $this->tmpDir.'/file2.txt';
        file_put_contents($file1, "alpha\nbeta\n");
        file_put_contents($file2, "gamma\ndelta\n");
        $this->context->recordFileRead($file1);
        $this->context->recordFileRead($file2);

        $originalFile1 = file_get_contents($file1);
        $originalFile2 = file_get_contents($file2);

        // file2 hunk has a context line that does not exist — seek_sequence will fail in stage
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: file1.txt',
            '*** Begin Hunk',
            ' alpha',
            '-beta',
            '+beta_modified',
            '*** End Hunk',
            '*** Update File: file2.txt',
            '*** Begin Hunk',
            ' THIS_LINE_DOES_NOT_EXIST',
            '-delta',
            '+delta_modified',
            '*** End Hunk',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertSame($originalFile1, file_get_contents($file1));
        $this->assertSame($originalFile2, file_get_contents($file2));
    }

    // ─── Scenario 6: SecretScanner rejects add with secret content ────────

    public function test_secret_scanner_rejects_new_file_with_secret(): void
    {
        $fakeKey = 'sk-ant-api03-'.str_repeat('A', 93).'AA';
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Add File: secrets.txt',
            "API_KEY={$fakeKey}",
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Secret detected', $result->output);
        $this->assertFileDoesNotExist($this->tmpDir.'/secrets.txt');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}
