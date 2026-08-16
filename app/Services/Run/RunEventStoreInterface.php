<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface RunEventStoreInterface
{
    public function append(RunEvent $event): RunEvent;

    /** @return iterable<RunEvent> */
    public function read(string $runId, int $afterSequence = 0): iterable;
}
