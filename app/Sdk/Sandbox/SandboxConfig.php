<?php

namespace HaoCode\Sdk\Sandbox;

/**
 * Sandbox runtime configuration for SDK agents.
 *
 * @api
 */
final class SandboxConfig
{
    public function __construct(
        /** @api */ public readonly string $provider = 'local',
        /** @api */ public readonly string $mode = 'filesystem',
        /** @api */ public readonly string $remoteCwd = '/workspace',
        /** @api */ public readonly string $sync = 'none',
        /** @api */ public readonly string $cleanup = 'never',
        /** @api */ public readonly ?string $root = null,
        /** @api */ public readonly array $exclude = [],
        /** @api */ public readonly array $options = [],
    ) {}

    /** @api */
    public static function local(
        string $mode = 'filesystem',
        string $sync = 'none',
        string $remoteCwd = '/workspace',
        string $cleanup = 'never',
        ?string $root = null,
        array $exclude = [],
    ): self {
        return new self(
            provider: 'local',
            mode: $mode,
            remoteCwd: $remoteCwd,
            sync: $sync,
            cleanup: $cleanup,
            root: $root,
            exclude: $exclude,
        );
    }

    /**
     * Create an operating-system isolated local sandbox.
     *
     * macOS uses Seatbelt (`sandbox-exec`) and Linux uses bubblewrap. Unlike
     * `local()`, this configuration fails when the requested isolation engine
     * is unavailable instead of running the command directly on the host.
     *
     * @api
     */
    public static function native(
        string $mode = 'full',
        string $sync = 'none',
        string $remoteCwd = '/workspace',
        string $cleanup = 'always',
        ?string $root = null,
        array $exclude = [],
        string $network = 'blocked',
        string $engine = 'auto',
    ): self {
        if (! in_array($network, ['blocked', 'allow-all'], true)) {
            throw new \InvalidArgumentException("Unsupported native sandbox network policy: {$network}");
        }
        if ($root !== null && self::isFilesystemRoot($root)) {
            throw new \InvalidArgumentException('The native sandbox root cannot be the filesystem root.');
        }

        return new self(
            provider: 'native',
            mode: $mode,
            remoteCwd: $remoteCwd,
            sync: $sync,
            cleanup: $cleanup,
            root: $root,
            exclude: $exclude,
            options: [
                'network' => $network,
                'engine' => $engine,
            ],
        );
    }

    /**
     * Create a Tokimo-backed cross-platform sandbox.
     *
     * The optional host runner is selected from the verified user cache by
     * operating system and CPU architecture. The guest image artifacts also
     * live outside the Composer package and are supplied through $baseRootfs.
     *
     * @api
     */
    public static function tokimo(
        string $baseRootfs,
        string $mode = 'full',
        string $sync = 'upload-cwd',
        string $remoteCwd = '/workspace',
        string $cleanup = 'always',
        ?string $root = null,
        array $exclude = [],
        ?string $binary = null,
        ?string $vmDir = null,
        int $memoryMb = 4096,
        int $cpuCount = 4,
        string $network = 'blocked',
        int $startupTimeoutSeconds = 30,
    ): self {
        if (! in_array($network, ['blocked', 'allow-all'], true)) {
            throw new \InvalidArgumentException("Unsupported Tokimo sandbox network policy: {$network}");
        }
        if ($memoryMb !== 0 && $memoryMb < 256) {
            throw new \InvalidArgumentException('Tokimo sandbox memoryMb must be 0 or at least 256.');
        }
        if ($cpuCount < 0 || $cpuCount > 64) {
            throw new \InvalidArgumentException('Tokimo sandbox cpuCount must be between 0 and 64.');
        }
        if ($startupTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Tokimo sandbox startupTimeoutSeconds must be at least 1.');
        }
        if (! str_starts_with($remoteCwd, '/') || $remoteCwd === '/') {
            throw new \InvalidArgumentException('Tokimo sandbox remoteCwd must be an absolute directory below the guest filesystem root.');
        }
        if ($root !== null && self::isFilesystemRoot($root)) {
            throw new \InvalidArgumentException('The Tokimo sandbox workspace root cannot be the filesystem root.');
        }

        return new self(
            provider: 'tokimo',
            mode: $mode,
            remoteCwd: $remoteCwd,
            sync: $sync,
            cleanup: $cleanup,
            root: $root,
            exclude: $exclude,
            options: [
                'baseRootfs' => $baseRootfs,
                'binary' => $binary,
                'vmDir' => $vmDir,
                'memoryMb' => $memoryMb,
                'cpuCount' => $cpuCount,
                'network' => $network,
                'startupTimeoutSeconds' => $startupTimeoutSeconds,
            ],
        );
    }

    /** @api */
    public static function agentRun(
        string $accountId,
        ?string $sandboxId = null,
        ?string $templateName = null,
        ?string $apiKey = null,
        string $region = 'cn-hangzhou',
        ?string $endpoint = null,
        string $mode = 'filesystem',
        string $sync = 'none',
        string $remoteCwd = '/workspace',
        int $timeoutSeconds = 30,
        array $exclude = [],
    ): self {
        return new self(
            provider: 'agentrun',
            mode: $mode,
            remoteCwd: $remoteCwd,
            sync: $sync,
            cleanup: 'never',
            exclude: $exclude,
            options: [
                'accountId' => $accountId,
                'sandboxId' => $sandboxId,
                'templateName' => $templateName,
                'apiKey' => $apiKey,
                'region' => $region,
                'endpoint' => $endpoint,
                'timeoutSeconds' => $timeoutSeconds,
            ],
        );
    }

    /** @internal */
    public function enablesBash(): bool
    {
        return $this->mode === 'full';
    }

    private static function isFilesystemRoot(string $path): bool
    {
        $normalized = rtrim(str_replace('\\', '/', trim($path)), '/');

        return $normalized === ''
            || preg_match('/^[A-Za-z]:$/', $normalized) === 1
            || preg_match('#^//[^/]+/[^/]+$#', $normalized) === 1;
    }
}
