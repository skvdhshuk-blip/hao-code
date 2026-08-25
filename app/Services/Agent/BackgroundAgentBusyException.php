<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** @internal */
final class BackgroundAgentBusyException extends \RuntimeException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $current,
        public readonly int $limit,
    ) {
        parent::__construct(
            "Background resource '{$resource}' is busy: {$current} exceeds limit {$limit}.",
        );
    }

    /** @return array{code: string, resource: string, current: int, limit: int} */
    public function metadata(): array
    {
        return [
            'code' => 'background_busy',
            'resource' => $this->resource,
            'current' => $this->current,
            'limit' => $this->limit,
        ];
    }
}
