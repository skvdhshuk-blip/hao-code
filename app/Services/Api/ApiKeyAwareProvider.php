<?php

namespace HaoCode\Services\Api;

/** @internal */
interface ApiKeyAwareProvider extends LlmProvider
{
    /**
     * Return an isolated provider using the supplied credential while retaining
     * the configured transport, model, endpoint, and stream behaviour.
     */
    public function withApiKey(string $apiKey): LlmProvider;
}
