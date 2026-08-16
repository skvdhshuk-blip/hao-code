<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
