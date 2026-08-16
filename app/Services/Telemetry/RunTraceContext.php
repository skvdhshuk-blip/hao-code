<?php

declare(strict_types=1);

namespace HaoCode\Services\Telemetry;

use HaoCode\Services\Run\RunJournal;
use OpenTelemetry\API\Trace\SpanInterface;

/** Maps canonical RunJournal identities onto telemetry spans. @internal */
final class RunTraceContext
{
    /** @return array<string, string> */
    public static function attributes(?RunJournal $journal, ?string $eventId = null): array
    {
        if ($journal === null) {
            return [];
        }

        $attributes = ['haocode.run_id' => $journal->runId()];
        $invocationId = $journal->invocationId();
        if ($invocationId !== null && $invocationId !== '') {
            $attributes['haocode.invocation_id'] = $invocationId;
        }
        if ($eventId !== null && $eventId !== '') {
            $attributes['haocode.event_id'] = $eventId;
        }

        return $attributes;
    }

    public static function annotate(
        ?PhoenixTracer $tracer,
        ?SpanInterface $span,
        ?RunJournal $journal,
        ?string $eventId = null,
    ): void {
        foreach (self::attributes($journal, $eventId) as $key => $value) {
            $tracer?->setAttribute($span, $key, $value);
        }
    }
}
