<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
interface EngineInterface
{
    public function id(): string;

    public function weight(): float;

    public function qualityPriority(): int;

    public function timeoutMs(): int;

    public function warmupUrl(): ?string;

    public function createRequest(string $query): EngineRequest;

    public function parse(EngineHttpResponse $response): EngineParseResult;
}
