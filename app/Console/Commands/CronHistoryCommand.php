<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Cron\Daemon\JobStore;
use Illuminate\Console\Command;

/**
 * `hao-code cron:history [job_id]` — shows execution history from the SQLite store.
 */
class CronHistoryCommand extends Command
{
    protected $signature = 'cron:history
        {job_id? : Specific job ID (omit for all jobs summary)}
        {--limit=20 : Number of history entries to show}
        {--db= : Path to SQLite database}';

    protected $description = 'Show cron job execution history';

    public function handle(): int
    {
        $store = new JobStore($this->option('db') ?: null);
        $jobId = $this->argument('job_id');
        $limit = (int) $this->option('limit');

        if ($jobId !== null) {
            return $this->showJobHistory($store, $jobId, $limit);
        }

        return $this->showAllJobs($store);
    }

    private function showJobHistory(JobStore $store, string $jobId, int $limit): int
    {
        $job = $store->getJob($jobId);
        if ($job === null) {
            $this->error("Job not found: {$jobId}");

            return self::FAILURE;
        }

        $history = $store->getHistory($jobId, $limit);

        if (empty($history)) {
            $this->info("No history for job {$jobId}.");

            return self::SUCCESS;
        }

        $this->line("History for {$jobId} (showing ".count($history).' entries):');
        $this->line(str_repeat('-', 60));

        foreach ($history as $entry) {
            $started = date('Y-m-d H:i:s', (int) $entry['started_at']);
            $duration = (int) $entry['ended_at'] - (int) $entry['started_at'];
            $exit = (int) $entry['exit_code'];
            $secret = (bool) $entry['secret_detected'] ? ' [secret_detected]' : '';
            $status = $exit === 0 ? '<info>OK</info>' : "<error>FAIL({$exit})</error>";

            $this->line("  {$started}  {$duration}s  exit={$exit}  {$status}{$secret}");

            if ($entry['stderr_tail'] !== '' && $entry['stderr_tail'] !== null) {
                $stderr = mb_substr((string) $entry['stderr_tail'], 0, 200);
                $this->line('    stderr: '.str_replace("\n", ' ', $stderr));
            }
        }

        return self::SUCCESS;
    }

    private function showAllJobs(JobStore $store): int
    {
        $jobs = $store->getAllJobs();

        if (empty($jobs)) {
            $this->info('No jobs in store.');

            return self::SUCCESS;
        }

        $this->line('Jobs ('.count($jobs).'):');
        $this->line(str_repeat('-', 60));

        foreach ($jobs as $job) {
            $fires = (int) $job['fire_count'];
            $status = $job['status'];
            $last = $job['last_fired'] ? date('Y-m-d H:i:s', (int) $job['last_fired']) : 'never';
            $cmd = mb_substr((string) $job['command'], 0, 50);

            $this->line("  {$job['id']}  [{$status}]  fires={$fires}  last={$last}");
            $this->line("    cron: {$job['cron']}  cmd: {$cmd}");
        }

        return self::SUCCESS;
    }
}
