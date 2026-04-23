<?php

namespace Tests\Unit\Services\Cron\Daemon;

use HaoCode\Services\Cron\Daemon\PidFile;
use PHPUnit\Framework\TestCase;

class PidFileTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        $this->tmpPath = sys_get_temp_dir().'/test_daemon_'.uniqid().'.pid';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPath);
    }

    public function test_write_and_read(): void
    {
        $pf = new PidFile($this->tmpPath);
        $pf->write();

        $this->assertFileExists($this->tmpPath);
        $this->assertSame(getmypid(), $pf->read());
    }

    public function test_pid_file_has0600_permissions(): void
    {
        $pf = new PidFile($this->tmpPath);
        $pf->write();

        $perms = substr(sprintf('%o', fileperms($this->tmpPath)), -4);
        $this->assertSame('0600', $perms);
    }

    public function test_remove(): void
    {
        $pf = new PidFile($this->tmpPath);
        $pf->write();
        $pf->remove();

        $this->assertFileDoesNotExist($this->tmpPath);
    }

    public function test_read_returns_null_when_missing(): void
    {
        $pf = new PidFile($this->tmpPath);
        $this->assertNull($pf->read());
    }

    public function test_is_running_with_current_process(): void
    {
        $pf = new PidFile($this->tmpPath);
        $pf->write();

        // Current process PID — must be running
        $this->assertTrue($pf->isRunning());
    }

    public function test_is_running_returns_false_for_stale_pid(): void
    {
        // PID 99999 is almost certainly not running
        file_put_contents($this->tmpPath, '99999');
        chmod($this->tmpPath, 0600);

        $pf = new PidFile($this->tmpPath);

        if (function_exists('posix_kill')) {
            // Only stale if posix_kill confirms the process doesn't exist
            $running = posix_kill(99999, 0);
            $this->assertSame($running, $pf->isRunning());
        } else {
            $this->markTestSkipped('posix_kill not available');
        }
    }
}
