<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class ToolExecutionAttempt
{
    /** @param array<string, mixed>|null $cachedResult */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $ownerId,
        public readonly int $fencingToken,
        public readonly bool $execute,
        public readonly bool $readOnly,
        public readonly ?array $cachedResult = null,
    ) {}
}
