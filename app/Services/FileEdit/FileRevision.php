<?php

namespace HaoCode\Services\FileEdit;

/**
 * Immutable receipt for the exact bytes observed by a successful Read.
 *
 * @internal
 */
final class FileRevision
{
    public function __construct(
        public readonly string $canonicalPath,
        public readonly ?int $device,
        public readonly ?int $inode,
        public readonly int $size,
        public readonly ?int $mtime,
        public readonly string $sha256,
        public readonly bool $complete,
        public readonly int $observedAtMicros,
        public readonly bool $local,
    ) {}

    public static function fromRead(string $filePath, string $content, bool $complete): self
    {
        clearstatcache(true, $filePath);
        $canonicalPath = realpath($filePath) ?: $filePath;
        $stat = @stat($canonicalPath);

        return new self(
            canonicalPath: $canonicalPath,
            device: is_array($stat) ? (int) ($stat['dev'] ?? 0) : null,
            inode: is_array($stat) ? (int) ($stat['ino'] ?? 0) : null,
            size: strlen($content),
            mtime: is_array($stat) ? (int) ($stat['mtime'] ?? 0) : null,
            sha256: hash('sha256', $content),
            complete: $complete,
            observedAtMicros: (int) round(microtime(true) * 1_000_000),
            local: is_array($stat),
        );
    }

    public static function capture(string $filePath, bool $complete = true): ?self
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return null;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (! @flock($handle, LOCK_SH)) {
                return null;
            }

            return self::captureFromHandle($handle, $filePath, $complete);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Capture bytes from a handle whose lock is already owned by the caller.
     *
     * @param  resource  $handle
     */
    public static function captureFromHandle($handle, string $filePath, bool $complete = true): ?self
    {
        $position = ftell($handle);
        if ($position === false || fseek($handle, 0) !== 0) {
            return null;
        }

        try {
            $stat = @fstat($handle);
            if (! is_array($stat)) {
                return null;
            }

            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }

            return new self(
                canonicalPath: realpath($filePath) ?: $filePath,
                device: (int) ($stat['dev'] ?? 0),
                inode: (int) ($stat['ino'] ?? 0),
                size: $size,
                mtime: (int) ($stat['mtime'] ?? 0),
                sha256: hash_final($hash),
                complete: $complete,
                observedAtMicros: (int) round(microtime(true) * 1_000_000),
                local: true,
            );
        } finally {
            @fseek($handle, $position);
        }
    }

    public function sameVersion(self $other): bool
    {
        return $this->local
            && $other->local
            && $this->canonicalPath === $other->canonicalPath
            && $this->device === $other->device
            && $this->inode === $other->inode
            && $this->size === $other->size
            && $this->mtime === $other->mtime
            && hash_equals($this->sha256, $other->sha256);
    }

    /**
     * @return array{
     *   canonical_path: string,
     *   device: int|null,
     *   inode: int|null,
     *   size: int,
     *   mtime: int|null,
     *   sha256: string,
     *   complete: bool,
     *   observed_at_micros: int,
     *   local: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'canonical_path' => $this->canonicalPath,
            'device' => $this->device,
            'inode' => $this->inode,
            'size' => $this->size,
            'mtime' => $this->mtime,
            'sha256' => $this->sha256,
            'complete' => $this->complete,
            'observed_at_micros' => $this->observedAtMicros,
            'local' => $this->local,
        ];
    }

    public static function fromArray(array $value): ?self
    {
        if (! is_string($value['canonical_path'] ?? null)
            || ! is_int($value['size'] ?? null)
            || ! is_string($value['sha256'] ?? null)
            || ! is_bool($value['complete'] ?? null)
            || ! is_int($value['observed_at_micros'] ?? null)
            || ! is_bool($value['local'] ?? null)) {
            return null;
        }

        $device = $value['device'] ?? null;
        $inode = $value['inode'] ?? null;
        $mtime = $value['mtime'] ?? null;
        if (($device !== null && ! is_int($device))
            || ($inode !== null && ! is_int($inode))
            || ($mtime !== null && ! is_int($mtime))) {
            return null;
        }

        return new self(
            canonicalPath: $value['canonical_path'],
            device: $device,
            inode: $inode,
            size: $value['size'],
            mtime: $mtime,
            sha256: $value['sha256'],
            complete: $value['complete'],
            observedAtMicros: $value['observed_at_micros'],
            local: $value['local'],
        );
    }
}
