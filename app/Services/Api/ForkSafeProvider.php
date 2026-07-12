<?php

namespace HaoCode\Services\Api;

use HaoCode\Services\Settings\SettingsManager;

/** @internal */
interface ForkSafeProvider extends LlmProvider
{
    /**
     * Rebuild network transports after pcntl_fork while retaining configuration.
     */
    public function freshAfterFork(?SettingsManager $settingsManager = null): LlmProvider;
}
