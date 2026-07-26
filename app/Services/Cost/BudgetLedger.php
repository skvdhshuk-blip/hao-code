<?php

namespace HaoCode\Services\Cost;

/**
 * Process-safe budget ledger shared by one root run and all descendants.
 *
 * @internal
 */
final class BudgetLedger
{
    private const GC_INTERVAL_SECONDS = 86400;

    private const STALE_AFTER_SECONDS = 7776000;

    private function __construct(
        private readonly string $id,
        private readonly float $limit,
        private readonly string $path,
    ) {}

    public static function create(float $limit): self
    {
        if (! is_finite($limit) || $limit < 0) {
            throw new \InvalidArgumentException('Budget limit must be a non-negative finite amount.');
        }

        $directory = self::directory();
        self::ensureDirectory($directory);
        self::collectGarbage($directory);

        $id = bin2hex(random_bytes(16));
        $ledger = new self($id, $limit, $directory.'/budget-'.$id.'.json');
        $ledger->writeInitialState(0.0);

        return $ledger;
    }

    public static function resume(string $id, float $limit, float $minimumSpent = 0.0): self
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid budget ledger id.');
        }
        if (! is_finite($limit) || $limit < 0) {
            throw new \InvalidArgumentException('Budget limit must be a non-negative finite amount.');
        }

        $directory = self::directory();
        self::ensureDirectory($directory);
        $path = $directory.'/budget-'.$id.'.json';

        if (! is_file($path)) {
            $ledger = new self($id, $limit, $path);
            try {
                $ledger->writeInitialState(max(0.0, $minimumSpent));
            } catch (\RuntimeException $e) {
                if (! is_file($path)) {
                    throw $e;
                }
                // Another process created it first; reconciling limit below.
                return self::resumeExisting($id, $limit, $minimumSpent, $path);
            }

            return $ledger;
        }

        return self::resumeExisting($id, $limit, $minimumSpent, $path);
    }

    /**
     * Resume an existing ledger under exclusive lock. Limits may only tighten
     * (min of stored and requested); never widen.
     */
    private static function resumeExisting(string $id, float $limit, float $minimumSpent, string $path): self
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the shared budget ledger.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the shared budget ledger.');
            }
            rewind($handle);
            $decoded = json_decode(stream_get_contents($handle) ?: '', true);
            if (! is_array($decoded)
                || ! is_numeric($decoded['limit'] ?? null)
                || ! is_numeric($decoded['spent'] ?? null)) {
                throw new \RuntimeException('Shared budget ledger is corrupt.');
            }
            $storedLimit = (float) $decoded['limit'];
            $spent = max(0.0, (float) $decoded['spent'], $minimumSpent);
            // Monotonic tighten only: 10→5 succeeds; 5→10 stays 5.
            $effectiveLimit = min($storedLimit, $limit);
            $state = ['limit' => $effectiveLimit, 'spent' => $spent];

            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (! ftruncate($handle, 0)) {
                throw new \RuntimeException('Could not truncate the shared budget ledger.');
            }
            $written = fwrite($handle, $encoded);
            if ($written === false || $written !== strlen($encoded) || ! fflush($handle)) {
                throw new \RuntimeException('Could not persist the shared budget ledger.');
            }

            return new self($id, $effectiveLimit, $path);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLimit(): float
    {
        return $this->limit;
    }

    public function add(float $cost): float
    {
        if (! is_finite($cost) || $cost < 0) {
            throw new \InvalidArgumentException('Budget cost increment must be a non-negative finite amount.');
        }

        return $this->withExclusiveState(function (array $state) use ($cost): array {
            $state['spent'] += $cost;

            return $state;
        });
    }

    public function ensureAtLeast(float $spent): float
    {
        $spent = is_finite($spent) ? max(0.0, $spent) : 0.0;

        return $this->withExclusiveState(function (array $state) use ($spent): array {
            $state['spent'] = max($state['spent'], $spent);

            return $state;
        });
    }

    public function getSpent(): float
    {
        $handle = $this->open();
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Could not lock the shared budget ledger.');
            }
            $state = $this->readState($handle);

            return $state['spent'];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function shouldStop(): bool
    {
        return $this->getSpent() >= $this->limit;
    }

    private function writeInitialState(float $spent): void
    {
        $handle = @fopen($this->path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not create the shared budget ledger.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the new shared budget ledger.');
            }
            $this->writeState($handle, [
                'limit' => $this->limit,
                'spent' => $spent,
            ]);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($this->path, 0600);
    }

    /**
     * @param callable(array{limit: float, spent: float}): array{limit: float, spent: float} $mutator
     */
    private function withExclusiveState(callable $mutator): float
    {
        $handle = $this->open();
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the shared budget ledger.');
            }
            $state = $mutator($this->readState($handle));
            $state['limit'] = $this->limit;
            $this->writeState($handle, $state);

            return $state['spent'];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource */
    private function open()
    {
        $handle = @fopen($this->path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the shared budget ledger.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return array{limit: float, spent: float}
     */
    private function readState($handle): array
    {
        rewind($handle);
        $decoded = json_decode(stream_get_contents($handle) ?: '', true);
        if (! is_array($decoded)
            || ! is_numeric($decoded['limit'] ?? null)
            || ! is_numeric($decoded['spent'] ?? null)) {
            throw new \RuntimeException('Shared budget ledger is corrupt.');
        }
        $storedLimit = (float) $decoded['limit'];
        // Disk may be tighter than this object if another resume tightened it;
        // never allow the in-memory limit to widen past the object limit.
        if ($storedLimit - $this->limit > 0.0000001) {
            throw new \RuntimeException(
                'Shared budget ledger limit is wider than the run configuration; cannot widen a budget.',
            );
        }

        return [
            'limit' => min($storedLimit, $this->limit),
            'spent' => max(0.0, (float) $decoded['spent']),
        ];
    }

    /**
     * @param resource $handle
     * @param array{limit: float, spent: float} $state
     */
    private function writeState($handle, array $state): void
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (! ftruncate($handle, 0)) {
            throw new \RuntimeException('Could not truncate the shared budget ledger.');
        }
        $written = fwrite($handle, $encoded);
        if ($written === false || $written !== strlen($encoded) || ! fflush($handle)) {
            throw new \RuntimeException('Could not persist the shared budget ledger.');
        }
    }

    private static function directory(): string
    {
        return \HaoCode\Support\Runtime\SdkRuntime::storagePath('app/haocode/budgets');
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)
            && ! @mkdir($directory, 0700, true)
            && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create shared budget directory: {$directory}");
        }
    }

    /**
     * Expired ledgers can be reconstructed from durable run snapshots.
     * Throttling avoids a directory scan for every budgeted SDK call.
     */
    private static function collectGarbage(string $directory): void
    {
        $marker = @fopen($directory.'/.gc', 'c+');
        if ($marker === false) {
            return;
        }

        try {
            if (! flock($marker, LOCK_EX | LOCK_NB)) {
                return;
            }
            rewind($marker);
            $lastCollection = (int) trim(stream_get_contents($marker) ?: '0');
            $now = time();
            if ($lastCollection > $now - self::GC_INTERVAL_SECONDS) {
                return;
            }
            rewind($marker);
            if (! ftruncate($marker, 0)
                || fwrite($marker, (string) $now) === false
                || ! fflush($marker)) {
                return;
            }

            $cutoff = $now - self::STALE_AFTER_SECONDS;
            foreach (glob($directory.'/budget-*.json') ?: [] as $path) {
                if (preg_match('/^budget-[a-f0-9]{32}\.json$/', basename($path)) !== 1) {
                    continue;
                }
                $modified = @filemtime($path);
                if ($modified !== false && $modified < $cutoff) {
                    @unlink($path);
                }
            }
        } finally {
            @flock($marker, LOCK_UN);
            fclose($marker);
        }
    }
}
