<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Sdk\Sandbox\SandboxRuntime;

/** @internal */
final class SdkRun
{
    private bool $closed = false;

    public function __construct(
        public readonly AgentLoop $loop,
        private readonly ?SandboxRuntime $sandboxRuntime = null,
    ) {}

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->sandboxRuntime?->close();
        $this->closed = true;
    }
}
