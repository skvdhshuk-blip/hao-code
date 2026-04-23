<?php

namespace Tests\Unit\Services\Cron\Daemon;

use HaoCode\Services\Cron\Daemon\TickLock;
use PHPUnit\Framework\TestCase;

class TickLockTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/ticklock_test_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->tmpDir.'/*'));
        @rmdir($this->tmpDir);
    }

    public function test_acquire_and_release(): void
    {
        $lock = new TickLock($this->tmpDir);
        $this->assertTrue($lock->acquire());
        $this->assertTrue($lock->isLocked());
        $lock->release();
        $this->assertFalse($lock->isLocked());
    }

    public function test_second_acquire_fails(): void
    {
        $lock1 = new TickLock($this->tmpDir);
        $lock2 = new TickLock($this->tmpDir);

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire(), 'Second lock should fail');

        $lock1->release();
    }

    public function test_lock_file_has0600_permissions(): void
    {
        $lock = new TickLock($this->tmpDir);
        $lock->acquire();

        $perms = substr(sprintf('%o', fileperms($lock->getPath())), -4);
        $this->assertSame('0600', $perms);

        $lock->release();
    }

    public function test_release_removes_lock_file(): void
    {
        $lock = new TickLock($this->tmpDir);
        $lock->acquire();
        $path = $lock->getPath();
        $lock->release();

        $this->assertFileDoesNotExist($path);
    }

    public function test_reacquire_after_release(): void
    {
        $lock = new TickLock($this->tmpDir);
        $lock->acquire();
        $lock->release();

        $lock2 = new TickLock($this->tmpDir);
        $this->assertTrue($lock2->acquire());
        $lock2->release();
    }
}
