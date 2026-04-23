<?php

namespace HaoCode\Services\Cron\Daemon;

use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Telemetry\PhoenixTracer;
use RuntimeException;
use Throwable;

/**
 * Main daemon loop: acquire lock → write PID → tick every 60s → graceful shutdown.
 * Ported from hermes cron/scheduler.py tick() protocol.
 *
 * HAO_CODE_CRON_MODE must be set to "daemon" before starting — fails loudly otherwise.
 */
class Daemon
{
    private const TICK_INTERVAL = 60;

    private TickLock $tickLock;

    private PidFile $pidFile;

    private SignalHandler $signalHandler;

    private JobStore $jobStore;

    private JobExecutor $jobExecutor;

    private PhoenixTracer $tracer;

    private PermissionChecker $permissionChecker;

    public function __construct(
        TickLock $tickLock,
        PidFile $pidFile,
        SignalHandler $signalHandler,
        JobStore $jobStore,
        JobExecutor $jobExecutor,
        PhoenixTracer $tracer,
        PermissionChecker $permissionChecker,
    ) {
        $this->tickLock = $tickLock;
        $this->pidFile = $pidFile;
        $this->signalHandler = $signalHandler;
        $this->jobStore = $jobStore;
        $this->jobExecutor = $jobExecutor;
        $this->tracer = $tracer;
        $this->permissionChecker = $permissionChecker;
    }

    /**
     * Validate HAO_CODE_CRON_MODE=daemon and start the main loop.
     */
    public function run(): void
    {
        // Security red line #1: HAO_CODE_CRON_MODE must be explicitly set
        $mode = getenv('HAO_CODE_CRON_MODE');
        if ($mode === false || $mode === '') {
            throw new RuntimeException(
                'HAO_CODE_CRON_MODE is not set. Set it to "daemon" or "inprocess" explicitly.'
            );
        }
        if ($mode !== 'daemon') {
            throw new RuntimeException(
                "HAO_CODE_CRON_MODE is '{$mode}', expected 'daemon'. Use 'inprocess' mode for in-process cron."
            );
        }

        // Security red line #6: single instance via flock
        if (! $this->tickLock->acquire()) {
            throw new RuntimeException(
                'Another daemon instance is already running (tick lock held: '.$this->tickLock->getPath().')'
            );
        }

        // Security red line #4: daemon is unattended — ask() must never block
        $this->permissionChecker->nonInteractive(true);

        $this->pidFile->write();
        $this->signalHandler->install();

        $span = $this->tracer->startSpan('cron.daemon.tick', PhoenixTracer::KIND_AGENT, [
            'daemon.pid' => getmypid(),
        ]);

        try {
            $this->loop();
        } catch (Throwable $e) {
            if ($span !== null) {
                $this->tracer->recordException($span, $e);
            }
            throw $e;
        } finally {
            if ($span !== null) {
                $span->end();
            }
            $this->cleanup();
        }
    }

    private function loop(): void
    {
        while (true) {
            $this->signalHandler->dispatch();

            if ($this->signalHandler->isShutdownRequested()) {
                break;
            }

            if ($this->signalHandler->isReloadRequested()) {
                $this->signalHandler->clearReload();
                // SIGHUP: reload is implicit — getDueJobs() re-reads DB each tick
            }

            $tickStart = time();
            $this->tick();

            // Sleep until next 60s boundary, dispatching signals during wait
            $elapsed = time() - $tickStart;
            $remaining = self::TICK_INTERVAL - $elapsed;

            for ($i = 0; $i < max(0, $remaining); $i++) {
                $this->signalHandler->dispatch();
                if ($this->signalHandler->isShutdownRequested()) {
                    return;
                }
                sleep(1);
            }
        }
    }

    private function tick(): void
    {
        try {
            $dueJobs = $this->jobStore->getDueJobs();
        } catch (Throwable) {
            return;
        }

        foreach ($dueJobs as $job) {
            $this->signalHandler->dispatch();
            if ($this->signalHandler->isShutdownRequested()) {
                return;
            }

            $signalSpan = $this->tracer->startSpan('cron.daemon.signal', PhoenixTracer::KIND_TOOL, [
                'job_id' => $job['id'],
                'signal' => 'tick',
            ]);

            try {
                $result = $this->jobExecutor->execute($job);

                $this->jobStore->markFired($job['id']);
                $this->jobStore->recordHistory(
                    $job['id'],
                    $result['started_at'],
                    $result['ended_at'],
                    $result['exit_code'],
                    $result['stderr_tail'],
                    $result['secret_detected'],
                );

                if (! (bool) $job['recurring']) {
                    $this->jobStore->markCompleted($job['id']);
                }
            } catch (Throwable $e) {
                if ($signalSpan !== null) {
                    $this->tracer->recordException($signalSpan, $e);
                }
            } finally {
                if ($signalSpan !== null) {
                    $signalSpan->end();
                }
            }
        }
    }

    private function cleanup(): void
    {
        $this->tickLock->release();
        $this->pidFile->remove();
    }
}
