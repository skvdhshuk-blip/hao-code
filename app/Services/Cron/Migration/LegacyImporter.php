<?php

namespace HaoCode\Services\Cron\Migration;

use HaoCode\Services\Cron\Daemon\JobStore;

/**
 * One-shot migration from legacy scheduled_tasks.json to SQLite JobStore.
 * Renames the source file to .bak after a successful import.
 */
class LegacyImporter
{
    public function __construct(
        private readonly JobStore $jobStore,
        private readonly string $legacyPath = '',
    ) {}

    /**
     * Import jobs from scheduled_tasks.json into SQLite.
     * Returns the count of jobs imported, or 0 if source file not found.
     */
    public function import(): int
    {
        $path = $this->resolveLegacyPath();

        if (! file_exists($path)) {
            return 0;
        }

        $content = file_get_contents($path);
        $jobs = json_decode($content, true);

        if (! is_array($jobs)) {
            return 0;
        }

        $imported = 0;

        foreach ($jobs as $id => $job) {
            if (($job['status'] ?? '') !== 'active') {
                continue;
            }

            $cron = $job['cron'] ?? '';
            $command = $job['prompt'] ?? '';
            if ($cron === '' || $command === '') {
                continue;
            }

            // Skip if already in SQLite
            if ($this->jobStore->getJob((string) $id) !== null) {
                continue;
            }

            try {
                $this->jobStore->addJob(
                    id: (string) $id,
                    cron: $cron,
                    command: $command,
                    durable: (bool) ($job['durable'] ?? true),
                    recurring: (bool) ($job['recurring'] ?? true),
                );
                $imported++;
            } catch (\Throwable) {
                // Skip malformed records; don't abort the whole migration
            }
        }

        if ($imported > 0) {
            @rename($path, $path.'.bak');
        }

        return $imported;
    }

    private function resolveLegacyPath(): string
    {
        if ($this->legacyPath !== '') {
            return $this->legacyPath;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return $home.'/.haocode/scheduled_tasks.json';
    }
}
