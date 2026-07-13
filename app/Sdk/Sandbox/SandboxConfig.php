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
        if ($root !== null && rtrim($root, DIRECTORY_SEPARATOR) === '') {
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
}
