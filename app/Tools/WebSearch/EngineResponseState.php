<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class EngineResponseState
{
    public string $body = '';
    public ?int $httpStatus = null;
    /** @var array<string, list<string>> */
    public array $headers = [];
    public string $effectiveUrl;
    public bool $settled = false;

    public function __construct(
        public readonly EngineInterface $engine,
        public readonly ResponseInterface $response,
        public readonly float $startedAt,
        public readonly float $deadline,
        string $requestUrl,
    ) {
        $this->effectiveUrl = $requestUrl;
    }
}
