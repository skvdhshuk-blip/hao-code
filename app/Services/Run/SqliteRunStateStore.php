<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

use PDO;

/** SQLite implementation of the P1/P2 run-state contracts. @internal */
final class SqliteRunStateStore implements DurableRunStateStoreInterface
{
    use SqliteRunStateStoreSchemaConcern;
    use SqliteRunStateStoreEventConcern;
    use SqliteRunStateStoreExecutionConcern;

    private const SCHEMA_VERSION = 1;

    private PDO $pdo;

    public function __construct(private readonly string $databasePath)
    {
        if (trim($databasePath) === '') {
            throw new \InvalidArgumentException('SQLite run store path must not be empty.');
        }
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('SQLite run store requires ext-pdo_sqlite.');
        }
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Could not create run store directory: {$directory}");
            }
        }

        $this->pdo = new PDO('sqlite:'.$databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        if ($databasePath !== ':memory:') {
            @chmod($databasePath, 0600);
        }
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=FULL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->initializeSchema();
    }
}
