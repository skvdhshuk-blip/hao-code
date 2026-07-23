<?php

namespace HaoCode\Services\Api;

use HaoCode\Services\Settings\SettingsManager;

/** @internal */
interface SettingsAwareProvider extends LlmProvider
{
    /**
     * Return an isolated provider view backed by run-scoped settings.
     */
    public function withSettingsManager(SettingsManager $settingsManager): LlmProvider;
}
