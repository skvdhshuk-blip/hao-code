<?php

namespace HaoCode\Sdk\Sandbox;

/** @api */
final class SandboxRuntime
{
    public function __construct(
        public readonly SandboxConfig $config,
        public readonly SandboxBackendInterface $backend,
    ) {}

    /** @internal */
    public function tools(): array
    {
        $tools = [
            new Tools\SandboxReadTool($this),
            new Tools\SandboxWriteTool($this),
            new Tools\SandboxGlobTool($this),
            new Tools\SandboxGrepTool($this),
        ];

        if ($this->config->enablesBash()) {
            $tools[] = new Tools\SandboxBashTool($this);
        }

        return $tools;
    }

    /** @internal */
    public function close(): void
    {
        $this->backend->close();
    }
}
