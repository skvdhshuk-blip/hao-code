<?php

namespace HaoCode\Services\Cron\Daemon;

/**
 * Installs pcntl signal handlers for the daemon process.
 * SIGTERM/SIGINT → graceful shutdown flag.
 * SIGHUP → reload flag (restarts job loading without stopping).
 */
class SignalHandler
{
    private bool $shutdownRequested = false;

    private bool $reloadRequested = false;

    public function install(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->shutdownRequested = true;
        });

        pcntl_signal(SIGINT, function (): void {
            $this->shutdownRequested = true;
        });

        // SIGHUP triggers job reload without full shutdown
        if (defined('SIGHUP')) {
            pcntl_signal(SIGHUP, function (): void {
                $this->reloadRequested = true;
            });
        }
    }

    public function dispatch(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    public function isReloadRequested(): bool
    {
        return $this->reloadRequested;
    }

    public function clearReload(): void
    {
        $this->reloadRequested = false;
    }
}
