<?php

namespace HaoCode\Services\Api;

use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\RateLimitTracker;

/**
 * Decorator that wraps any LlmProvider with credential pool rotation.
 *
 * On each streaming call the decorator:
 *   1. Picks the next healthy credential from the pool.
 *   2. Clones credential-aware providers with the selected API key.
 *   3. On 429 / rate_limit_error, calls markExhausted() and retries with
 *      the next credential until the pool is exhausted.
 *
 * The decorator remains outside the wire-format providers and retries the same
 * normalized request with a fresh provider instance when a key is exhausted.
 */
class PooledProvider implements ForkSafeProvider, SettingsAwareProvider
{
    private array $lastRateLimitHeaders = [];

    public function __construct(
        private readonly LlmProvider $inner,
        private readonly CredentialPool $pool,
        private readonly string $providerName,
        private readonly ?RateLimitTracker $rateLimitTracker = null,
        private readonly bool $requireScopedSettings = false,
    ) {}

    /** @internal */
    public function requiringScopedSettings(): self
    {
        return new self(
            $this->inner,
            $this->pool,
            $this->providerName,
            $this->rateLimitTracker,
            true,
        );
    }

    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator {
        // If no credentials registered for this provider, pass through directly
        if (! $this->pool->hasProvider($this->providerName)) {
            yield from $this->inner->streamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort);
            $this->lastRateLimitHeaders = $this->inner->getLastRateLimitHeaders();

            return;
        }

        $triedIds = [];

        while (true) {
            $credential = $this->pool->pickNext($this->providerName);

            // pickNext returns null only when pool is empty (no credentials registered)
            if ($credential === null) {
                yield from $this->inner->streamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort);
                $this->lastRateLimitHeaders = $this->inner->getLastRateLimitHeaders();

                return;
            }

            // Avoid infinite loop if all credentials have been tried this round
            $credentialKey = $credential->idHash();
            if (in_array($credentialKey, $triedIds, true)) {
                throw new NoAvailableCredentialException(
                    "All credentials for provider '{$this->providerName}' have been tried and failed."
                );
            }
            $triedIds[] = $credentialKey;

            // Check rate limit before attempting
            if ($this->rateLimitTracker?->checkBlocked($credential)) {
                $this->pool->markExhausted($credential);

                continue;
            }

            $provider = $this->providerFor($credential);
            $responseStateCommitted = false;
            try {
                foreach ($provider->streamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort) as $event) {
                    if ($event->commitsResponseState()) {
                        $responseStateCommitted = true;
                    }
                    yield $event;
                }
                $this->lastRateLimitHeaders = $provider->getLastRateLimitHeaders();
                $this->pool->markSuccess($credential);
                $this->rateLimitTracker?->record($credential);

                return;
            } catch (ApiErrorException $e) {
                $this->lastRateLimitHeaders = $provider->getLastRateLimitHeaders();

                if ($this->isRateLimitError($e)) {
                    $retryAfter = $this->parseRetryAfter($this->lastRateLimitHeaders);
                    $this->pool->markExhausted($credential, $retryAfter);

                    if ($responseStateCommitted) {
                        throw $e;
                    }

                    continue;
                }

                $this->pool->markError($credential);
                throw $e;
            }
        }
    }

    public function getLastRateLimitHeaders(): array
    {
        return $this->lastRateLimitHeaders;
    }

    public function freshAfterFork(?\HaoCode\Services\Settings\SettingsManager $settingsManager = null): LlmProvider
    {
        if ($this->inner instanceof ForkSafeProvider) {
            $inner = $this->inner->freshAfterFork($settingsManager);
        } elseif ($settingsManager !== null && $this->inner instanceof SettingsAwareProvider) {
            $inner = $this->inner->withSettingsManager($settingsManager);
        } elseif ($settingsManager !== null && $this->requireScopedSettings) {
            throw new \RuntimeException(
                'Pooled provider cannot apply scoped settings because the inner provider is not settings-aware.',
            );
        } else {
            $inner = $this->inner;
        }

        return new self(
            $inner,
            $this->pool,
            $this->providerName,
            $this->rateLimitTracker,
            $this->requireScopedSettings,
        );
    }

    public function withSettingsManager(SettingsManager $settingsManager): self
    {
        if (! $this->inner instanceof SettingsAwareProvider && $this->requireScopedSettings) {
            throw new \RuntimeException(
                'Pooled provider cannot apply scoped settings because the inner provider is not settings-aware.',
            );
        }
        $inner = $this->inner instanceof SettingsAwareProvider
            ? $this->inner->withSettingsManager($settingsManager)
            : $this->inner;

        return new self(
            $inner,
            $this->pool,
            $this->providerName,
            $this->rateLimitTracker,
            $this->requireScopedSettings,
        );
    }

    private function providerFor(Credential $credential): LlmProvider
    {
        if ($this->inner instanceof ApiKeyAwareProvider) {
            return $this->inner->withApiKey($credential->apiKey);
        }

        // Compatibility fallback for third-party providers created before the
        // explicit credential-aware contract was introduced.
        try {
            $provider = clone $this->inner;
            $reflection = new \ReflectionObject($provider);

            do {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->getName() !== 'apiKey'
                        || $property->getDeclaringClass()->getName() !== $reflection->getName()
                    ) {
                        continue;
                    }
                    if ($property->isStatic() || $property->isReadOnly()) {
                        throw new \RuntimeException(
                            'Credential pool cannot write a static or readonly apiKey property.',
                        );
                    }

                    $property->setAccessible(true);
                    $property->setValue($provider, $credential->apiKey);

                    return $provider;
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Credential pool cannot safely clone and apply an API key to the configured provider.',
                0,
                $e,
            );
        }

        throw new \RuntimeException(
            'Credential pool requires an ApiKeyAwareProvider or a provider with a mutable apiKey property.',
        );
    }

    private function isRateLimitError(ApiErrorException $e): bool
    {
        return in_array($e->getErrorType(), ['rate_limit_error', 'overloaded_error'], true)
            || $e->getCode() === 429;
    }

    private function parseRetryAfter(array $headers): ?float
    {
        $value = $headers['retry-after'] ?? null;
        if ($value !== null && $value !== '' && is_numeric($value)) {
            return max(0.0, min((float) $value, 300.0));
        }

        return null;
    }
}
