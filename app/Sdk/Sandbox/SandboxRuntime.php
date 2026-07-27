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

    /**
     * Leave the sandbox root/session intact for durable HITL resume.
     *
     * @internal
     */
    public function detach(): void
    {
        if (method_exists($this->backend, 'detach')) {
            $this->backend->detach();

            return;
        }
        // Backends without detach still skip close-side cleanup when callers
        // choose preserve-on-close; remote providers keep their server identity.
    }

    /**
     * @return array<string, mixed>|null
     * @internal
     */
    public function exportLease(): ?array
    {
        if (method_exists($this->backend, 'exportLease')) {
            $lease = $this->backend->exportLease();

            return is_array($lease) ? $lease : null;
        }

        // AgentRun and similar remote backends: identity lives in config options.
        if ($this->config->provider === 'agentrun') {
            return [
                'provider' => 'agentrun',
                'mode' => $this->config->mode,
                'remote_cwd' => $this->config->remoteCwd,
                'sync' => $this->config->sync,
                'cleanup' => $this->config->cleanup,
                'root' => $this->config->root,
                'owns_root' => false,
                'exclude' => $this->config->exclude,
                'options' => $this->config->options,
            ];
        }

        return null;
    }

    /**
     * Rebuild a SandboxConfig that reattaches to a previously detached lease.
     *
     * @param  array<string, mixed>  $lease
     * @internal
     */
    public static function configFromLease(array $lease, ?SandboxConfig $fallback = null): SandboxConfig
    {
        $provider = is_string($lease['provider'] ?? null) ? $lease['provider'] : ($fallback?->provider ?? 'local');
        $options = is_array($lease['options'] ?? null) ? $lease['options'] : ($fallback?->options ?? []);
        $ownsRoot = (bool) ($lease['owns_root'] ?? false);
        if ($ownsRoot) {
            $options['owns_root'] = true;
        }

        return new SandboxConfig(
            provider: $provider,
            mode: is_string($lease['mode'] ?? null) ? $lease['mode'] : ($fallback?->mode ?? 'filesystem'),
            remoteCwd: is_string($lease['remote_cwd'] ?? null) ? $lease['remote_cwd'] : ($fallback?->remoteCwd ?? '/workspace'),
            sync: is_string($lease['sync'] ?? null) ? $lease['sync'] : ($fallback?->sync ?? 'none'),
            cleanup: is_string($lease['cleanup'] ?? null) ? $lease['cleanup'] : ($fallback?->cleanup ?? 'never'),
            root: is_string($lease['root'] ?? null) && $lease['root'] !== ''
                ? $lease['root']
                : $fallback?->root,
            exclude: is_array($lease['exclude'] ?? null) ? $lease['exclude'] : ($fallback?->exclude ?? []),
            options: $options,
        );
    }
}
