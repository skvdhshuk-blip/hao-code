<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
final class EngineRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
    ) {}
}
