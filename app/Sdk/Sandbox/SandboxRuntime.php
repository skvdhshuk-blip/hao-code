<?php

namespace HaoCode\Sdk\Sandbox;

/** @api */
final class SandboxRuntime
{
    /** Sandbox replacement tool names that must not be overridden by custom tools. */
    public const RESERVED_TOOL_NAMES = ['Read', 'Write', 'Glob', 'Grep', 'Bash'];

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
        }
    }

    /**
     * @return array<string, mixed>|null
     * @internal
     */
    public function exportLease(): ?array
    {
        if (method_exists($this->backend, 'exportLease')) {
            $lease = $this->backend->exportLease();

            return is_array($lease) ? self::redactLeaseSecrets($lease) : null;
        }

        return null;
    }

    /**
     * Rebuild a SandboxConfig that reattaches identity from a lease while
     * taking security policy from the caller's current config (only as strict
     * or stricter where we can compare).
     *
     * @param  array<string, mixed>  $lease
     * @internal
     */
    public static function configFromLease(array $lease, ?SandboxConfig $caller = null): SandboxConfig
    {
        $provider = is_string($lease['provider'] ?? null)
            ? $lease['provider']
            : ($caller?->provider ?? 'local');

        if ($caller !== null && $caller->provider !== $provider) {
            throw new \InvalidArgumentException(
                "Sandbox lease provider '{$provider}' does not match resume config provider '{$caller->provider}'.",
            );
        }

        $identity = is_array($lease['identity'] ?? null) ? $lease['identity'] : [];
        $root = is_string($identity['root'] ?? null) && $identity['root'] !== ''
            ? $identity['root']
            : (is_string($lease['root'] ?? null) && $lease['root'] !== '' ? $lease['root'] : $caller?->root);
        $ownsRoot = (bool) ($identity['owns_root'] ?? $lease['owns_root'] ?? false);

        // Policy: prefer caller's current mode/network when stricter; otherwise lease snapshot.
        $mode = $caller?->mode ?? (is_string($lease['mode'] ?? null) ? $lease['mode'] : 'filesystem');
        if ($caller !== null && $caller->mode === 'filesystem' && ($lease['mode'] ?? null) === 'full') {
            // Caller tightened from full → filesystem.
            $mode = 'filesystem';
        }
        $remoteCwd = is_string($identity['remote_cwd'] ?? null) && $identity['remote_cwd'] !== ''
            ? $identity['remote_cwd']
            : (is_string($lease['remote_cwd'] ?? null) ? $lease['remote_cwd'] : ($caller?->remoteCwd ?? '/workspace'));
        $sync = $caller?->sync ?? (is_string($lease['sync'] ?? null) ? $lease['sync'] : 'none');
        $cleanup = $caller?->cleanup ?? (is_string($lease['cleanup'] ?? null) ? $lease['cleanup'] : 'never');
        $exclude = $caller?->exclude ?? (is_array($lease['exclude'] ?? null) ? $lease['exclude'] : []);

        $leaseOptions = is_array($lease['options'] ?? null) ? $lease['options'] : [];
        $callerOptions = $caller?->options ?? [];
        $options = array_merge($leaseOptions, $callerOptions);

        // Identity fields win over caller for reattach.
        if ($provider === 'agentrun') {
            $sandboxId = $identity['sandbox_id']
                ?? $leaseOptions['sandboxId']
                ?? null;
            if (! is_string($sandboxId) || $sandboxId === '') {
                throw new \RuntimeException(
                    'AgentRun durable HITL lease is missing resolved sandbox_id; cannot reattach a template-created sandbox.',
                );
            }
            $options['sandboxId'] = $sandboxId;
            // Never rehydrate secrets from a lease; caller/env supply credentials.
            unset($options['apiKey'], $options['authorization'], $options['token']);
            // Drop templateName so resume does not mint a second sandbox.
            unset($options['templateName']);
        }

        if ($provider === 'tokimo') {
            $vmDir = $identity['vm_dir'] ?? $leaseOptions['vmDir'] ?? null;
            if (is_string($vmDir) && $vmDir !== '') {
                $options['vmDir'] = $vmDir;
            }
        }

        // Network policy: caller can only tighten allow-all → blocked.
        $leaseNetwork = is_string($leaseOptions['network'] ?? null) ? $leaseOptions['network'] : null;
        $callerNetwork = is_string($callerOptions['network'] ?? null) ? $callerOptions['network'] : null;
        if ($callerNetwork === 'blocked' || $leaseNetwork === 'blocked') {
            $options['network'] = 'blocked';
        }

        if ($ownsRoot) {
            $options['owns_root'] = true;
        }

        // Refuse recursive delete of arbitrary paths: only haocode temp roots may be owned.
        if ($ownsRoot && is_string($root) && $root !== '') {
            $tmp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            $base = rtrim(str_replace('\\', '/', $tmp), '/').'/';
            $resolvedRoot = realpath($root) ?: $root;
            $normalized = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
            // macOS often has /var/folders vs /private/var/folders — compare basenames too.
            $underTmp = str_starts_with($normalized.'/', $base)
                || str_starts_with($normalized.'/', '/private'.$base)
                || str_starts_with(str_replace('/private', '', $normalized).'/', str_replace('/private', '', $base));
            if (! $underTmp || ! str_contains($normalized, 'haocode-')) {
                $options['owns_root'] = false;
                $ownsRoot = false;
            }
        }

        return new SandboxConfig(
            provider: $provider,
            mode: $mode,
            remoteCwd: $remoteCwd,
            sync: $sync,
            cleanup: $cleanup,
            root: $root,
            exclude: $exclude,
            options: $options,
        );
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    private static function redactLeaseSecrets(array $lease): array
    {
        $secretKeys = ['apiKey', 'api_key', 'authorization', 'token', 'password', 'secret', 'cookie'];
        if (isset($lease['options']) && is_array($lease['options'])) {
            foreach ($secretKeys as $key) {
                unset($lease['options'][$key]);
            }
        }

        return $lease;
    }
}
