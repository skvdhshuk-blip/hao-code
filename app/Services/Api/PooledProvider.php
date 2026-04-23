<?php

namespace HaoCode\Services\Api;

use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\RateLimitTracker;

/**
 * Decorator that wraps any LlmProvider with credential pool rotation.
 *
 * On each streaming call the decorator:
 *   1. Picks the next healthy credential from the pool.
 *   2. Injects the credential's API key into the inner provider via reflection.
 *   3. On 429 / rate_limit_error, calls markExhausted() and retries with
 *      the next credential until the pool is exhausted.
 *
 * The three concrete providers (Anthropic, OpenAiProvider, OpenAiChatProvider)
 * are NOT modified — this decorator sits in front of them.
 */
class PooledProvider implements LlmProvider
{
    private array $lastRateLimitHeaders = [];

    public function __construct(
        private readonly LlmProvider $inner,
        private readonly CredentialPool $pool,
        private readonly string $providerName,
        private readonly ?RateLimitTracker $rateLimitTracker = null,
    ) {}

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
            if (in_array($credential->id, $triedIds, true)) {
                throw new NoAvailableCredentialException(
                    "All credentials for provider '{$this->providerName}' have been tried and failed."
                );
            }
            $triedIds[] = $credential->id;

            // Check rate limit before attempting
            if ($this->rateLimitTracker?->checkBlocked($credential)) {
                $this->pool->markExhausted($credential);

                continue;
            }

            $this->injectApiKey($credential);

            try {
                yield from $this->inner->streamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort);
                $this->lastRateLimitHeaders = $this->inner->getLastRateLimitHeaders();
                $this->rateLimitTracker?->record($credential);

                return;
            } catch (ApiErrorException $e) {
                $this->lastRateLimitHeaders = $this->inner->getLastRateLimitHeaders();

                if ($this->isRateLimitError($e)) {
                    $retryAfter = $this->parseRetryAfter($this->lastRateLimitHeaders);
                    $this->pool->markExhausted($credential, $retryAfter);

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

    private function injectApiKey(Credential $credential): void
    {
        try {
            $ref = new \ReflectionObject($this->inner);
            // All three providers have a private $apiKey property
            if ($ref->hasProperty('apiKey')) {
                $prop = $ref->getProperty('apiKey');
                $prop->setAccessible(true);
                $prop->setValue($this->inner, $credential->apiKey);
            }
        } catch (\ReflectionException) {
            // If reflection fails, proceed — the inner provider will use its own key
        }
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
            return min((float) $value, 300.0);
        }

        return null;
    }
}
