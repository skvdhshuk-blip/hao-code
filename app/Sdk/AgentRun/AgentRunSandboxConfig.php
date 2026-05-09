<?php

namespace HaoCode\Sdk\AgentRun;

/**
 * Configuration for Alibaba Cloud AgentRun Code Interpreter sandbox access.
 *
 * @api
 */
final class AgentRunSandboxConfig
{
    public function __construct(
        /** @api */ public readonly string $accountId,
        /** @api */ public readonly string $sandboxId = '',
        /** @api */ public readonly ?string $templateName = null,
        /** @api */ public readonly string $region = 'cn-hangzhou',
        /** @api */ public readonly ?string $accessKeyId = null,
        /** @api */ public readonly ?string $accessKeySecret = null,
        /** @api */ public readonly ?string $securityToken = null,
        /** @api */ public readonly ?string $parentId = null,
        /** @api */ public readonly string $remoteCwd = '/home/user',
        /** @api */ public readonly ?string $endpoint = null,
        /** @api */ public readonly int $timeoutSeconds = 30,
        /** @api */ public readonly bool $useRamEndpoint = true,
    ) {}

    /**
     * Build config from common AgentRun / Alibaba Cloud environment variables.
     *
     * @api
     */
    public static function fromEnv(?string $sandboxId = null, ?string $templateName = null, ?string $remoteCwd = null): self
    {
        $accountId = self::env('AGENTRUN_ACCOUNT_ID') ?: self::env('ALIBABA_CLOUD_ACCOUNT_ID');
        if ($accountId === '') {
            throw new \InvalidArgumentException('AGENTRUN_ACCOUNT_ID or ALIBABA_CLOUD_ACCOUNT_ID is required for AgentRun sandbox.');
        }

        return new self(
            accountId: $accountId,
            sandboxId: $sandboxId ?? self::env('AGENTRUN_SANDBOX_ID'),
            templateName: $templateName ?? (self::env('AGENTRUN_TEMPLATE_NAME') ?: null),
            region: self::env('AGENTRUN_REGION') ?: self::env('ALIBABA_CLOUD_REGION_ID') ?: 'cn-hangzhou',
            accessKeyId: self::env('ALIBABA_CLOUD_ACCESS_KEY_ID') ?: self::env('AGENTRUN_ACCESS_KEY_ID') ?: null,
            accessKeySecret: self::env('ALIBABA_CLOUD_ACCESS_KEY_SECRET') ?: self::env('AGENTRUN_ACCESS_KEY_SECRET') ?: null,
            securityToken: self::env('ALIBABA_CLOUD_SECURITY_TOKEN') ?: self::env('AGENTRUN_SECURITY_TOKEN') ?: null,
            parentId: self::env('AGENTRUN_PARENT_ID') ?: $accountId,
            remoteCwd: $remoteCwd ?? self::env('AGENTRUN_REMOTE_CWD') ?: '/home/user',
            endpoint: self::env('AGENTRUN_DATA_ENDPOINT') ?: null,
            timeoutSeconds: (int) (self::env('AGENTRUN_TIMEOUT_SECONDS') ?: 30),
        );
    }

    public function hasRamCredentials(): bool
    {
        return is_string($this->accessKeyId) && $this->accessKeyId !== ''
            && is_string($this->accessKeySecret) && $this->accessKeySecret !== '';
    }

    public function dataEndpoint(): string
    {
        if ($this->endpoint !== null && trim($this->endpoint) !== '') {
            return rtrim($this->endpoint, '/');
        }

        $account = $this->hasRamCredentials() && $this->useRamEndpoint
            ? $this->accountId.'-ram'
            : $this->accountId;

        return "https://{$account}.agentrun-data.{$this->region}.aliyuncs.com";
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
