<?php

namespace HaoCode\Sdk;

use HaoCode\Support\Runtime\SdkRuntime;

/**
 * Compatibility shim for applications that referenced the old framework provider.
 *
 * The SDK now boots itself through a small framework-free runtime, so this
 * class no longer extends or requires an external ServiceProvider.
 *
 * @internal
 */
class HaoCodeSdkServiceProvider
{
    public function register(): void
    {
        SdkRuntime::boot();
    }

    public function boot(): void
    {
        SdkRuntime::boot();
    }
}
