<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface RunLeaseStoreInterface
{
    public function claimRun(string $runId, string $ownerId, int $leaseDurationMs, int $nowMs): RunLease;

    public function renewRun(string $runId, string $ownerId, int $fencingToken, int $leaseDurationMs, int $nowMs): RunLease;

    public function releaseRun(string $runId, string $ownerId, int $fencingToken): void;
}
