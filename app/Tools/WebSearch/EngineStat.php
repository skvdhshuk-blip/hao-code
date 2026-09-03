<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

/** @internal */
final class EngineStat
{
    public const TRANSPORT_ERROR = 'transport_error';
    public const HTTP_ERROR = 'http_error';

    public function __construct(
        public readonly string $engine,
        public readonly string $status,
        public readonly int $count,
        public readonly int $elapsedMs,
        public readonly ?int $httpStatus,
        public readonly ?string $error,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'status' => $this->status,
            'count' => $this->count,
            'elapsed_ms' => $this->elapsedMs,
            'http_status' => $this->httpStatus,
            'error' => $this->error,
        ];
    }

    public function failed(): bool
    {
        return in_array($this->status, [self::TRANSPORT_ERROR, self::HTTP_ERROR, 'parse_error'], true);
    }
}
