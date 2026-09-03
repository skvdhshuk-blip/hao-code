<?php

declare(strict_types=1);

namespace Tests\Support;

use HaoCode\Tools\WebSearch\Engine\EngineHttpResponse;
use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\EngineParseResult;
use HaoCode\Tools\WebSearch\Engine\EngineRequest;

final class FakeWebSearchEngine implements EngineInterface
{
    private readonly ?\Closure $parser;

    public function __construct(
        private readonly string $engineId,
        private readonly int $priority = 100,
        private readonly float $engineWeight = 1.0,
        private readonly int $engineTimeoutMs = 5000,
        private readonly ?string $warmup = null,
        private readonly ?string $requestUrl = null,
        ?callable $parser = null,
    ) {
        $this->parser = $parser === null ? null : $parser(...);
    }

    public function id(): string
    {
        return $this->engineId;
    }

    public function weight(): float
    {
        return $this->engineWeight;
    }

    public function qualityPriority(): int
    {
        return $this->priority;
    }

    public function timeoutMs(): int
    {
        return $this->engineTimeoutMs;
    }

    public function warmupUrl(): ?string
    {
        return $this->warmup;
    }

    public function createRequest(string $query): EngineRequest
    {
        return new EngineRequest(
            $this->requestUrl ?? 'https://'.$this->engineId.'.example/search?q='.rawurlencode($query),
        );
    }

    public function parse(EngineHttpResponse $response): EngineParseResult
    {
        if ($this->parser !== null) {
            return ($this->parser)($response);
        }

        return $response->body === 'empty'
            ? EngineParseResult::empty()
            : EngineParseResult::error();
    }
}
