<?php

namespace HaoCode\Services\Cron\Daemon;

/**
 * Manages daemon PID file at ~/.haocode/daemon.pid with 0600 permissions.
 * Uses posix_kill(pid, 0) to detect stale pids.
 */
class PidFile
{
    private string $path;

    public function __construct(?string $path = null)
    {
        if ($path !== null) {
            $this->path = $path;
        } else {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
            $this->path = $home.'/.haocode/daemon.pid';
        }

        // Normalise to realpath if the parent dir exists, else keep as-is
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            $real = realpath($dir);
            if ($real !== false) {
                $this->path = $real.DIRECTORY_SEPARATOR.basename($this->path);
            }
        }
    }

    /**
     * Write current PID. Creates directory with 0700 if needed.
     */
    public function write(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($this->path, (string) getmypid());
        chmod($this->path, 0600);
    }

    /**
     * Read PID from file. Returns null if file missing or invalid.
     */
    public function read(): ?int
    {
        if (! file_exists($this->path)) {
            return null;
        }

        $content = trim((string) file_get_contents($this->path));
        if (! ctype_digit($content)) {
            return null;
        }

        return (int) $content;
    }

    /**
     * Check if daemon is currently running (live process, not stale PID).
     */
    public function isRunning(): bool
    {
        $pid = $this->read();
        if ($pid === null) {
            return false;
        }

        // posix_kill(pid, 0) probes without sending a real signal
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback for systems without posix extension
        return file_exists('/proc/'.$pid);
    }

    /**
     * Remove PID file.
     */
    public function remove(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
