<?php

namespace Tests\Unit;

use HaoCode\Services\Settings\SettingsManager;
use Tests\TestCase;

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
