<?php

namespace HaoCode\Services\Cron\Daemon;

/**
 * Exclusive file lock preventing multiple daemon instances from ticking simultaneously.
 * Uses flock(LOCK_EX|LOCK_NB) on ~/.haocode/cron/.tick.lock (0600).
 */
class TickLock
{
    private string $path;

    /** @var resource|null */
    private $handle = null;

    private bool $locked = false;

    public function __construct(?string $dir = null)
    {
        $base = $dir ?? $this->defaultDir();
        $this->path = realpath($base) ?: $base;
        $this->path .= DIRECTORY_SEPARATOR.'.tick.lock';
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        chmod($this->path, 0600);
        $this->handle = $handle;
        $this->locked = true;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            if ($this->locked) {
                flock($this->handle, LOCK_UN);
                $this->locked = false;
            }
            fclose($this->handle);
            $this->handle = null;
        }

        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function defaultDir(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return $home.'/.haocode/cron';
    }
}
