<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class ToolExecutionClaim
{
    public function __construct(
        public readonly ToolExecutionRecord $record,
        public readonly bool $execute,
        public readonly ?RunEvent $event = null,
    ) {}
}
