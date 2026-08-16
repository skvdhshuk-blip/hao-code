<?php

declare(strict_types=1);

namespace HaoCode\Services\Security;

/** Single policy for content leaving the process through telemetry or event export. @internal */
final class SensitiveDataRedactor
{
    public const MASK = '[redacted]';

    private const TELEMETRY_CONTENT_KEYS = [
        '/^llm\.system$/',
        '/^llm\.input_messages\.\d+\.message\.content$/',
        '/^output\.value$/',
        '/^input\.value$/',
        '/^llm\.output_messages\.\d+\.message\.tool_calls\.\d+\.tool_call\.function\.arguments$/',
    ];

    /** Scalar payload fields that are structural rather than user/model/tool content. */
    private const SAFE_RUN_EVENT_SCALARS = [
        'action_count',
        'attempt_id',
        'decision_count',
        'error_class',
        'idempotency_key',
        'input_hash',
        'interrupt_id',
        'message_count',
        'model',
        'provider',
        'read_only',
        'source_agent_id',
        'stop_reason',
        'tool_count',
        'tool_name',
        'tool_use_id',
        'turns',
    ];

    private const SAFE_USAGE_FIELDS = [
        'cache_creation_tokens',
        'cache_read_tokens',
        'input_tokens',
        'output_tokens',
    ];

    public function isTelemetryContentKey(string $key): bool
    {
        foreach (self::TELEMETRY_CONTENT_KEYS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function redactRunEvent(array $event): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $event['payload'] = [];
        foreach ($payload as $key => $value) {
            $event['payload'][$key] = $this->redactRunEventField((string) $key, $value);
        }

        return $event;
    }

    private function redactRunEventField(string $key, mixed $value): mixed
    {
        if (in_array($key, self::SAFE_RUN_EVENT_SCALARS, true)) {
            return is_scalar($value) || $value === null ? $value : self::MASK;
        }
        if ($key === 'decision_kinds') {
            return is_array($value) && array_is_list($value)
                && array_reduce($value, static fn (bool $safe, mixed $item): bool => $safe && is_string($item), true)
                    ? $value
                    : self::MASK;
        }
        if ($key === 'usage' && is_array($value)) {
            $usage = [];
            foreach ($value as $usageKey => $usageValue) {
                $usage[$usageKey] = in_array($usageKey, self::SAFE_USAGE_FIELDS, true) && is_int($usageValue)
                    ? $usageValue
                    : self::MASK;
            }

            return $usage;
        }
        if ($key === 'reason' && $value === 'lease_expired_after_start') {
            return $value;
        }

        return self::MASK;
    }
}
