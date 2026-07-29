<?php

namespace HaoCode\Services\FileHistory;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;

/**
 * Tracks file changes across a session with durable snapshots.
 */
class FileHistoryManager
{
    private const MANIFEST_VERSION = 1;

    private const MAX_SNAPSHOTS = 100;

    private readonly string $sessionId;

    private readonly string $storageRoot;

    private readonly string $historyPath;

    private readonly string $manifestPath;

    private readonly string $blobPath;

    private readonly string $lockPath;

    private readonly AtomicFileWriter $atomicWriter;

    private int $nextId = 0;

    /** @var array<int, FileSnapshot> */
    private array $snapshots = [];

    public function __construct(
        ?string $sessionId = null,
        ?string $storageRoot = null,
        ?AtomicFileWriter $atomicWriter = null,
    ) {
        $this->sessionId = $sessionId === null || $sessionId === '' ? 'default' : $sessionId;
        $normalizedRoot = rtrim(
            $storageRoot ?? sys_get_temp_dir().'/haocode_file_history',
            DIRECTORY_SEPARATOR,
        );
        $rootCandidate = $normalizedRoot === '' ? DIRECTORY_SEPARATOR : $normalizedRoot;
        if (CanonicalPathResolver::isFilesystemRoot($rootCandidate)) {
            throw new \RuntimeException(
                "Refusing to use a filesystem root for file history: {$rootCandidate}",
            );
        }
        $this->ensurePrivateDirectory($rootCandidate, false);
        $canonicalRoot = realpath($rootCandidate);
        if ($canonicalRoot === false) {
            throw new \RuntimeException("Unable to resolve file history root: {$rootCandidate}");
        }
        if (CanonicalPathResolver::isFilesystemRoot($canonicalRoot)) {
            throw new \RuntimeException(
                "Refusing to use a filesystem root for file history: {$canonicalRoot}",
            );
        }
        $this->storageRoot = $canonicalRoot;

        $historyCandidate = $this->storageRoot.'/'.hash('sha256', $this->sessionId);
        $this->ensurePrivateDirectory($historyCandidate);
        $this->historyPath = $this->canonicalDescendant($historyCandidate, $this->storageRoot);

        $blobCandidate = $this->historyPath.'/blobs';
        $this->ensurePrivateDirectory($blobCandidate);
        $this->blobPath = $this->canonicalDescendant($blobCandidate, $this->historyPath);
        $this->manifestPath = $this->historyPath.'/manifest.json';
        $this->lockPath = $this->historyPath.'/.lock';
        $this->atomicWriter = $atomicWriter ?? new AtomicFileWriter;

        $this->withExclusiveLock(function (): void {
            $this->loadManifest();
            if (@lstat($this->manifestPath) === false) {
                $this->persistManifest();
            }
            $this->garbageCollectBlobs();
        });
    }

    public function forSession(string $sessionId): self
    {
        if ($sessionId === $this->sessionId) {
            return $this;
        }

        return new self($sessionId, $this->storageRoot, $this->atomicWriter);
    }

    /**
     * Record a snapshot of a regular file before it is modified.
     */
    public function recordBefore(string $filePath): void
    {
        if (! is_file($filePath)) {
            return;
        }

        $canonicalPath = realpath($filePath) ?: $filePath;
        $content = @file_get_contents($canonicalPath);
        if ($content === false) {
            throw new \RuntimeException("Unable to read file for history snapshot: {$filePath}");
        }
        $hash = hash('sha256', $content);

        $this->withExclusiveLock(function () use ($canonicalPath, $content, $hash): void {
            $this->loadManifest();

            $last = $this->latestSnapshotForFile($canonicalPath);
            if ($last !== null && hash_equals($last->contentHash, $hash)) {
                return;
            }

            $snapshotId = $this->nextId;
            $blobName = sprintf(
                '%020d_%s.blob',
                $snapshotId,
                substr(hash('sha256', $canonicalPath), 0, 16),
            );
            $blobFile = $this->blobPath.'/'.$blobName;

            try {
                $this->writePrivateFileAtomically($blobFile, $content, $this->blobPath);

                $this->snapshots[] = new FileSnapshot(
                    id: $snapshotId,
                    filePath: $canonicalPath,
                    content: $content,
                    contentHash: $hash,
                    timestamp: time(),
                    blobName: $blobName,
                );
                $this->nextId++;

                if (count($this->snapshots) > self::MAX_SNAPSHOTS) {
                    $this->snapshots = array_values(array_slice(
                        $this->snapshots,
                        -self::MAX_SNAPSHOTS,
                    ));
                }

                $this->persistManifest();
                $this->garbageCollectBlobs();
            } catch (\Throwable $e) {
                $this->loadManifest();
                if (! $this->isBlobReferenced($blobName) && is_file($blobFile)) {
                    @unlink($blobFile);
                }

                throw $e;
            }
        });
    }

    /**
     * Get the diff between two snapshots.
     */
    public function getDiff(int $fromId, int $toId): ?string
    {
        $this->refresh();
        $from = $this->findSnapshotById($fromId);
        $to = $this->findSnapshotById($toId);
        if ($from === null || $to === null || $from->blobName === null) {
            return null;
        }

        $fromFile = $this->blobPath.'/'.$from->blobName;
        if (! is_file($fromFile)) {
            return null;
        }

        $toFile = tempnam(sys_get_temp_dir(), 'haocode_diff_');
        if ($toFile === false) {
            throw new \RuntimeException('Unable to create temporary diff file.');
        }

        try {
            if (file_put_contents($toFile, $to->content) !== strlen($to->content)) {
                throw new \RuntimeException('Unable to write temporary diff file.');
            }
            @chmod($toFile, 0600);

            $output = shell_exec(sprintf(
                'diff -u %s %s 2>/dev/null',
                escapeshellarg($fromFile),
                escapeshellarg($toFile),
            ));

            return $output ?: 'No differences found.';
        } finally {
            @unlink($toFile);
        }
    }

    /**
     * Restore a file to a previous snapshot.
     */
    public function restore(int $snapshotId): bool
    {
        $this->refresh();
        $snapshot = $this->findSnapshotById($snapshotId);
        if ($snapshot === null) {
            return false;
        }
        if (is_link($snapshot->filePath)) {
            return false;
        }

        $expectedRevision = file_exists($snapshot->filePath)
            ? FileRevision::capture($snapshot->filePath)
            : null;
        if (file_exists($snapshot->filePath) && $expectedRevision === null) {
            return false;
        }

        try {
            $this->atomicWriter->write(
                $snapshot->filePath,
                $snapshot->content,
                $expectedRevision,
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return FileSnapshot[]
     */
    public function getSnapshotsForFile(string $filePath): array
    {
        $this->refresh();
        $canonicalPath = realpath($filePath) ?: $filePath;

        return array_filter(
            $this->snapshots,
            static fn (FileSnapshot $snapshot): bool => $snapshot->filePath === $canonicalPath,
        );
    }

    /**
     * @return FileSnapshot[]
     */
    public function getAllSnapshots(): array
    {
        $this->refresh();

        return $this->snapshots;
    }

    public function getLatest(): ?FileSnapshot
    {
        $this->refresh();
        $latest = end($this->snapshots);

        return $latest instanceof FileSnapshot ? $latest : null;
    }

    public function getSummary(): string
    {
        $this->refresh();
        if ($this->snapshots === []) {
            return 'No file changes tracked.';
        }

        $files = array_unique(array_map(
            static fn (FileSnapshot $snapshot): string => $snapshot->filePath,
            $this->snapshots,
        ));
        $lines = [
            sprintf(
                'Tracked %d snapshots across %d files:',
                count($this->snapshots),
                count($files),
            ),
        ];

        foreach ($files as $file) {
            $count = count(array_filter(
                $this->snapshots,
                static fn (FileSnapshot $snapshot): bool => $snapshot->filePath === $file,
            ));
            $lines[] = sprintf('  %s (%d versions)', basename($file), $count);
        }

        return implode("\n", $lines);
    }

    private function refresh(): void
    {
        $this->withExclusiveLock(function (): void {
            $this->loadManifest();
        });
    }

    private function findSnapshotById(int $id): ?FileSnapshot
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->id === $id) {
                return $snapshot;
            }
        }

        return null;
    }

    private function latestSnapshotForFile(string $filePath): ?FileSnapshot
    {
        for ($index = count($this->snapshots) - 1; $index >= 0; $index--) {
            if ($this->snapshots[$index]->filePath === $filePath) {
                return $this->snapshots[$index];
            }
        }

        return null;
    }

    private function loadManifest(): void
    {
        if (@lstat($this->manifestPath) === false) {
            $this->snapshots = [];
            $this->nextId = 0;

            return;
        }
        $this->assertRegularFile($this->manifestPath, $this->historyPath);

        $raw = $this->readPrivateFile($this->manifestPath, $this->historyPath);

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid file history manifest JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($manifest)
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! is_int($manifest['next_id'] ?? null)
            || ! is_array($manifest['snapshots'] ?? null)) {
            throw new \RuntimeException('Invalid file history manifest structure.');
        }

        $snapshots = [];
        $ids = [];
        $maximumId = -1;
        foreach ($manifest['snapshots'] as $entry) {
            if (! is_array($entry)
                || ! is_int($entry['id'] ?? null)
                || ! is_string($entry['file_path'] ?? null)
                || ! is_string($entry['content_hash'] ?? null)
                || ! is_int($entry['timestamp'] ?? null)
                || ! is_string($entry['blob'] ?? null)
                || basename($entry['blob']) !== $entry['blob']
                || isset($ids[$entry['id']])) {
                throw new \RuntimeException('Invalid file history snapshot entry.');
            }

            $blobFile = $this->blobPath.'/'.$entry['blob'];
            if (@lstat($blobFile) === false) {
                throw new \RuntimeException("Missing or corrupt file history blob: {$entry['blob']}");
            }
            $content = $this->readPrivateFile($blobFile, $this->blobPath);
            if (! hash_equals($entry['content_hash'], hash('sha256', $content))) {
                throw new \RuntimeException("Missing or corrupt file history blob: {$entry['blob']}");
            }

            $ids[$entry['id']] = true;
            $maximumId = max($maximumId, $entry['id']);
            $snapshots[] = new FileSnapshot(
                id: $entry['id'],
                filePath: $entry['file_path'],
                content: $content,
                contentHash: $entry['content_hash'],
                timestamp: $entry['timestamp'],
                blobName: $entry['blob'],
            );
        }

        $this->snapshots = $snapshots;
        $this->nextId = max($manifest['next_id'], $maximumId + 1);
    }

    private function persistManifest(): void
    {
        $manifest = [
            'version' => self::MANIFEST_VERSION,
            'next_id' => $this->nextId,
            'snapshots' => array_map(
                static fn (FileSnapshot $snapshot): array => [
                    'id' => $snapshot->id,
                    'file_path' => $snapshot->filePath,
                    'content_hash' => $snapshot->contentHash,
                    'timestamp' => $snapshot->timestamp,
                    'blob' => $snapshot->blobName,
                ],
                $this->snapshots,
            ),
        ];

        try {
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (\JsonException $e) {
            throw new \RuntimeException('Unable to encode file history manifest.', 0, $e);
        }

        $this->writePrivateFileAtomically(
            $this->manifestPath,
            $json,
            $this->historyPath,
        );
    }

    private function garbageCollectBlobs(): void
    {
        $referenced = [];
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->blobName !== null) {
                $referenced[$snapshot->blobName] = true;
            }
        }

        foreach (glob($this->blobPath.'/*.blob') ?: [] as $blobFile) {
            if (isset($referenced[basename($blobFile)])) {
                continue;
            }
            $this->assertRegularFile($blobFile, $this->blobPath);
            if (! @unlink($blobFile) && is_file($blobFile)) {
                throw new \RuntimeException("Unable to remove stale file history blob: {$blobFile}");
            }
        }
    }

    private function isBlobReferenced(string $blobName): bool
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->blobName === $blobName) {
                return true;
            }
        }

        return false;
    }

    private function ensurePrivateDirectory(string $path, bool $secureExisting = true): void
    {
        if (CanonicalPathResolver::isFilesystemRoot($path)) {
            throw new \RuntimeException(
                "Refusing to secure a filesystem root as file history: {$path}",
            );
        }
        if (is_link($path)) {
            throw new \RuntimeException("Refusing symlink file history directory: {$path}");
        }

        $created = false;
        if (! is_dir($path)) {
            if (@lstat($path) !== false) {
                throw new \RuntimeException("File history path is not a directory: {$path}");
            }
            $created = @mkdir($path, 0700, true);
            if (! $created && ! is_dir($path)) {
                throw new \RuntimeException("Unable to create file history directory: {$path}");
            }
        }
        if (is_link($path)) {
            throw new \RuntimeException("Refusing symlink file history directory: {$path}");
        }
        $stat = @lstat($path);
        if (! is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000) {
            throw new \RuntimeException("File history path is not a directory: {$path}");
        }
        if (! $created && ! $secureExisting) {
            if ($this->isUnsafeSharedRoot($path)) {
                throw new \RuntimeException(
                    "Refusing group/other-writable non-sticky file history root: {$path}",
                );
            }

            return;
        }
        if (! @chmod($path, 0700)) {
            throw new \RuntimeException("Unable to secure file history directory: {$path}");
        }
    }

    private function isUnsafeSharedRoot(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $permissions = @fileperms($path);

        return $permissions === false
            || (($permissions & 0022) !== 0 && ($permissions & 01000) === 0);
    }

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
