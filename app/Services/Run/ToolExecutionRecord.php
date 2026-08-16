<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class ToolExecutionRecord
{
    /** @param array<string, mixed>|null $result */
    public function __construct(
        public readonly ToolExecutionRequest $request,
        public readonly ToolExecutionState $state,
        public readonly ?string $ownerId,
        public readonly int $leaseExpiresAtMs,
        public readonly int $fencingToken,
        public readonly ?array $result,
        public readonly int $updatedAtMs,
    ) {}

    public function belongsTo(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }

    public function leaseIsActive(int $nowMs): bool
    {
        return $this->leaseExpiresAtMs > $nowMs;
    }
}
