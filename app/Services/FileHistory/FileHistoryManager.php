<?php

namespace HaoCode\Services\FileHistory;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\FileEdit\DiffGenerator;

/**
 * Tracks file changes across a session with durable snapshots.
 */
class FileHistoryManager
{
    use FileHistoryManagerConstructConcern;
    use FileHistoryManagerCanonicalDescendantConcern;

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
}
