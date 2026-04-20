<?php

namespace Tests\Unit;

use App\Services\Telemetry\SafeSpanProcessor;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use PHPUnit\Framework\TestCase;

class SafeSpanProcessorTest extends TestCase
{
    public function test_on_start_swallows_inner_exceptions(): void
    {
        $inner = $this->makeThrowingProcessor();
        $wrapper = new SafeSpanProcessor($inner);

        $wrapper->onStart(
            $this->createMock(ReadWriteSpanInterface::class),
            $this->createMock(ContextInterface::class),
        );

        $this->addToAssertionCount(1);
    }

    public function test_on_end_swallows_inner_exceptions(): void
    {
        $inner = $this->makeThrowingProcessor();
        $wrapper = new SafeSpanProcessor($inner);

        $wrapper->onEnd($this->createMock(ReadableSpanInterface::class));

        $this->addToAssertionCount(1);
    }

    public function test_force_flush_returns_false_on_inner_failure(): void
    {
        $wrapper = new SafeSpanProcessor($this->makeThrowingProcessor());

        $this->assertFalse($wrapper->forceFlush());
    }

    public function test_shutdown_returns_false_on_inner_failure(): void
    {
        $wrapper = new SafeSpanProcessor($this->makeThrowingProcessor());

        $this->assertFalse($wrapper->shutdown());
    }

    public function test_success_path_delegates_to_inner(): void
    {
        $inner = $this->createMock(SpanProcessorInterface::class);
        $span = $this->createMock(ReadableSpanInterface::class);
        $inner->expects($this->once())->method('onEnd')->with($span);
        $inner->method('forceFlush')->willReturn(true);
        $inner->method('shutdown')->willReturn(true);

        $wrapper = new SafeSpanProcessor($inner);
        $wrapper->onEnd($span);

        $this->assertTrue($wrapper->forceFlush());
        $this->assertTrue($wrapper->shutdown());
    }

    private function makeThrowingProcessor(): SpanProcessorInterface
    {
        $proc = $this->createMock(SpanProcessorInterface::class);
        $proc->method('onStart')->willThrowException(new \RuntimeException('export failed'));
        $proc->method('onEnd')->willThrowException(new \RuntimeException('export failed'));
        $proc->method('forceFlush')->willThrowException(new \RuntimeException('flush failed'));
        $proc->method('shutdown')->willThrowException(new \RuntimeException('shutdown failed'));

        return $proc;
    }
}
