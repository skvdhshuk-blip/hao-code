<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Cron\Daemon\JobStore;
use Illuminate\Console\Command;

/**
 * `hao-code cron:add "<cron>" "<command>"` — adds a cron job to the SQLite daemon store.
 */
class CronAddCommand extends Command
{
    protected $signature = 'cron:add
        {cron : 5-field cron expression (e.g. "*/1 * * * *")}
        {cmd : Shell command to run}
        {--once : One-shot job (non-recurring)}
        {--db= : Path to SQLite database}';

    protected $description = 'Add a cron job to the daemon job store';

    public function handle(): int
    {
        $cron = $this->argument('cron');
        $command = $this->argument('cmd');
        $recurring = ! $this->option('once');

        $store = new JobStore($this->option('db') ?: null);

        $id = 'cron_'.bin2hex(random_bytes(4));

        try {
            $store->addJob($id, $cron, $command, durable: true, recurring: $recurring);
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid cron expression: '.$e->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error('Job rejected: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Job added: {$id}");
        $this->line("  cron:    {$cron}");
        $this->line('  command: '.mb_substr($command, 0, 80));
        $this->line('  mode:    '.($recurring ? 'recurring' : 'one-shot'));

        return self::SUCCESS;
    }
}
