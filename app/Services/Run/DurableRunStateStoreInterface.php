<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
interface DurableRunStateStoreInterface extends
    RunStateStoreInterface,
    ToolExecutionStoreInterface,
    RunLeaseStoreInterface
{
}
