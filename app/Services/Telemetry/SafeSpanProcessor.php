<?php

namespace App\Services\Telemetry;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Throwable;

/**
 * Telemetry-never-load-bearing guard.
 *
 * The default {@see \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor}
 * synchronously flushes when its queue is full or when a batch size is reached,
 * and it propagates export failures (e.g. 4xx from the OTLP endpoint, WAF
 * rejections, DNS errors) out through {@see SpanProcessorInterface::onEnd()}.
 *
 * That path is called from {@see \OpenTelemetry\SDK\Trace\Span::end()}, which in
 * turn is called from the agent's tool / query / turn `finally` blocks. A
 * bubbling RuntimeException there will kill an in-flight tool execution and
 * break the agent loop — even though telemetry is supposed to be a best-effort
 * side channel.
 *
 * This wrapper catches every Throwable from the inner processor and swallows
 * it. Dropped spans are a lesser evil than a crashed agent.
 */
final class SafeSpanProcessor implements SpanProcessorInterface
{
    public function __construct(private readonly SpanProcessorInterface $inner) {}

    #[\Override]
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        try {
            $this->inner->onStart($span, $parentContext);
        } catch (Throwable) {
            // Never let telemetry bring down the caller.
        }
    }

    #[\Override]
    public function onEnd(ReadableSpanInterface $span): void
    {
        try {
            $this->inner->onEnd($span);
        } catch (Throwable) {
            // Never let telemetry bring down the caller.
        }
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->forceFlush($cancellation);
        } catch (Throwable) {
            return false;
        }
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->shutdown($cancellation);
        } catch (Throwable) {
            return false;
        }
    }
}
