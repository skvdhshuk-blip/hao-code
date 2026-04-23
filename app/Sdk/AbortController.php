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

    /** @var callable[] */
    private array $listeners = [];

    /** @api */
    public function abort(): void
    {
        if ($this->aborted) {
            return;
        }

        $this->aborted = true;

        foreach ($this->listeners as $listener) {
            $listener();
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
        if ($this->aborted) {
            $callback();

            return;
        }

        $this->listeners[] = $callback;
    }
}
