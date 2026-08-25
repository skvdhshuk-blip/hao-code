<?php

namespace Tests\Unit;

use Tests\TestCase;

/** Test fixture that injects the current test configuration as immutable defaults. */
class SettingsManager extends \HaoCode\Services\Settings\SettingsManager
{
    public function __construct(?string $workingDirectory = null, array $runtimeDefaults = [])
    {
        parent::__construct(
            $workingDirectory,
            $runtimeDefaults !== []
                ? $runtimeDefaults
                : \HaoCode\Support\Runtime\SdkRuntime::settingsDefaults(),
        );
    }

    /** @param array<string, mixed> $settings */
    public function useCachedSettings(array $settings): void
    {
        $setter = \Closure::bind(
            function (array $values): void { $this->cachedSettings = $values; },
            $this,
            \HaoCode\Services\Settings\SettingsManager::class,
        );
        $setter($settings);
    }
}

class SettingsManagerTest extends TestCase
{
    use SettingsManagerTestSetUpConcern;
    use SettingsManagerTestTestAllReturnsExpectedKeysConcern;
    use SettingsManagerTestTestClearingNamedProviderPreservesExplicitConnectionOverridesConcern;


    // ─── runtime overrides ────────────────────────────────────────────────

    // ─── getBaseUrl ───────────────────────────────────────────────────────

    // ─── getMaxTokens ─────────────────────────────────────────────────────

    // ─── getPermissionMode ────────────────────────────────────────────────

    // ─── all() ────────────────────────────────────────────────────────────

    // ─── permissions merge from global + project settings ─────────────────

    // ─── thinking / effort / vim ─────────────────────────────────────────
}
