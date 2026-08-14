<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

trait BackgroundAgentManagerRegisterSignalReaperConcern
{

    private function registerSignalReaper(): void
    {
        if (! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')
            || ! defined('SIGCHLD')) {
            return;
        }

        self::$signalReapers[spl_object_id($this)] = \WeakReference::create($this);
        if (self::$signalReaperInstalled) {
            return;
        }

        $previous = pcntl_signal_get_handler(constant('SIGCHLD'));
        if (defined('SIG_IGN') && $previous === constant('SIG_IGN')) {
            // The host already asks the OS to auto-reap all children.
            return;
        }

        self::$previousSigchldHandler = $previous;
        self::$previousAsyncSignals = pcntl_async_signals();
        $installed = pcntl_signal(
            constant('SIGCHLD'),
            static function (int $signal, array $info = []): void {
                foreach (self::$signalReapers as $id => $reference) {
                    $manager = $reference->get();
                    if (! $manager instanceof self) {
                        unset(self::$signalReapers[$id]);

                        continue;
                    }
                    // Only waitpid and enqueue here. File locks and state
                    // transitions remain in the normal manager call stack.
                    $manager->reapExitedProcessHandles();
                }

                $previous = self::$previousSigchldHandler;
                if (is_callable($previous)) {
                    $previous($signal, $info);
                }
            },
        );
        if ($installed) {
            // Background-agent ownership requires prompt SIGCHLD delivery.
            // Existing callable SIGCHLD handlers are preserved above.
            pcntl_async_signals(true);
            self::$signalReaperInstalled = true;
        }
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }
}
