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
            new FileHistoryManager(null, $this->tmpDir.'/.history'),
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
        $this->assertFileExists(
            $this->tmpDir.'/.history/'.hash('sha256', 'test-session').'/manifest.json',
        );
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

    public function test_duplicate_canonical_target_is_rejected_before_mutation(): void
    {
        $filePath = $this->tmpDir.'/duplicate.txt';
        file_put_contents($filePath, "before\n");
        $this->context->recordFileRead($filePath);

        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: duplicate.txt',
            '*** Begin Hunk',
            '-before',
            '+after',
            '*** End Hunk',
            '*** Delete File: ./duplicate.txt',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Duplicate patch target', $result->output);
        $this->assertSame("before\n", file_get_contents($filePath));
    }

    public function test_update_preserves_executable_mode(): void
    {
        $filePath = $this->tmpDir.'/script.sh';
        file_put_contents($filePath, "#!/bin/sh\necho before\n");
        chmod($filePath, 0755);
        $this->context->recordFileRead($filePath);

        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: script.sh',
            '*** Begin Hunk',
            ' #!/bin/sh',
            '-echo before',
            '+echo after',
            '*** End Hunk',
            '*** End Patch',
        ]);

        $result = $this->tool->call(['patch' => $patch], $this->context);

        clearstatcache(true, $filePath);
        $this->assertFalse($result->isError, $result->output);
        $this->assertSame(0755, fileperms($filePath) & 0777);
    }

    public function test_commit_failure_restores_all_files_and_removes_temporaries(): void
    {
        $file1 = $this->tmpDir.'/first.txt';
        $file2 = $this->tmpDir.'/second.txt';
        file_put_contents($file1, "first-old\n");
        file_put_contents($file2, "second-old\n");
        $this->context->recordFileRead($file1);
        $this->context->recordFileRead($file2);

        $parser = new PatchEnvelopeParser;
        $applier = new class(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session', $this->tmpDir.'/.failure-history'),
        ) extends PatchApplier {
            private int $moveCount = 0;

            protected function movePath(string $from, string $to): bool
            {
                $this->moveCount++;
                if ($this->moveCount === 3) {
                    return false;
                }

                return parent::movePath($from, $to);
            }
        };
        $tool = new ApplyPatchTool(
            $applier,
            $parser,
            PhoenixTracer::fromConfig(['enabled' => false]),
        );
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: first.txt',
            '*** Begin Hunk',
            '-first-old',
            '+first-new',
            '*** End Hunk',
            '*** Delete File: second.txt',
            '*** End Patch',
        ]);

        $result = $tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertSame("first-old\n", file_get_contents($file1));
        $this->assertSame("second-old\n", file_get_contents($file2));
        $this->assertSame([], glob($this->tmpDir.'/.haocode_patch_*') ?: []);
    }

    public function test_external_replacement_at_commit_is_not_overwritten_or_deleted(): void
    {
        $filePath = $this->tmpDir.'/raced.txt';
        file_put_contents($filePath, "original\n");
        $this->context->recordFileRead($filePath);

        $parser = new PatchEnvelopeParser;
        $applier = new class(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session', $this->tmpDir.'/.race-history'),
        ) extends PatchApplier {
            private bool $replaced = false;

            protected function beforeCommitOperation(
                \HaoCode\Services\FileEdit\PatchOperation $operation,
                string $target,
            ): void {
                if ($this->replaced) {
                    return;
                }
                $this->replaced = true;
                rename($target, $target.'.external-original');
                file_put_contents($target, "external\n");
            }
        };
        $tool = new ApplyPatchTool(
            $applier,
            $parser,
            PhoenixTracer::fromConfig(['enabled' => false]),
        );
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Update File: raced.txt',
            '*** Begin Hunk',
            '-original',
            '+patched',
            '*** End Hunk',
            '*** End Patch',
        ]);

        $result = $tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('changed after it was read', $result->output);
        $this->assertSame("external\n", file_get_contents($filePath));
        $this->assertSame("original\n", file_get_contents($filePath.'.external-original'));
        $this->assertSame([], glob($this->tmpDir.'/.haocode_patch_*') ?: []);
    }

    public function test_concurrent_add_target_is_reserved_and_external_file_is_preserved(): void
    {
        $filePath = $this->tmpDir.'/new-raced.txt';
        $parser = new PatchEnvelopeParser;
        $applier = new class(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session', $this->tmpDir.'/.add-race-history'),
        ) extends PatchApplier {
            protected function beforeCommitOperation(
                \HaoCode\Services\FileEdit\PatchOperation $operation,
                string $target,
            ): void {
                rename($target, $target.'.reservation');
                file_put_contents($target, 'external');
            }
        };
        $tool = new ApplyPatchTool(
            $applier,
            $parser,
            PhoenixTracer::fromConfig(['enabled' => false]),
        );
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Add File: new-raced.txt',
            'agent',
            '*** End Patch',
        ]);

        $result = $tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertSame('external', file_get_contents($filePath));
        $this->assertSame([], glob($this->tmpDir.'/.haocode_patch_*') ?: []);
    }

    public function test_parent_symlink_swap_before_reservation_fails_closed(): void
    {
        $outside = $this->tmpDir.'_outside';
        mkdir($outside, 0755, true);
        $parser = new PatchEnvelopeParser;
        $applier = new class(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session', $this->tmpDir.'/.symlink-history'),
        ) extends PatchApplier {
            public string $outside;

            protected function beforeAcquirePath(
                \HaoCode\Services\FileEdit\PatchOperation $operation,
                string $target,
            ): void {
                $parent = dirname($target);
                rename($parent, $parent.'.original');
                symlink($this->outside, $parent);
            }
        };
        $applier->outside = $outside;
        $tool = new ApplyPatchTool(
            $applier,
            $parser,
            PhoenixTracer::fromConfig(['enabled' => false]),
        );
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Add File: nested/file.txt',
            'must stay inside',
            '*** End Patch',
        ]);

        try {
            $result = $tool->call(['patch' => $patch], $this->context);

            $this->assertTrue($result->isError);
            $this->assertStringContainsString('Symlink detected', $result->output);
            $this->assertFileDoesNotExist($outside.'/file.txt');
        } finally {
            if (is_link($this->tmpDir.'/nested')) {
                unlink($this->tmpDir.'/nested');
            }
            if (is_dir($this->tmpDir.'/nested.original')) {
                rename($this->tmpDir.'/nested.original', $this->tmpDir.'/nested');
            }
            $this->removeDir($outside);
        }
    }

    public function test_failed_nested_add_removes_created_directory(): void
    {
        $parser = new PatchEnvelopeParser;
        $applier = new class(
            $parser,
            new HunkSequencer,
            new SecretScanner,
            new FileHistoryManager('test-session', $this->tmpDir.'/.directory-history'),
        ) extends PatchApplier {
            private int $moveCount = 0;

            protected function movePath(string $from, string $to): bool
            {
                $this->moveCount++;

                return $this->moveCount !== 2 && parent::movePath($from, $to);
            }
        };
        $tool = new ApplyPatchTool(
            $applier,
            $parser,
            PhoenixTracer::fromConfig(['enabled' => false]),
        );
        $patch = implode("\n", [
            '*** Begin Patch',
            '*** Add File: nested/path/first.txt',
            'first',
            '*** Add File: second.txt',
            'second',
            '*** End Patch',
        ]);

        $result = $tool->call(['patch' => $patch], $this->context);

        $this->assertTrue($result->isError);
        $this->assertDirectoryDoesNotExist($this->tmpDir.'/nested');
        $this->assertFileDoesNotExist($this->tmpDir.'/second.txt');
        $this->assertSame([], glob($this->tmpDir.'/.haocode_patch_*') ?: []);
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
