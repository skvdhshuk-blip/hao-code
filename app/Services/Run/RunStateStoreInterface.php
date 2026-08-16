<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface RunStateStoreInterface extends RunEventStoreInterface, RunCheckpointStoreInterface
{
}
