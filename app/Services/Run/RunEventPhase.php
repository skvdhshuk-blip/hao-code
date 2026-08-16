<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
enum RunEventPhase: string
{
    case Run = 'run';
    case Model = 'model';
    case Tool = 'tool';
    case Human = 'human';
    case Checkpoint = 'checkpoint';
}
