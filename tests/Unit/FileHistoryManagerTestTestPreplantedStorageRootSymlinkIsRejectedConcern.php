<?php

namespace Tests\Unit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use PHPUnit\Framework\TestCase;

trait FileHistoryManagerTestTestPreplantedStorageRootSymlinkIsRejectedConcern
{

    public function test_preplanted_storage_root_symlink_is_rejected(): void
    {
        $outside = $this->storageRoot.'_outside';
        $symlink = $this->storageRoot.'_symlink';
        mkdir($outside, 0700, true);
        symlink($outside, $symlink);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symlink file history directory');

        try {
            new FileHistoryManager('symlink-session', $symlink);
        } finally {
            @unlink($symlink);
            $this->removeDirectory($outside);
        }
    }

    public function test_filesystem_root_is_rejected_without_changing_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX root permission assertion.');
        }

        clearstatcache(true, DIRECTORY_SEPARATOR);
        $modeBefore = fileperms(DIRECTORY_SEPARATOR) & 0777;

        try {
            new FileHistoryManager('root-rejected', DIRECTORY_SEPARATOR);
            $this->fail('Expected filesystem-root history storage to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('filesystem root', $exception->getMessage());
        }

        clearstatcache(true, DIRECTORY_SEPARATOR);
        $this->assertSame($modeBefore, fileperms(DIRECTORY_SEPARATOR) & 0777);
    }

    public function test_preplanted_lock_symlink_is_rejected_without_touching_target(): void
    {
        $external = $this->makeTmpFile('external lock target');
        $lock = $this->historyPath.'/.lock';
        unlink($lock);
        symlink($external, $lock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symlink file history file');

        try {
            new FileHistoryManager($this->sessionId, $this->storageRoot);
        } finally {
            $this->assertSame('external lock target', file_get_contents($external));
            @unlink($external);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
