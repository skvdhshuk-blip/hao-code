<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Cron\Daemon\JobExecutor;
use HaoCode\Services\Cron\Daemon\JobStore;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use Illuminate\Console\Command;

/**
 * `hao-code cron:retry <job_id>` — manually re-runs a job immediately.
 */
class CronRetryCommand extends Command
{
    protected $signature = 'cron:retry
        {job_id : Job ID to retry}
        {--db= : Path to SQLite database}';

    protected $description = 'Manually retry a cron job immediately';

    public function handle(SettingsManager $settings, PhoenixTracer $tracer): int
    {
        $jobId = $this->argument('job_id');
        $store = new JobStore($this->option('db') ?: null);

        $job = $store->getJob($jobId);
        if ($job === null) {
            $this->error("Job not found: {$jobId}");

            return self::FAILURE;
        }

        $this->info("Retrying job {$jobId}...");
        $this->line('  command: '.mb_substr((string) $job['command'], 0, 80));

        $executor = new JobExecutor($tracer, new SecretScanner);
        $result = $executor->execute($job);

        $store->markFired($jobId);
        $store->recordHistory(
            $jobId,
            $result['started_at'],
            $result['ended_at'],
            $result['exit_code'],
            $result['stderr_tail'],
            $result['secret_detected'],
        );

        $duration = $result['ended_at'] - $result['started_at'];
        $exit = $result['exit_code'];

        if ($exit === 0) {
            $this->info("Completed in {$duration}s (exit=0).");
        } else {
            $this->error("Failed in {$duration}s (exit={$exit}).");

            if ($result['stderr_tail'] !== '') {
                $this->line('stderr: '.mb_substr($result['stderr_tail'], 0, 300));
            }
        }

        if ($result['secret_detected']) {
            $this->warn('Secret detected in output — stderr masked in history.');
        }

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
