<?php

declare(strict_types=1);

namespace HaoCode\Services\Api\Capability;

/** @internal */
enum CapabilityStatus: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
