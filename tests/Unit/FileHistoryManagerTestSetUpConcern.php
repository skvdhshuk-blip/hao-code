<?php

namespace Tests\Unit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use PHPUnit\Framework\TestCase;

trait FileHistoryManagerTestSetUpConcern
{

    protected function setUp(): void
    {
        $this->sessionId = 'test_' . uniqid();
        $this->storageRoot = sys_get_temp_dir().'/haocode_file_history_test_'.uniqid();
        $this->historyPath = $this->storageRoot.'/'.hash('sha256', $this->sessionId);
        $this->manager = new FileHistoryManager($this->sessionId, $this->storageRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
    }

    private function makeTmpFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fhm_');
        file_put_contents($file, $content);
        return $file;
    }

    public function test_record_before_stores_snapshot(): void
    {
        $file = $this->makeTmpFile('original content');

        $this->manager->recordBefore($file);

        $snapshots = $this->manager->getAllSnapshots();
        $this->assertCount(1, $snapshots);
        $this->assertSame('original content', reset($snapshots)->content);

        unlink($file);
    }

    public function test_record_before_does_nothing_for_nonexistent_file(): void
    {
        $this->manager->recordBefore('/nonexistent/path/file.txt');

        $this->assertEmpty($this->manager->getAllSnapshots());
    }

    public function test_record_before_skips_duplicate_content_for_same_file(): void
    {
        $file = $this->makeTmpFile('same content');

        $this->manager->recordBefore($file);
        $this->manager->recordBefore($file); // same content, same path

        $this->assertCount(1, $this->manager->getAllSnapshots());

        unlink($file);
    }

    public function test_record_before_stores_new_snapshot_when_content_changes(): void
    {
        $file = $this->makeTmpFile('version 1');
        $this->manager->recordBefore($file);

        file_put_contents($file, 'version 2');
        $this->manager->recordBefore($file);

        $this->assertCount(2, $this->manager->getAllSnapshots());

        unlink($file);
    }

    public function test_get_latest_returns_most_recent_snapshot(): void
    {
        $file = $this->makeTmpFile('first');
        $this->manager->recordBefore($file);

        file_put_contents($file, 'second');
        $this->manager->recordBefore($file);

        $latest = $this->manager->getLatest();
        $this->assertNotNull($latest);
        $this->assertSame('second', $latest->content);

        unlink($file);
    }

    public function test_get_latest_returns_null_when_no_snapshots(): void
    {
        $this->assertNull($this->manager->getLatest());
    }

    public function test_get_snapshots_for_file_filters_by_path(): void
    {
        $file1 = $this->makeTmpFile('file one');
        $file2 = $this->makeTmpFile('file two');

        $this->manager->recordBefore($file1);
        $this->manager->recordBefore($file2);

        $forFile1 = $this->manager->getSnapshotsForFile($file1);
        $this->assertCount(1, $forFile1);
        $this->assertSame('file one', reset($forFile1)->content);

        unlink($file1);
        unlink($file2);
    }

    public function test_restore_writes_snapshot_content_back_to_file(): void
    {
        $file = $this->makeTmpFile('original');
        $this->manager->recordBefore($file);

        $snapshots = $this->manager->getAllSnapshots();
        $id = reset($snapshots)->id;

        file_put_contents($file, 'modified');
        $this->assertSame('modified', file_get_contents($file));

        $result = $this->manager->restore($id);
        $this->assertTrue($result);
        $this->assertSame('original', file_get_contents($file));

        unlink($file);
    }

    public function test_restore_returns_false_for_unknown_id(): void
    {
        $result = $this->manager->restore(99999);
        $this->assertFalse($result);
    }

    public function test_snapshot_ids_are_unique_across_trim_boundary(): void
    {
        // Fill past MAX_SNAPSHOTS (100) to trigger the trim. We use many different files
        // to bypass the duplicate-content dedup logic.
        $files = [];
        for ($i = 0; $i <= 102; $i++) {
            $file = $this->makeTmpFile("content_{$i}");
            $files[] = $file;
            $this->manager->recordBefore($file);
        }

        $allIds = array_map(fn($s) => $s->id, $this->manager->getAllSnapshots());
        $this->assertSame(count($allIds), count(array_unique($allIds)), 'Snapshot IDs must be unique after trim');

        foreach ($files as $f) {
            unlink($f);
        }
    }

    public function test_get_summary_reflects_tracked_files(): void
    {
        $file = $this->makeTmpFile('content');
        $this->manager->recordBefore($file);

        $summary = $this->manager->getSummary();

        $this->assertStringContainsString('1 snapshots', $summary);
        $this->assertStringContainsString(basename($file), $summary);

        unlink($file);
    }

    public function test_get_summary_with_no_snapshots(): void
    {
        $summary = $this->manager->getSummary();
        $this->assertStringContainsString('No file changes tracked', $summary);
    }

    public function test_get_diff_uses_php_diff_without_shell_exec(): void
    {
        $source = file_get_contents((new \ReflectionClass(FileHistoryManager::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('diff -u', $source);
    }

    public function test_missing_blob_is_reported_as_corrupt_history(): void
    {
        $file = $this->makeTmpFile("original\n");
        $this->manager->recordBefore($file);
        $blob = (glob($this->historyPath.'/blobs/*.blob') ?: [])[0] ?? null;
        $this->assertNotNull($blob);
        unlink($blob);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing or corrupt');

        try {
            $this->manager->getAllSnapshots();
        } finally {
            unlink($file);
        }
    }

    public function test_get_diff_returns_no_differences_for_same_content(): void
    {
        $file = $this->makeTmpFile("same\ncontent\n");
        $this->manager->recordBefore($file);
        $id1 = $this->manager->getLatest()->id;

        // Same content, but since dedup prevents a second snapshot,
        // we compare the snapshot to itself via a roundabout way
        $diff = $this->manager->getDiff($id1, $id1);
        // Comparing same id means same file → diff sees no differences
        $this->assertNotNull($diff);
        $this->assertStringContainsString('No differences', $diff);

        unlink($file);
    }

    public function test_record_before_deduplicates_same_content_even_with_interleaved_files(): void
    {
        // Reproduces the bug: interleaving file edits should still deduplicate
        // when the same file's content hasn't changed.
        $fileA = $this->makeTmpFile('content A');
        $fileB = $this->makeTmpFile('content B');

        $this->manager->recordBefore($fileA); // snapshot 1 for fileA
        $this->manager->recordBefore($fileB); // snapshot 2 for fileB
        $this->manager->recordBefore($fileA); // fileA with SAME content — should NOT add snapshot

        $forA = array_values($this->manager->getSnapshotsForFile($fileA));
        $this->assertCount(1, $forA, 'Same content for fileA should not create a second snapshot');

        unlink($fileA);
        unlink($fileB);
    }

    public function test_get_diff_returns_null_for_unknown_ids(): void
    {
        $this->assertNull($this->manager->getDiff(0, 1));
    }

    public function test_get_diff_returns_diff_between_two_snapshots(): void
    {
        $file = $this->makeTmpFile("line1\nline2\n");
        $this->manager->recordBefore($file);
        $id1 = $this->manager->getLatest()->id;

        file_put_contents($file, "line1\nline2\nline3\n");
        $this->manager->recordBefore($file);
        $id2 = $this->manager->getLatest()->id;

        $diff = $this->manager->getDiff($id1, $id2);

        $this->assertNotNull($diff);
        $this->assertStringContainsString('line3', $diff);

        unlink($file);
    }

    public function test_snapshots_reload_and_restore_after_manager_restart(): void
    {
        $file = $this->makeTmpFile('version one');
        $this->manager->recordBefore($file);
        $snapshotId = $this->manager->getLatest()->id;
        file_put_contents($file, 'version two');

        $reloaded = new FileHistoryManager($this->sessionId, $this->storageRoot);

        $this->assertCount(1, $reloaded->getAllSnapshots());
        $this->assertTrue($reloaded->restore($snapshotId));
        $this->assertSame('version one', file_get_contents($file));

        unlink($file);
    }

    public function test_restart_advances_id_without_overwriting_existing_blob(): void
    {
        $file = $this->makeTmpFile('version one');
        $this->manager->recordBefore($file);

        $reloaded = new FileHistoryManager($this->sessionId, $this->storageRoot);
        file_put_contents($file, 'version two');
        $reloaded->recordBefore($file);

        $ids = array_map(
            static fn ($snapshot): int => $snapshot->id,
            $reloaded->getAllSnapshots(),
        );
        $this->assertSame([0, 1], array_values($ids));
        $this->assertCount(2, glob($this->historyPath.'/blobs/*.blob') ?: []);

        unlink($file);
    }

    public function test_two_managers_for_same_session_merge_under_lock(): void
    {
        $otherManager = new FileHistoryManager($this->sessionId, $this->storageRoot);
        $file1 = $this->makeTmpFile('one');
        $file2 = $this->makeTmpFile('two');

        $this->manager->recordBefore($file1);
        $otherManager->recordBefore($file2);

        $snapshots = $this->manager->getAllSnapshots();
        $this->assertCount(2, $snapshots);
        $this->assertSame(
            [0, 1],
            array_values(array_map(static fn ($snapshot): int => $snapshot->id, $snapshots)),
        );

        unlink($file1);
        unlink($file2);
    }

    public function test_for_session_isolates_manifests_and_snapshots(): void
    {
        $file1 = $this->makeTmpFile('one');
        $file2 = $this->makeTmpFile('two');
        $other = $this->manager->forSession('other-session');

        $this->manager->recordBefore($file1);
        $other->recordBefore($file2);

        $this->assertCount(1, $this->manager->getAllSnapshots());
        $this->assertCount(1, $other->getAllSnapshots());
        $this->assertNotSame(
            hash('sha256', $this->sessionId),
            hash('sha256', 'other-session'),
        );
        $this->assertFileExists(
            $this->storageRoot.'/'.hash('sha256', 'other-session').'/manifest.json',
        );

        unlink($file1);
        unlink($file2);
    }

    public function test_history_storage_uses_private_permissions(): void
    {
        $file = $this->makeTmpFile('private');
        $this->manager->recordBefore($file);
        $blob = (glob($this->historyPath.'/blobs/*.blob') ?: [])[0] ?? null;
        $this->assertNotNull($blob);

        clearstatcache();
        $this->assertSame(0700, fileperms($this->storageRoot) & 0777);
        $this->assertSame(0700, fileperms($this->historyPath) & 0777);
        $this->assertSame(0700, fileperms($this->historyPath.'/blobs') & 0777);
        $this->assertSame(0600, fileperms($this->historyPath.'/manifest.json') & 0777);
        $this->assertSame(0600, fileperms($this->historyPath.'/.lock') & 0777);
        $this->assertSame(0600, fileperms($blob) & 0777);

        unlink($file);
    }

    public function test_existing_safe_storage_root_permissions_are_preserved(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $storageRoot = $this->storageRoot.'_existing_safe';
        mkdir($storageRoot, 0755);
        chmod($storageRoot, 0755);
        $historyPath = $storageRoot.'/'.hash('sha256', 'existing-safe');

        try {
            new FileHistoryManager('existing-safe', $storageRoot);

            clearstatcache(true);
            $this->assertSame(0755, fileperms($storageRoot) & 0777);
            $this->assertSame(0700, fileperms($historyPath) & 0777);
            $this->assertSame(0700, fileperms($historyPath.'/blobs') & 0777);
            $this->assertSame(0600, fileperms($historyPath.'/manifest.json') & 0777);
            $this->assertSame(0600, fileperms($historyPath.'/.lock') & 0777);
        } finally {
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_existing_non_sticky_shared_storage_root_is_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $storageRoot = $this->storageRoot.'_unsafe';
        mkdir($storageRoot, 0700);
        chmod($storageRoot, 0777);
        $historyPath = $storageRoot.'/'.hash('sha256', 'unsafe-shared');

        try {
            new FileHistoryManager('unsafe-shared', $storageRoot);
            $this->fail('Expected unsafe shared history root to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'group/other-writable non-sticky',
                $exception->getMessage(),
            );
            clearstatcache(true, $storageRoot);
            $this->assertSame(0777, fileperms($storageRoot) & 0777);
            $this->assertDirectoryDoesNotExist($historyPath);
        } finally {
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_existing_sticky_shared_storage_root_is_accepted(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $storageRoot = $this->storageRoot.'_sticky';
        mkdir($storageRoot, 0700);
        chmod($storageRoot, 01777);
        $historyPath = $storageRoot.'/'.hash('sha256', 'sticky-shared');

        try {
            new FileHistoryManager('sticky-shared', $storageRoot);

            clearstatcache(true);
            $this->assertSame(01777, fileperms($storageRoot) & 01777);
            $this->assertSame(0700, fileperms($historyPath) & 0777);
            $this->assertSame(0700, fileperms($historyPath.'/blobs') & 0777);
        } finally {
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_trim_garbage_collects_unreferenced_blobs(): void
    {
        $files = [];
        for ($index = 0; $index < 105; $index++) {
            $file = $this->makeTmpFile("content {$index}");
            $files[] = $file;
            $this->manager->recordBefore($file);
        }

        $this->assertCount(100, $this->manager->getAllSnapshots());
        $this->assertCount(100, glob($this->historyPath.'/blobs/*.blob') ?: []);

        foreach ($files as $file) {
            unlink($file);
        }
    }

    public function test_corrupt_manifest_is_rejected_on_restart(): void
    {
        file_put_contents($this->historyPath.'/manifest.json', '{broken');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid file history manifest JSON');

        new FileHistoryManager($this->sessionId, $this->storageRoot);
    }
}
