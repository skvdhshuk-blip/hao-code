<?php

namespace HaoCode\Services\Api;

use JsonException;

class StreamEvent
{
    public function __construct(
        public readonly string $type,
        public readonly ?array $data = null,
    ) {}

    public static function fromSse(string $eventType, string $rawData): self
    {
        return new self($eventType, self::decodeSseData($rawData, 'provider'));
    }

    /**
     * Decode one SSE JSON payload and reject malformed provider data.
     *
     * @return array<string, mixed>
     */
    public static function decodeSseData(string $rawData, string $provider): array
    {
        if (! str_starts_with(ltrim($rawData), '{')) {
            throw new ApiErrorException(
                "Malformed {$provider} SSE JSON payload: expected a JSON object.",
                'protocol_error',
            );
        }

        try {
            $decoded = json_decode($rawData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiErrorException(
                "Malformed {$provider} SSE JSON payload: {$exception->getMessage()}",
                'protocol_error',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new ApiErrorException(
                "Malformed {$provider} SSE JSON payload: expected a JSON object.",
                'protocol_error',
            );
        }

        return $decoded;
    }

    /**
     * 判断该事件是否已经提交模型可见内容或可能触发工具副作用。
     */
    public function commitsResponseState(): bool
    {
        return ! in_array($this->type, ['message_start', 'ping'], true);
    }
}
