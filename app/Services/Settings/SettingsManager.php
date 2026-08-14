<?php

namespace HaoCode\Services\Settings;

use HaoCode\Services\Permissions\PermissionMode;

class SettingsManager
{
    use SettingsManagerConstructConcern;
    use SettingsManagerGetMemoryStoragePathConcern;
    use SettingsManagerValidateExplicitSecurityModesConcern;

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const DEFAULT_MAX_TOKENS = 16384;

    private const DEFAULT_APPROVAL_POLICY = 'on-request';

    private const DEFAULT_SANDBOX_MODE = 'workspace-write';

    /** @var list<string> */
    private const PROVIDER_IDENTITY_OVERRIDES = [
        'api_key',
        'model',
        'provider_type',
        'api_base_url',
        'max_tokens',
        'context_window',
    ];

    private ?array $cachedSettings = null;

    private array $runtimeOverrides = [];

    /** @var (\Closure(ResolvedProviderConfig, self): void)|null */
    private ?\Closure $runtimeConfigurationValidator = null;

    private readonly SettingsFileStore $fileStore;

}
