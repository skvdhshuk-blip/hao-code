<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface RunCheckpointStoreInterface
{
    public function commitCheckpoint(RunCheckpoint $checkpoint): void;

    public function latestCheckpoint(string $runId): ?RunCheckpoint;
}
