<?php

namespace HaoCode\Sdk;

/**
 * Cancellation handle for long-running SDK operations.
 *
 * Pass into HaoCodeConfig, then call abort() from another
 * thread, signal handler, or timeout callback.
 *
 * @api
 */
class AbortController
{
    private bool $aborted = false;

    /** @var array<int, callable> */
    private array $listeners = [];

    private int $nextListenerId = 1;

    /** @api */
    public function abort(): void
    {
        if ($this->aborted) {
            return;
        }

        $this->aborted = true;
        $listeners = $this->listeners;
        $this->listeners = [];
        $firstException = null;

        foreach ($listeners as $listener) {
            try {
                $listener();
            } catch (\Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        if ($firstException !== null) {
            throw $firstException;
        }
    }

    /** @api */
    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /** @api */
    public function onAbort(callable $callback): void
    {
        $this->subscribe($callback);
    }

    /**
     * Register a removable cancellation listener for an SDK run.
     *
     * @return \Closure(): void
     * @internal
     */
    public function subscribe(callable $callback): \Closure
    {
        if ($this->aborted) {
            $callback();

            return static function (): void {};
        }

        $listenerId = $this->nextListenerId++;
        $this->listeners[$listenerId] = $callback;

        return function () use ($listenerId): void {
            unset($this->listeners[$listenerId]);
        };
    }
}
