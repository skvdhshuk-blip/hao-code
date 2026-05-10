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
