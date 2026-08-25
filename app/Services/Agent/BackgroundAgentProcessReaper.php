<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** Owns SIGCHLD integration and non-blocking child-process collection. @internal */
final class BackgroundAgentProcessReaper
{
    /** @var array<int, \WeakReference> */
    private static array $reapers = [];

    private static bool $signalHandlerInstalled = false;

    private static mixed $previousSignalHandler = null;

    private static ?bool $previousAsyncSignals = null;

    /** @var array<int, array{id: string, token: string}> */
    private array $ownedProcesses = [];

    /** @var list<array{id: string, token: string}> */
    private array $exitedProcesses = [];

    private bool $draining = false;

    private bool $drainAgain = false;

    public function track(int $pid, string $id, string $token): void
    {
        $this->ownedProcesses[$pid] = ['id' => $id, 'token' => $token];
        $this->registerSignalHandler();
    }

    /** @return array{id: string, token: string}|null */
    public function owned(int $pid): ?array
    {
        return $this->ownedProcesses[$pid] ?? null;
    }

    public function owns(int $pid): bool
    {
        return isset($this->ownedProcesses[$pid]);
    }

    /** @return array{id: string, token: string}|null */
    public function shiftExited(): ?array
    {
        $exited = array_shift($this->exitedProcesses);

        return is_array($exited) ? $exited : null;
    }

    /** @return list<string> */
    public function ownedIds(): array
    {
        return array_values(array_map(
            static fn (array $owned): string => $owned['id'],
            $this->ownedProcesses,
        ));
    }

    public function drain(): void
    {
        if ($this->ownedProcesses === [] || ! function_exists('pcntl_waitpid')) {
            return;
        }
        if ($this->draining) {
            $this->drainAgain = true;

            return;
        }

        $this->draining = true;
        try {
            do {
                $this->drainAgain = false;
                foreach ($this->ownedProcesses as $pid => $owned) {
                    $waited = @pcntl_waitpid($pid, $status, WNOHANG);
                    if ($waited === -1) {
                        $interrupted = defined('PCNTL_EINTR')
                            && function_exists('pcntl_get_last_error')
                            && pcntl_get_last_error() === constant('PCNTL_EINTR');
                        if (! $interrupted) {
                            unset($this->ownedProcesses[$pid]);
                        }

                        continue;
                    }
                    if ($waited !== $pid) {
                        continue;
                    }

                    unset($this->ownedProcesses[$pid]);
                    $this->exitedProcesses[] = $owned;
                }
            } while ($this->drainAgain);
        } finally {
            $this->draining = false;
        }
    }

    public static function assertResetSafe(): void
    {
        $ownedIds = [];
        foreach (self::$reapers as $key => $reference) {
            $reaper = $reference->get();
            if (! $reaper instanceof self) {
                unset(self::$reapers[$key]);
                continue;
            }

            $reaper->drain();
            array_push($ownedIds, ...$reaper->ownedIds());
        }

        if ($ownedIds !== []) {
            throw new \RuntimeException(
                'Cannot reset HaoCode runtime while background agents are still running: '
                .implode(', ', array_values(array_unique($ownedIds))).'.',
            );
        }
    }

    public static function resetSignalHandler(): void
    {
        if (self::$signalHandlerInstalled && function_exists('pcntl_signal') && defined('SIGCHLD')) {
            $handler = self::$previousSignalHandler;
            if (! is_callable($handler) && ! is_int($handler)) {
                $handler = defined('SIG_DFL') ? constant('SIG_DFL') : 0;
            }
            pcntl_signal(constant('SIGCHLD'), $handler);
            if (self::$previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals(self::$previousAsyncSignals);
            }
        }

        self::$reapers = [];
        self::$signalHandlerInstalled = false;
        self::$previousSignalHandler = null;
        self::$previousAsyncSignals = null;
    }

    private function registerSignalHandler(): void
    {
        if (! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')
            || ! defined('SIGCHLD')) {
            return;
        }

        self::$reapers[spl_object_id($this)] = \WeakReference::create($this);
        if (self::$signalHandlerInstalled) {
            return;
        }

        $previous = pcntl_signal_get_handler(constant('SIGCHLD'));
        if (defined('SIG_IGN') && $previous === constant('SIG_IGN')) {
            return;
        }

        self::$previousSignalHandler = $previous;
        self::$previousAsyncSignals = pcntl_async_signals();
        $installed = pcntl_signal(
            constant('SIGCHLD'),
            static function (int $signal, array $info = []): void {
                foreach (self::$reapers as $id => $reference) {
                    $reaper = $reference->get();
                    if (! $reaper instanceof self) {
                        unset(self::$reapers[$id]);
                        continue;
                    }
                    $reaper->drain();
                }

                $previous = self::$previousSignalHandler;
                if (is_callable($previous)) {
                    $previous($signal, $info);
                }
            },
        );
        if ($installed) {
            pcntl_async_signals(true);
            self::$signalHandlerInstalled = true;
        }
    }
}
