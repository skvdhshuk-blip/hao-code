<?php

namespace App\Services\Telemetry;

use App\Services\Settings\SettingsManager;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * Arize Phoenix OTLP tracer.
 *
 * Wraps the OpenTelemetry PHP SDK so the rest of the codebase can emit
 * spans with a tiny, opinionated API without having to know about tracer
 * providers, exporters, or OTLP transport factories. All spans are tagged
 * with OpenInference semantic-convention attributes so Phoenix renders
 * them as proper agent / LLM / tool traces out of the box.
 *
 * The tracer is inert unless telemetry is explicitly enabled in settings —
 * {@see startSpan()} returns null, and instrumentation calls become cheap
 * no-ops. This means adding tracer.* calls to hot paths is free for users
 * who have not opted in.
 *
 * Settings layout (project or global settings.json):
 *
 *   {
 *     "telemetry": {
 *       "phoenix": {
 *         "enabled": true,
 *         "endpoint": "https://phoenix.example.com",
 *         "api_key": "...",
 *         "project_name": "hao-code",
 *         "redact_messages": false
 *       }
 *     }
 *   }
 *
 * Environment variable overrides (take precedence over settings.json):
 *   HAOCODE_PHOENIX_ENABLED, HAOCODE_PHOENIX_ENDPOINT,
 *   HAOCODE_PHOENIX_API_KEY, HAOCODE_PHOENIX_PROJECT,
 *   HAOCODE_PHOENIX_REDACT.
 */
class PhoenixTracer
{
    /**
     * OpenInference span kinds. Values must match what Phoenix expects — the
     * UI filters and renderers key off these strings exactly.
     */
    public const KIND_AGENT = 'AGENT';
    public const KIND_LLM = 'LLM';
    public const KIND_TOOL = 'TOOL';
    public const KIND_CHAIN = 'CHAIN';

    private ?TracerProvider $provider = null;
    private ?TracerInterface $tracer = null;
    private bool $initFailed = false;

    private function __construct(
        private readonly bool $enabled,
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $projectName,
        private readonly bool $redactMessages,
    ) {}

    /**
     * Build a tracer from resolved settings + env vars. Never throws — if
     * configuration is missing or invalid, returns a disabled tracer.
     */
    public static function fromSettings(SettingsManager $settings): self
    {
        $config = $settings->getTelemetryConfig();

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            endpoint: (string) ($config['endpoint'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? ''),
            projectName: (string) ($config['project_name'] ?? 'hao-code'),
            redactMessages: (bool) ($config['redact_messages'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $config Already-resolved telemetry config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            endpoint: (string) ($config['endpoint'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? ''),
            projectName: (string) ($config['project_name'] ?? 'hao-code'),
            redactMessages: (bool) ($config['redact_messages'] ?? false),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->endpoint !== '' && ! $this->initFailed;
    }

    public function getProjectName(): string
    {
        return $this->projectName;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function shouldRedactMessages(): bool
    {
        return $this->redactMessages;
    }

    /**
     * Begin a span. Returns null when telemetry is disabled so callers can
     * do `?->setAttribute(...)` / `?->end()` without guards.
     *
     * OpenInference attribute semantics:
     *   - openinference.span.kind: one of AGENT / LLM / TOOL / CHAIN
     *   - input.value / output.value: canonical I/O text (JSON-encoded if structured)
     *   - llm.model_name, llm.provider, llm.token_count.*: LLM metadata
     *   - tool.name, tool.parameters, tool.description: tool metadata
     *
     * @param array<string, scalar|array|null> $attributes
     */
    public function startSpan(string $name, string $openInferenceKind = self::KIND_CHAIN, array $attributes = []): ?SpanInterface
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $tracer = $this->tracer();
        if ($tracer === null) {
            return null;
        }

        $builder = $tracer->spanBuilder($name);
        $span = $builder->startSpan();
        $span->setAttribute('openinference.span.kind', $openInferenceKind);

        foreach ($attributes as $key => $value) {
            $normalized = $this->normalizeAttributeValue($value);
            if ($normalized === null) {
                continue;
            }
            $span->setAttribute($key, $normalized);
        }

        return $span;
    }

    /**
     * Mark a span as an error and end it safely. Use in catch blocks:
     *
     *   } catch (Throwable $e) {
     *       $this->tracer->recordException($span, $e);
     *       throw $e;
     *   }
     */
    public function recordException(?SpanInterface $span, Throwable $error): void
    {
        if ($span === null) {
            return;
        }

        $span->recordException($error, [
            'exception.type' => $error::class,
            'exception.message' => $error->getMessage(),
        ]);
        $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
    }

    /**
     * Flush pending spans and shut the provider down. Safe to call when
     * disabled (no-op).
     */
    public function shutdown(): void
    {
        if ($this->provider === null) {
            return;
        }

        try {
            $this->provider->shutdown();
        } catch (Throwable) {
            // Don't let telemetry flush failures propagate to the user.
        }
    }

    private function tracer(): ?TracerInterface
    {
        if ($this->tracer !== null) {
            return $this->tracer;
        }

        if ($this->initFailed) {
            return null;
        }

        try {
            // OTEL's DebugScope wrapper tracks scope detach ordering and
            // emits E_USER_NOTICE on violations, which Laravel's exception
            // handler escalates to a fatal error. Disable the wrapper.
            if (! getenv('OTEL_PHP_DEBUG_SCOPES_DISABLED')) {
                putenv('OTEL_PHP_DEBUG_SCOPES_DISABLED=true');
            }

            // Symfony's streaming HTTP client uses fibers internally. The
            // default FiberBoundContextStorage triggers E_USER_WARNING on
            // any context access from a fiber that wasn't forked with an
            // attached OTEL context — which happens unavoidably inside the
            // stream pump, even when our actual span lifecycle is in the
            // main coroutine. Swap in the non-fiber-aware ContextStorage
            // so telemetry never interferes with the streaming pipeline.
            Context::setStorage(new ContextStorage());

            $this->provider = $this->buildProvider();
            $this->tracer = $this->provider->getTracer('hao-code', env('HAO_CODE_VERSION', 'dev'));

            // Ensure queued spans get exported when the PHP process ends,
            // even on the `$result = HaoCode::query(...); echo $result;` SDK
            // path that never explicitly shuts the tracer down.
            register_shutdown_function(function (): void {
                $this->shutdown();
            });
        } catch (Throwable) {
            // Telemetry must never break the app. Mark init as failed so we
            // don't retry on every span.
            $this->initFailed = true;
            $this->tracer = null;
            $this->provider = null;

            return null;
        }

        return $this->tracer;
    }

    private function buildProvider(): TracerProvider
    {
        $endpoint = rtrim($this->endpoint, '/') . '/v1/traces';
        $headers = ['authorization' => 'Bearer ' . $this->apiKey];

        // Phoenix 13.x only accepts protobuf on /v1/traces, not JSON.
        $transport = (new OtlpHttpTransportFactory())->create(
            endpoint: $endpoint,
            contentType: 'application/x-protobuf',
            headers: $headers,
        );

        $exporter = new SpanExporter($transport);
        $processor = new BatchSpanProcessor($exporter, \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault());

        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => 'hao-code',
            'service.version' => env('HAO_CODE_VERSION', 'dev'),
            // Phoenix buckets spans by this resource attribute.
            'openinference.project.name' => $this->projectName,
        ])));

        return TracerProvider::builder()
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->build();
    }

    private function normalizeAttributeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            // OTEL allows scalar arrays only. Anything structured → JSON string.
            $isScalarArray = array_is_list($value) && array_reduce(
                $value,
                static fn (bool $carry, mixed $item): bool => $carry && is_scalar($item),
                true,
            );
            if ($isScalarArray) {
                return $value;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? null : $encoded;
        }

        return null;
    }
}
