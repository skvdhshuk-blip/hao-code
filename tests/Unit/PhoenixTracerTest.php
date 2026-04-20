<?php

namespace Tests\Unit;

use App\Services\Telemetry\PhoenixTracer;
use PHPUnit\Framework\TestCase;

class PhoenixTracerTest extends TestCase
{
    public function test_disabled_tracer_returns_null_spans_without_touching_the_network(): void
    {
        $tracer = PhoenixTracer::fromConfig(['enabled' => false]);

        $this->assertFalse($tracer->isEnabled());
        $this->assertNull($tracer->startSpan('noop', PhoenixTracer::KIND_AGENT, ['foo' => 'bar']));
    }

    public function test_missing_endpoint_counts_as_disabled_even_if_enabled_flag_is_set(): void
    {
        $tracer = PhoenixTracer::fromConfig([
            'enabled' => true,
            'endpoint' => '',
            'api_key' => 'k',
        ]);

        $this->assertFalse($tracer->isEnabled());
    }

    public function test_redact_messages_flag_is_exposed(): void
    {
        $tracer = PhoenixTracer::fromConfig([
            'enabled' => true,
            'endpoint' => 'https://phoenix.example.com',
            'api_key' => 'k',
            'redact_messages' => true,
        ]);

        $this->assertTrue($tracer->shouldRedactMessages());
    }

    public function test_default_project_name_is_hao_code(): void
    {
        $tracer = PhoenixTracer::fromConfig(['enabled' => true, 'endpoint' => 'https://p', 'api_key' => 'k']);

        $this->assertSame('hao-code', $tracer->getProjectName());
    }

    public function test_shutdown_is_safe_when_disabled(): void
    {
        $tracer = PhoenixTracer::fromConfig(['enabled' => false]);
        $tracer->shutdown();

        $this->addToAssertionCount(1);
    }

    public function test_record_exception_on_null_span_is_noop(): void
    {
        $tracer = PhoenixTracer::fromConfig(['enabled' => false]);
        $tracer->recordException(null, new \RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }
}
