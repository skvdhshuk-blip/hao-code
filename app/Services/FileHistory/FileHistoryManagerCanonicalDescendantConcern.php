<?php

namespace HaoCode\Services\FileHistory;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\FileEdit\DiffGenerator;

trait FileHistoryManagerCanonicalDescendantConcern
{

    private function canonicalDescendant(string $path, string $root): string
    {
        if (is_link($path)) {
            throw new \RuntimeException("Refusing symlink file history path: {$path}");
        }
        $canonical = realpath($path);
        $canonicalRoot = realpath($root);
        if ($canonical === false
            || $canonicalRoot === false
            || ! str_starts_with(
                $canonical.DIRECTORY_SEPARATOR,
                rtrim($canonicalRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
            )) {
            throw new \RuntimeException("File history path escapes storage root: {$path}");
        }

        return $canonical;
    }

    private function assertStorageLayout(): void
    {
        foreach ([$this->storageRoot, $this->historyPath, $this->blobPath] as $directory) {
            if (is_link($directory) || ! is_dir($directory)) {
                throw new \RuntimeException("Unsafe file history directory: {$directory}");
            }
        }
        $this->canonicalDescendant($this->historyPath, $this->storageRoot);
        $this->canonicalDescendant($this->blobPath, $this->historyPath);
    }

    private function assertRegularFile(string $path, string $root): void
    {
        if (is_link($path)) {
            throw new \RuntimeException("Refusing symlink file history file: {$path}");
        }
        $stat = @lstat($path);
        if (! is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            throw new \RuntimeException("File history path is not a regular file: {$path}");
        }
        $this->canonicalDescendant($path, $root);
    }

    private function readPrivateFile(string $path, string $root): string
    {
        $this->assertRegularFile($path, $root);
        $expectedIdentity = $this->fileIdentity(@lstat($path));
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open file history file: {$path}");
        }

        try {
            if (! $this->sameFileIdentity(
                $expectedIdentity,
                $this->fileIdentity(@fstat($handle)),
            ) || ! $this->sameFileIdentity(
                $expectedIdentity,
                $this->fileIdentity(@lstat($path)),
            )) {
                throw new \RuntimeException("File history file changed concurrently: {$path}");
            }
            if (! @chmod($path, 0600)
                || ! $this->sameFileIdentity(
                    $expectedIdentity,
                    $this->fileIdentity(@lstat($path)),
                )) {
                throw new \RuntimeException("Unable to secure file history file: {$path}");
            }
            $content = stream_get_contents($handle);
            if ($content === false) {
                throw new \RuntimeException("Unable to read file history file: {$path}");
            }

            return $content;
        } finally {
            fclose($handle);
        }
    }

    private function writePrivateFileAtomically(string $path, string $content, string $root): void
    {
        $this->assertStorageLayout();
        $existingIdentity = null;
        if (@lstat($path) !== false) {
            $this->assertRegularFile($path, $root);
            $existingIdentity = $this->fileIdentity(@lstat($path));
        }

        $temporary = tempnam(dirname($path), '.haocode_history_');
        if ($temporary === false) {
            throw new \RuntimeException("Unable to create file history temporary file in: ".dirname($path));
        }

        try {
            $this->assertRegularFile($temporary, dirname($path));
            $temporaryIdentity = $this->fileIdentity(@lstat($temporary));
            $handle = @fopen($temporary, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException("Unable to open file history temporary file: {$temporary}");
            }
            try {
                if (! $this->sameFileIdentity(
                    $temporaryIdentity,
                    $this->fileIdentity(@fstat($handle)),
                ) || ! $this->sameFileIdentity(
                    $temporaryIdentity,
                    $this->fileIdentity(@lstat($temporary)),
                )) {
                    throw new \RuntimeException(
                        "File history temporary file changed concurrently: {$temporary}",
                    );
                }
                if (! @chmod($temporary, 0600)
                    || ! $this->sameFileIdentity(
                        $temporaryIdentity,
                        $this->fileIdentity(@lstat($temporary)),
                    )
                    || ! @ftruncate($handle, 0)
                    || @fseek($handle, 0) !== 0) {
                    throw new \RuntimeException(
                        "Unable to prepare file history temporary file: {$temporary}",
                    );
                }
                $offset = 0;
                $length = strlen($content);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($content, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException("Unable to write file history temporary file: {$temporary}");
                    }
                    $offset += $written;
                }
                if (! fflush($handle)) {
                    throw new \RuntimeException("Unable to flush file history temporary file: {$temporary}");
                }
                if (function_exists('fsync') && ! @fsync($handle)) {
                    throw new \RuntimeException("Unable to sync file history temporary file: {$temporary}");
                }
            } finally {
                fclose($handle);
            }
            $currentIdentity = @lstat($path) === false
                ? null
                : $this->fileIdentity(@lstat($path));
            if (! $this->sameFileIdentity($existingIdentity, $currentIdentity)) {
                throw new \RuntimeException("File history target changed concurrently: {$path}");
            }
            if (is_link($path) || ! @rename($temporary, $path)) {
                throw new \RuntimeException("Unable to atomically publish file history file: {$path}");
            }
            $temporary = '';
            $this->assertRegularFile($path, $root);
        } finally {
            if ($temporary !== '' && file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  array<string|int, mixed>|false  $stat
     * @return array{device: int, inode: int}|null
     */
    private function fileIdentity(array|false $stat): ?array
    {
        if (! is_array($stat)
            || ! is_int($stat['dev'] ?? null)
            || ! is_int($stat['ino'] ?? null)) {
            return null;
        }

        return ['device' => $stat['dev'], 'inode' => $stat['ino']];
    }

    /**
     * @param  array{device: int, inode: int}|null  $left
     * @param  array{device: int, inode: int}|null  $right
     */
    private function sameFileIdentity(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left['device'] === $right['device'] && $left['inode'] === $right['inode'];
    }

    private function withExclusiveLock(callable $callback): mixed
    {
        $this->assertStorageLayout();
        if (@lstat($this->lockPath) !== false) {
            $this->assertRegularFile($this->lockPath, $this->historyPath);
        }

        $handle = @fopen(
            $this->lockPath,
            @lstat($this->lockPath) === false ? 'x+b' : 'r+b',
        );
        if ($handle === false && @lstat($this->lockPath) !== false) {
            $this->assertRegularFile($this->lockPath, $this->historyPath);
            $handle = @fopen($this->lockPath, 'r+b');
        }
        if ($handle === false) {
            throw new \RuntimeException("Unable to open file history lock: {$this->lockPath}");
        }
        $this->assertRegularFile($this->lockPath, $this->historyPath);
        if (! $this->sameFileIdentity(
            $this->fileIdentity(@fstat($handle)),
            $this->fileIdentity(@lstat($this->lockPath)),
        )) {
            fclose($handle);
            throw new \RuntimeException("File history lock changed concurrently: {$this->lockPath}");
        }
        if (! @chmod($this->lockPath, 0600)
            || ! $this->sameFileIdentity(
                $this->fileIdentity(@fstat($handle)),
                $this->fileIdentity(@lstat($this->lockPath)),
            )) {
            fclose($handle);
            throw new \RuntimeException("Unable to secure file history lock: {$this->lockPath}");
        }

        try {
            if (! @flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock file history: {$this->historyPath}");
            }
            if (! $this->sameFileIdentity(
                $this->fileIdentity(@fstat($handle)),
                $this->fileIdentity(@lstat($this->lockPath)),
            )) {
                throw new \RuntimeException("File history lock changed concurrently: {$this->lockPath}");
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
