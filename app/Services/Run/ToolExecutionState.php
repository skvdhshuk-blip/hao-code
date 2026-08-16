<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
enum ToolExecutionState: string
{
    case Claimed = 'claimed';
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Unknown,
        ], true);
    }
}
