<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunLease
{
    public function __construct(
        public readonly string $runId,
        public readonly string $ownerId,
        public readonly int $leaseExpiresAtMs,
        public readonly int $fencingToken,
        public readonly bool $acquired,
    ) {}
}
