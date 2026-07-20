<?php

namespace HaoCode\Services\Cron\Daemon;

use HaoCode\Services\Permissions\Policy\PolicyDecisionKind;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite-backed job store via PDO.
 * WAL mode + schema version validation on startup.
 * Security red line #7: PolicyMatcher is mandatory — addJob() always runs precheck.
 */
class JobStore
{
    private const SCHEMA_VERSION = 1;

    private PDO $pdo;

    private PolicyMatcher $policyMatcher;

    /**
     * @param  PolicyMatcher  $policyMatcher  Required. Pass PolicyMatcher::allowAll() in tests
     *                                        that don't need policy enforcement.
     */
    public function __construct(?string $dbPath = null, ?PolicyMatcher $policyMatcher = null)
    {
        // Default to a fully-open matcher so callers that omit it still run the precheck
        // path (but allow everything). Callers that need real enforcement must inject rules.
        $this->policyMatcher = $policyMatcher ?? PolicyMatcher::allowAll();
        $path = $dbPath ?? $this->defaultDbPath();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        try {
            $this->pdo = new PDO('sqlite:'.$path, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot open SQLite database: '.$e->getMessage(), 0, $e);
        }

        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $this->initSchema();
        $this->validateSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_version (
                version INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS jobs (
                id          TEXT PRIMARY KEY,
                cron        TEXT NOT NULL,
                command     TEXT NOT NULL,
                durable     INTEGER NOT NULL DEFAULT 1,
                recurring   INTEGER NOT NULL DEFAULT 1,
                last_fired  INTEGER,
                fire_count  INTEGER NOT NULL DEFAULT 0,
                status      TEXT NOT NULL DEFAULT 'active',
                created_at  INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS job_history (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id          TEXT NOT NULL,
                started_at      INTEGER NOT NULL,
                ended_at        INTEGER NOT NULL,
                exit_code       INTEGER NOT NULL,
                stderr_tail     BLOB,
                secret_detected INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (job_id) REFERENCES jobs(id)
            );
        SQL);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
        if ($count === 0) {
            $stmt = $this->pdo->prepare('INSERT INTO schema_version (version) VALUES (?)');
            $stmt->execute([self::SCHEMA_VERSION]);
        }
    }

    /** Validate that DB schema version matches expected version. */
    private function validateSchema(): void
    {
        $version = (int) $this->pdo->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
        if ($version !== self::SCHEMA_VERSION) {
            throw new RuntimeException(
                sprintf('SQLite schema version mismatch: expected %d, found %d', self::SCHEMA_VERSION, $version)
            );
        }
    }

    /**
     * Add a job after validating cron syntax and running PolicyMatcher precheck.
     * Security red line #7: policy failure immediately rejects the job.
     */
    public function addJob(string $id, string $cron, string $command, bool $durable = true, bool $recurring = true): void
    {
        // Cron syntax precheck
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression (must have 5 fields): {$cron}");
        }

        // Policy precheck (security red line #7) — always executed, never skippable.
        // On the cron daemon path, NotApplicable (no rule matched) is treated
        // as Deny: an unattended scheduler must be fail-closed, unlike the
        // interactive PermissionChecker path where NotApplicable falls through
        // to the normal permission pipeline.
        $cmdParts = preg_split('/\s+/', trim($command), 2);
        $binary = $cmdParts[0] ?? $command;
        $args = $cmdParts[1] ?? '';
        $decision = $this->policyMatcher->match('Bash', $binary, ['args' => $args]);
        if (
            $decision->kind === PolicyDecisionKind::Deny
            || $decision->kind === PolicyDecisionKind::NotApplicable
        ) {
            throw new RuntimeException(
                'Job rejected by policy: '.($decision->reason ?? 'no matching rule (fail-closed)')
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (id, cron, command, durable, recurring, status, created_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?)'
        );
        $stmt->execute([$id, $cron, $command, (int) $durable, (int) $recurring, time()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDueJobs(): array
    {
        $now = time();
        $rows = $this->pdo->query(
            "SELECT * FROM jobs WHERE status = 'active'"
        )->fetchAll();

        $due = [];
        foreach ($rows as $row) {
            if ($this->isCronDue($row['cron'], $now, (int) ($row['last_fired'] ?? 0))) {
                $due[] = $row;
            }
        }

        return $due;
    }

    public function markFired(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET last_fired = ?, fire_count = fire_count + 1 WHERE id = ?'
        );
        $stmt->execute([time(), $id]);
    }

    public function markCompleted(string $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function recordHistory(
        string $jobId,
        int $startedAt,
        int $endedAt,
        int $exitCode,
        string $stderrTail,
        bool $secretDetected
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO job_history (job_id, started_at, ended_at, exit_code, stderr_tail, secret_detected)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$jobId, $startedAt, $endedAt, $exitCode, $stderrTail, (int) $secretDetected]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(string $jobId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM job_history WHERE job_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$jobId, $limit]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllJobs(): array
    {
        return $this->pdo->query('SELECT * FROM jobs ORDER BY created_at DESC')->fetchAll();
    }

    public function getJob(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function removeJob(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    private function isCronDue(string $cron, int $now, int $lastFired): bool
    {
        // Don't re-fire within the same minute
        if ($now - $lastFired < 60) {
            return false;
        }

        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            return false;
        }

        $dt = new \DateTime('@'.$now);
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $this->matchField($parts[0], (int) $dt->format('i'), 0, 59)
            && $this->matchField($parts[1], (int) $dt->format('H'), 0, 23)
            && $this->matchField($parts[2], (int) $dt->format('j'), 1, 31)
            && $this->matchField($parts[3], (int) $dt->format('n'), 1, 12)
            && $this->matchField($parts[4], (int) $dt->format('w'), 0, 6);
    }

    private function matchField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }
        if (preg_match('/^\*\/(\d+)$/', $field, $m)) {
            $step = (int) $m[1];

            return $step > 0 && ($value - $min) % $step === 0;
        }
        if (ctype_digit($field)) {
            return $value === (int) $field;
        }
        if (preg_match('/^(\d+)-(\d+)$/', $field, $m)) {
            return $value >= (int) $m[1] && $value <= (int) $m[2];
        }
        if (str_contains($field, ',')) {
            return in_array($value, array_map('intval', explode(',', $field)));
        }

        return false;
    }

    private function defaultDbPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return $home.'/.haocode/cron/jobs.sqlite';
    }
}
