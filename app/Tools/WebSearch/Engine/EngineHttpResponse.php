<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
final class EngineHttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $effectiveUrl,
        public readonly array $headers,
        public readonly string $body,
    ) {}
}
