<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Sdk\Sandbox\SandboxRuntime;

/** @internal */
final class SdkRun
{
    private bool $closed = false;

    public function __construct(
        public readonly AgentLoop $loop,
        private readonly ?SandboxRuntime $sandboxRuntime = null,
        private readonly ?McpConnectionManager $mcpConnectionManager = null,
    ) {}

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->sandboxRuntime?->close();
        } finally {
            try {
                $this->mcpConnectionManager?->disconnectAll();
            } finally {
                $this->closed = true;
            }
        }
    }
}
