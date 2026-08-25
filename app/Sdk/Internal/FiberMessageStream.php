<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Services\Agent\AgentLoop;

/** @internal */
final class FiberMessageStream
{
    private \SplQueue $queue;

    private ?\Fiber $fiber = null;

    private ?\Throwable $thrown = null;

    private mixed $result = null;

    private bool $handlerRegistered = false;

    private bool $released = false;

    private bool $started = false;

    private bool $terminalReturned = false;

    private readonly int $ownerPid;

    private readonly ?\Closure $onText;

    private readonly ?\Closure $onToolStart;

    private readonly ?\Closure $onToolComplete;

    private readonly ?\Closure $onTurnStart;

    public function __construct(
        private readonly AgentLoop $loop,
        private readonly \Closure $operation,
        private readonly \Closure $terminalMessage,
        private readonly \Closure $release,
        private readonly \Closure $preserveInterrupt,
        mixed $onText = null,
        mixed $onToolStart = null,
        mixed $onToolComplete = null,
        mixed $onTurnStart = null,
    ) {
        $this->ownerPid = getmypid();
        $this->queue = new \SplQueue;
        $this->onText = $this->normalizeCallback($onText);
        $this->onToolStart = $this->normalizeCallback($onToolStart);
        $this->onToolComplete = $this->normalizeCallback($onToolComplete);
        $this->onTurnStart = $this->normalizeCallback($onTurnStart);
    }

    public function __destruct()
    {
        $this->abandon();
    }

    public function nextMessage(): ?Message
    {
        if ($this->terminalReturned) {
            return null;
        }
        if (! $this->started) {
            $this->started = true;
            $this->registerHandler();
            $callbacks = $this->callbacks();
            $operation = $this->operation;
            $this->fiber = new \Fiber(static function () use ($callbacks, $operation): array {
                try {
                    return ['result' => $operation(...$callbacks), 'thrown' => null];
                } catch (\Throwable $exception) {
                    return ['result' => null, 'thrown' => $exception];
                }
            });
            $this->fiber->start();
        }

        while (true) {
            if (! $this->queue->isEmpty()) {
                return $this->queue->dequeue();
            }
            if ($this->fiber !== null && ! $this->fiber->isTerminated()) {
                $this->fiber->resume();

                continue;
            }

            $terminal = $this->fiber?->getReturn() ?? ['result' => null, 'thrown' => null];
            $this->result = $terminal['result'];
            $this->thrown = $terminal['thrown'];
            $this->fiber = null;
            $this->terminalReturned = true;
            if ($this->thrown instanceof HumanInterruptException) {
                ($this->preserveInterrupt)();
                $this->releaseOnce();

                return Message::interrupt($this->thrown->interrupt);
            }
            $this->releaseOnce();

            return $this->thrown !== null
                ? Message::error($this->thrown->getMessage())
                : ($this->terminalMessage)($this->result);
        }
    }

    public function abandon(): void
    {
        // Early tool execution forks while this stream is active. The child
        // inherits this object, but its shutdown must never abort or release
        // the parent-owned run and shared cancellation token.
        if (getmypid() !== $this->ownerPid) {
            return;
        }
        if ($this->started && ! $this->terminalReturned) {
            $this->loop->abort();
        }
        if ($this->fiber?->isStarted() && ! $this->fiber->isTerminated()) {
            // Abandonment is cancellation. Never resume work from a
            // Generator destructor; dropping the Fiber is PHP 8.1-safe.
            $this->fiber = null;
        }
        $this->terminalReturned = true;
        if ($this->thrown instanceof HumanInterruptException) {
            ($this->preserveInterrupt)();
        }
        $this->releaseOnce();
    }

    /** @return array{0: \Closure, 1: \Closure, 2: \Closure, 3: \Closure} */
    private function callbacks(): array
    {
        $queue = $this->queue;
        $onText = $this->onText;
        $onToolStart = $this->onToolStart;
        $onToolComplete = $this->onToolComplete;
        $onTurnStart = $this->onTurnStart;

        return [
            static function (string $delta) use ($queue, $onText): void {
                $queue->enqueue(Message::text($delta));
                $onText?->__invoke($delta);
                \Fiber::getCurrent()?->suspend();
            },
            static function (string $name, array $input) use ($queue, $onToolStart): void {
                $queue->enqueue(Message::toolStart($name, $input));
                $onToolStart?->__invoke($name, $input);
                \Fiber::getCurrent()?->suspend();
            },
            static function (string $name, mixed $result) use ($queue, $onToolComplete): void {
                $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
                $onToolComplete?->__invoke($name, $result);
                \Fiber::getCurrent()?->suspend();
            },
            static function (int $turn) use ($queue, $onTurnStart): void {
                $queue->enqueue(Message::turn($turn));
                $onTurnStart?->__invoke($turn);
                \Fiber::getCurrent()?->suspend();
            },
        ];
    }

    private function registerHandler(): void
    {
        $queue = $this->queue;
        $this->loop->setAutoDecisionHandler(static function (Message $message) use ($queue): void {
            $queue->enqueue($message);
            \Fiber::getCurrent()?->suspend();
        });
        $this->handlerRegistered = true;
    }

    private function releaseOnce(): void
    {
        if ($this->released) {
            return;
        }
        if ($this->handlerRegistered) {
            $this->loop->setAutoDecisionHandler(null);
            $this->handlerRegistered = false;
        }
        $this->released = true;
        ($this->release)();
    }

    private function normalizeCallback(mixed $callback): ?\Closure
    {
        return $callback === null ? null : \Closure::fromCallable($callback);
    }
}
