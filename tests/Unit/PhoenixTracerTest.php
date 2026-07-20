<?php

namespace Tests\Unit;

use HaoCode\Services\Telemetry\PhoenixTracer;
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

    // ─── centralized redaction (chatgpt P2 telemetry gap) ───────────────

    /**
     * @dataProvider redactableKeyProvider
     */
    public function test_is_redactable_key_masks_content_bearing_keys(string $key, bool $expected): void
    {
        $tracer = PhoenixTracer::fromConfig(['enabled' => false]);

        $this->assertSame($expected, $tracer->isRedactableKey($key), "key: {$key}");
    }

    public function test_set_attribute_is_noop_on_null_span(): void
    {
        // Disabled tracer returns null spans from startSpan; callers must be
        // able to chain $tracer->setAttribute($span, ...) without null guards.
        $tracer = PhoenixTracer::fromConfig(['enabled' => false]);

        // Should not throw on a null span.
        $tracer->setAttribute(null, 'output.value', 'sensitive');
        $tracer->setAttribute(null, 'llm.model_name', 'claude-test');

        $this->addToAssertionCount(1);
    }

    // End-to-end redaction on a live span cannot be exercised here without an
    // enabled tracer (which would attempt a real OTLP export). The
    // isRedactableKey data provider above locks the key contract; the null-span
    // no-op test guards the wrapper. The caller audit (no remaining direct
    // ->setAttribute in app/Services outside PhoenixTracer itself) is the
    // safety net that the centralized path is actually used.

    public static function redactableKeyProvider(): array
    {
        return [
            // Canonical OpenInference keys
            'llm.system'                      => ['llm.system', true],
            'output.value'                    => ['output.value', true],
            'input.value'                     => ['input.value', true],
            'llm.input_messages.0.content'    => ['llm.input_messages.0.message.content', true],
            'llm.input_messages.42.content'   => ['llm.input_messages.42.message.content', true],
            'tool_call args index 0'         => ['llm.output_messages.0.message.tool_calls.0.tool_call.function.arguments', true],
            'tool_call args index 9'         => ['llm.output_messages.9.message.tool_calls.3.tool_call.function.arguments', true],

            // Metadata keys that must NOT be redacted
            'llm.model_name'                  => ['llm.model_name', false],
            'llm.token_count.total'           => ['llm.token_count.total', false],
            'tool.name'                       => ['tool.name', false],
            'tool.call_id'                    => ['tool.call_id', false],
            'tool.is_error'                   => ['tool.is_error', false],
            'llm.tools.names'                 => ['llm.tools.names', false],
            'llm.output_tool_calls_count'     => ['llm.output_tool_calls_count', false],
            'llm.input_messages_count'        => ['llm.input_messages_count', false],
            'tool_call name (not args)'      => ['llm.output_messages.0.message.tool_calls.0.tool_call.function.name', false],

            // Paths that look similar but are not on the redaction list
            'llm.output_messages.0.role'      => ['llm.output_messages.0.message.role', false],
            'input.mime_type'                 => ['input.mime_type', false],
        ];
    }
}
