<?php

namespace Tests\Unit;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use PHPUnit\Framework\TestCase;

class AtomicFileWriterTest extends TestCase
{
    public function test_preserves_mode_and_replaces_expected_revision(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atomic-writer-');
        file_put_contents($path, 'before');
        chmod($path, 0750);
        $revision = FileRevision::capture($path);

        try {
            $this->assertNotNull($revision);
            (new AtomicFileWriter())->write($path, 'after', $revision);

            $this->assertSame('after', file_get_contents($path));
            $this->assertSame(0750, fileperms($path) & 0777);
        } finally {
            @unlink($path);
        }
    }

    public function test_detects_change_during_prepare_and_preserves_external_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atomic-writer-race-');
        file_put_contents($path, 'before');
        $revision = FileRevision::capture($path);

        try {
            $this->assertNotNull($revision);

            $this->expectException(FileConflictException::class);
            try {
                (new AtomicFileWriter())->write(
                    $path,
                    'agent bytes',
                    $revision,
                    static function (string $target): void {
                        file_put_contents($target, 'external bytes');
                    },
                );
            } finally {
                $this->assertSame('external bytes', file_get_contents($path));
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_creates_new_file_without_leaving_temporary_files(): void
    {
        $directory = sys_get_temp_dir().'/atomic-writer-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory.'/new.txt';

        try {
            (new AtomicFileWriter())->write($path, 'created', null);

            $this->assertSame('created', file_get_contents($path));
            $this->assertSame([], glob($directory.'/.haocode_write_*') ?: []);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_does_not_delete_or_overwrite_concurrently_created_file(): void
    {
        $directory = sys_get_temp_dir().'/atomic-writer-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory.'/new.txt';

        try {
            $this->expectException(FileConflictException::class);
            try {
                (new AtomicFileWriter())->write(
                    $path,
                    'agent bytes',
                    null,
                    static function (string $target): void {
                        unlink($target);
                        file_put_contents($target, 'external bytes');
                    },
                );
            } finally {
                $this->assertSame('external bytes', file_get_contents($path));
            }
        } finally {
            @unlink($path);
            foreach (glob($directory.'/.haocode_write_*') ?: [] as $temporary) {
                @unlink($temporary);
            }
            @rmdir($directory);
        }
    }

    public function test_does_not_delete_or_overwrite_same_inode_reservation_change(): void
    {
        $directory = sys_get_temp_dir().'/atomic-writer-reservation-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory.'/new.txt';

        try {
            $this->expectException(FileConflictException::class);
            try {
                (new AtomicFileWriter())->write(
                    $path,
                    'agent bytes',
                    null,
                    static function (string $target): void {
                        file_put_contents($target, 'external bytes');
                    },
                );
            } finally {
                $this->assertSame('external bytes', file_get_contents($path));
            }
        } finally {
            @unlink($path);
            foreach (glob($directory.'/.haocode_write_*') ?: [] as $temporary) {
                @unlink($temporary);
            }
            @rmdir($directory);
        }
    }
}
