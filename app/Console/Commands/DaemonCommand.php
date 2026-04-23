<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Cron\Daemon\Daemon;
use HaoCode\Services\Cron\Daemon\JobExecutor;
use HaoCode\Services\Cron\Daemon\JobStore;
use HaoCode\Services\Cron\Daemon\PidFile;
use HaoCode\Services\Cron\Daemon\SignalHandler;
use HaoCode\Services\Cron\Daemon\TickLock;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `hao-code daemon {start|stop|status}` — manages the cron daemon process.
 *
 * Security red lines enforced here:
 * - HAO_CODE_CRON_MODE must be "daemon" or command exits non-zero
 * - PidFile 0600 via PidFile class
 * - Single instance via TickLock flock
 */
class DaemonCommand extends Command
{
    protected $signature = 'daemon
        {action : start|stop|status}
        {--db= : Path to SQLite database}
        {--pid= : Path to PID file}';

    protected $description = 'Manage the cron daemon (start|stop|status)';

    public function handle(SettingsManager $settings, PhoenixTracer $tracer): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'start' => $this->start($settings, $tracer),
            'stop' => $this->stop(),
            'status' => $this->status(),
            default => $this->invalidAction($action),
        };
    }

    private function start(SettingsManager $settings, PhoenixTracer $tracer): int
    {
        // Security red line #1: explicit mode check
        $mode = getenv('HAO_CODE_CRON_MODE');
        if ($mode === false || $mode === '') {
            $this->error('HAO_CODE_CRON_MODE is not set. Set it to "daemon" to use daemon mode.');

            return self::FAILURE;
        }
        if ($mode !== 'daemon') {
            $this->error("HAO_CODE_CRON_MODE='{$mode}' — expected 'daemon'. Refusing to start.");

            return self::FAILURE;
        }

        $pidFile = new PidFile($this->option('pid') ?: null);

        if ($pidFile->isRunning()) {
            $this->error('Daemon is already running (PID: '.$pidFile->read().').');

            return self::FAILURE;
        }

        $this->info('Starting cron daemon...');

        $tickLock = new TickLock;
        $signalHandler = new SignalHandler;
        $secretScanner = new SecretScanner;
        $jobStore = new JobStore($this->option('db') ?: null);
        $jobExecutor = new JobExecutor($tracer, $secretScanner);
        $permissionChecker = new PermissionChecker($settings, new DenialTracker);

        $daemon = new Daemon(
            $tickLock,
            $pidFile,
            $signalHandler,
            $jobStore,
            $jobExecutor,
            $tracer,
            $permissionChecker,
        );

        try {
            $daemon->run();
        } catch (RuntimeException $e) {
            $this->error('Daemon error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function stop(): int
    {
        $pidFile = new PidFile($this->option('pid') ?: null);
        $pid = $pidFile->read();

        if ($pid === null || ! $pidFile->isRunning()) {
            $this->warn('Daemon is not running.');

            return self::SUCCESS;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
            $this->info("Sent SIGTERM to daemon (PID {$pid}).");
        } else {
            $this->error('posix_kill not available — cannot send SIGTERM.');

            return self::FAILURE;
        }

        // Wait up to 5 seconds for daemon to exit
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            if (! $pidFile->isRunning()) {
                $this->info('Daemon stopped.');

                return self::SUCCESS;
            }
        }

        $this->warn('Daemon did not stop within 5 seconds — may still be running.');

        return self::FAILURE;
    }

    private function status(): int
    {
        $pidFile = new PidFile($this->option('pid') ?: null);

        if ($pidFile->isRunning()) {
            $this->info('Daemon is running (PID: '.$pidFile->read().').');
        } else {
            $this->info('Daemon is not running.');
        }

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action '{$action}'. Use: start, stop, or status.");

        return self::INVALID;
    }
}
