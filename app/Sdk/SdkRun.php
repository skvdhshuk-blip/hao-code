<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Sdk\Internal\EffectiveCapabilityManifest;
use HaoCode\Sdk\Internal\RunCapabilityGuard;
use HaoCode\Sdk\Sandbox\SandboxRuntime;

/** @internal */
final class SdkRun
{
    private bool $closed = false;

    private bool $preserveSandbox = false;

    public function __construct(
        public readonly AgentLoop $loop,
        private readonly ?SandboxRuntime $sandboxRuntime = null,
        private readonly ?McpConnectionManager $mcpConnectionManager = null,
        private ?\Closure $unsubscribeAbort = null,
        private readonly ?RunCapabilityGuard $capabilityGuard = null,
    ) {
        $this->loop->attachSandboxRuntime($this->sandboxRuntime);
    }

    /**
     * On durable HITL interrupt, keep the sandbox filesystem/session for resume.
     */
    public function preserveSandboxOnClose(): void
    {
        $this->preserveSandbox = true;
    }

    /**
     * Return the durable sandbox identity for a Conversation that will rebuild
     * itself after a facade-based interrupt resume.
     *
     * @return array<string, mixed>|null
     * @internal
     */
    public function getSandboxLease(): ?array
    {
        return $this->sandboxRuntime?->exportLease();
    }

    /** @internal */
    public function getCapabilityManifest(): ?EffectiveCapabilityManifest
    {
        $settings = $this->loop->getRunContext()?->settings;

        return $this->capabilityGuard !== null && $settings !== null
            ? $this->capabilityGuard->manifest($settings)
            : null;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $unsubscribe = $this->unsubscribeAbort;
            $this->unsubscribeAbort = null;
            $unsubscribe?->__invoke();
        } finally {
            try {
                $this->loop->setAbortRequestedChecker(null);
            } finally {
                try {
                    if ($this->preserveSandbox) {
                        $this->sandboxRuntime?->detach();
                    } else {
                        $this->sandboxRuntime?->close();
                    }
                } finally {
                    try {
                        $this->mcpConnectionManager?->disconnectAll();
                    } finally {
                        $this->closed = true;
                    }
                }
            }
        }
    }
}
