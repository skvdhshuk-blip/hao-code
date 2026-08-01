<?php

namespace HaoCode\Services\FileEdit;

/**
 * Publishes one file through a same-directory temporary file.
 *
 * @internal
 */
final class AtomicFileWriter
{
    /**
     * @param  callable(string): void|null  $beforeCommit
     */
    public function write(
        string $filePath,
        string $content,
        ?FileRevision $expectedRevision,
        ?callable $beforeCommit = null,
    ): void {
        $this->writeInternal(
            $filePath,
            function ($sourceHandle, $tempHandle, string $temporary) use ($content): void {
                $this->writeAll($tempHandle, $content, $temporary);
            },
            $expectedRevision,
            $beforeCommit,
        );
    }

    /**
     * Atomically publish content produced while the locked source file is
     * streamed.  This keeps large Edit operations bounded without weakening
     * the same revision checks used by regular string writes.
     *
     * @param callable(resource, resource, string): void $producer
     * @param callable(string): void|null $beforeCommit
     */
    public function writeFromProducer(
        string $filePath,
        callable $producer,
        ?FileRevision $expectedRevision,
        ?callable $beforeCommit = null,
    ): void {
        $this->writeInternal($filePath, $producer, $expectedRevision, $beforeCommit);
    }

    /**
     * @param callable(resource, resource, string): void $producer
     * @param callable(string): void|null $beforeCommit
     */
    private function writeInternal(
        string $filePath,
        callable $producer,
        ?FileRevision $expectedRevision,
        ?callable $beforeCommit = null,
    ): void {
        $existed = file_exists($filePath);
        $target = $existed ? (realpath($filePath) ?: $filePath) : $filePath;
        $directory = dirname($target);
        if (! is_dir($directory)) {
            throw new \RuntimeException("Parent directory does not exist: {$directory}");
        }

        if ($existed && $expectedRevision === null) {
            throw new FileConflictException("Read tool first: {$filePath} must be read before writing.");
        }
        if (! $existed && $expectedRevision !== null) {
            throw new FileConflictException("File changed since it was read: {$filePath} no longer exists.");
        }

        $reservationCreated = false;
        $handle = $existed
            ? @fopen($target, 'r+b')
            : @fopen($target, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open file for atomic write: {$filePath}");
        }
        if (! $existed) {
            $reservationCreated = true;
        }
        $reservationStat = $reservationCreated ? @fstat($handle) : null;
        $reservationRevision = null;

        $temporary = null;
        $committed = false;
        $reservationUnmodified = false;

        try {
            if (! @flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Failed to lock file for write: {$filePath}");
            }

            if ($expectedRevision !== null) {
                $this->assertExpectedRevision($handle, $target, $filePath, $expectedRevision);
            } else {
                $reservationRevision = FileRevision::captureFromHandle($handle, $target);
                if ($reservationRevision === null) {
                    throw new \RuntimeException(
                        "Failed to capture new-file reservation: {$filePath}",
                    );
                }
                $this->assertReservationOwned(
                    $handle,
                    $target,
                    $filePath,
                    $reservationRevision,
                );
            }

            if ($beforeCommit !== null) {
                $beforeCommit($target);
            }

            $temporary = tempnam($directory, '.haocode_write_');
            if ($temporary === false) {
                throw new \RuntimeException("Failed to create temporary file in {$directory}");
            }

            $tempHandle = @fopen($temporary, 'wb');
            if ($tempHandle === false) {
                throw new \RuntimeException("Failed to open temporary file: {$temporary}");
            }
            try {
                // The source handle remains exclusively locked for the whole
                // production step, so the callback cannot read a revision that
                // differs from the one checked immediately before it.
                $producer($handle, $tempHandle, $temporary);
                if (! fflush($tempHandle)) {
                    throw new \RuntimeException("Failed to flush temporary file: {$temporary}");
                }
                if (function_exists('fsync') && ! @fsync($tempHandle)) {
                    throw new \RuntimeException("Failed to sync temporary file: {$temporary}");
                }
            } finally {
                fclose($tempHandle);
            }

            $mode = $existed
                ? ((@fileperms($target) ?: 0644) & 0777)
                : (0666 & ~umask());
            if (! @chmod($temporary, $mode)) {
                throw new \RuntimeException(
                    "Failed to apply file permissions to temporary file: {$temporary}",
                );
            }

            if ($expectedRevision !== null) {
                $this->assertExpectedRevision($handle, $target, $filePath, $expectedRevision);
            } else {
                $this->assertReservationOwned(
                    $handle,
                    $target,
                    $filePath,
                    $reservationRevision,
                );
            }

            if (! @rename($temporary, $target)) {
                throw new \RuntimeException("Failed to atomically replace file: {$filePath}");
            }
            $temporary = null;
            $committed = true;
        } finally {
            if ($reservationCreated && ! $committed) {
                $reservationUnmodified = $reservationRevision !== null
                    ? $this->reservationMatches(
                        $handle,
                        $target,
                        $reservationRevision,
                    )
                    : $this->emptyReservationStillOwned($handle, $target);
            }
            @flock($handle, LOCK_UN);
            fclose($handle);

            if ($temporary !== null && file_exists($temporary)) {
                @unlink($temporary);
            }
            if ($reservationCreated
                && ! $committed
                && $reservationUnmodified
                && is_array($reservationStat)) {
                @unlink($target);
            }
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $content, string $path): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException("Failed to write temporary file: {$path}");
            }
            $offset += $written;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function assertExpectedRevision(
        $handle,
        string $target,
        string $displayPath,
        FileRevision $expected,
    ): void {
        $current = FileRevision::captureFromHandle($handle, $target);
        clearstatcache(true, $target);
        $pathStat = @stat($target);
        if ($current === null
            || ! is_array($pathStat)
            || $current->device !== (int) ($pathStat['dev'] ?? 0)
            || $current->inode !== (int) ($pathStat['ino'] ?? 0)
            || ! $expected->sameVersion($current)) {
            throw new FileConflictException(
                "File changed since it was read: {$displayPath}. Read it again before writing.",
            );
        }
    }

    /**
     * @param  resource  $handle
     */
    private function assertReservationOwned(
        $handle,
        string $target,
        string $displayPath,
        ?FileRevision $expectedReservation,
    ): void
    {
        if ($expectedReservation === null
            || ! $this->reservationMatches($handle, $target, $expectedReservation)) {
            throw new FileConflictException(
                "File reservation changed concurrently: {$displayPath}. Read it before overwriting.",
            );
        }
    }

    /**
     * @param  resource  $handle
     */
    private function reservationMatches(
        $handle,
        string $target,
        FileRevision $expectedReservation,
    ): bool {
        $current = FileRevision::captureFromHandle($handle, $target);
        clearstatcache(true, $target);
        $pathStat = @stat($target);

        return $current !== null
            && is_array($pathStat)
            && $current->device === (int) ($pathStat['dev'] ?? 0)
            && $current->inode === (int) ($pathStat['ino'] ?? 0)
            && $expectedReservation->sameVersion($current);
    }

    /**
     * Best-effort cleanup check when locking failed before a full reservation
     * receipt could be captured. Never remove a reservation that now contains
     * someone else's bytes.
     *
     * @param  resource  $handle
     */
    private function emptyReservationStillOwned($handle, string $target): bool
    {
        $handleStat = @fstat($handle);
        clearstatcache(true, $target);
        $pathStat = @stat($target);

        return is_array($handleStat)
            && is_array($pathStat)
            && ($handleStat['dev'] ?? null) === ($pathStat['dev'] ?? null)
            && ($handleStat['ino'] ?? null) === ($pathStat['ino'] ?? null)
            && (int) ($handleStat['size'] ?? -1) === 0;
    }
}
