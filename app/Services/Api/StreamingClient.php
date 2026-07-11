<?php

namespace HaoCode\Services\Api;

use HaoCode\Services\Settings\SettingsManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Public-facing streaming entry point.
 *
 * Historically this class owned the Anthropic Messages API wire logic
 * directly. It is now a thin dispatcher that selects the concrete
 * {@see LlmProvider} implementation based on the active provider's
 * "type" field in settings ("anthropic" or "openai"), resolved at the
 * start of each turn so runtime /provider switches take effect
 * immediately.
 *
 * The public surface (`streamMessages`, `getLastRateLimitHeaders`) is
 * preserved so QueryEngine, SessionTitleService, and existing tests
 * that mock StreamingClient keep working.
 */
class StreamingClient implements LlmProvider
{
    private AnthropicProvider $anthropic;
    private OpenAiProvider $openai;
    private OpenAiChatProvider $openaiChat;
    private ?SettingsManager $settingsManager;
    private string $defaultProviderType;
    private ?LlmProvider $lastUsed = null;

    public function __construct(
        string $apiKey,
        string $model,
        string $baseUrl = 'https://api.anthropic.com',
        int $maxTokens = 16384,
        string $apiVersion = '2023-06-01',
        bool $thinkingEnabled = false,
        int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        ?SettingsManager $settingsManager = null,
        int $idleTimeoutSeconds = 60,
        float $streamPollTimeoutSeconds = 1.0,
        ?callable $timeProvider = null,
        ?AnthropicProvider $anthropicProvider = null,
        ?OpenAiProvider $openAiProvider = null,
        ?OpenAiChatProvider $openAiChatProvider = null,
        string $providerType = 'anthropic',
    ) {
        $this->settingsManager = $settingsManager;
        $this->defaultProviderType = match ($providerType) {
            'openai', 'openai_chat', 'anthropic' => $providerType,
            default => 'anthropic',
        };
        $this->anthropic = $anthropicProvider ?? new AnthropicProvider(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            maxTokens: $maxTokens,
            apiVersion: $apiVersion,
            thinkingEnabled: $thinkingEnabled,
            thinkingBudget: $thinkingBudget,
            httpClient: $httpClient,
            settingsManager: $settingsManager,
            idleTimeoutSeconds: $idleTimeoutSeconds,
            streamPollTimeoutSeconds: $streamPollTimeoutSeconds,
            timeProvider: $timeProvider,
        );

        // When a SettingsManager is attached it owns the base URL, so the
        // fallback defaults below only matter for SDK consumers who pass a
        // custom baseUrl without wiring up settings. In that case we honour
        // the caller's baseUrl on whichever provider they actually selected
        // via $providerType; the unused providers never fire.
        $isAnthropicSelected = $this->defaultProviderType === 'anthropic';
        $this->openai = $openAiProvider ?? new OpenAiProvider(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $isAnthropicSelected ? 'https://api.openai.com' : $baseUrl,
            maxTokens: $maxTokens,
            thinkingEnabled: $thinkingEnabled,
            thinkingBudget: $thinkingBudget,
            httpClient: $httpClient,
            settingsManager: $settingsManager,
            idleTimeoutSeconds: $idleTimeoutSeconds,
            streamPollTimeoutSeconds: $streamPollTimeoutSeconds,
            timeProvider: $timeProvider,
        );

        $this->openaiChat = $openAiChatProvider ?? new OpenAiChatProvider(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $isAnthropicSelected ? 'https://api.openai.com' : $baseUrl,
            maxTokens: $maxTokens,
            thinkingEnabled: $thinkingEnabled,
            thinkingBudget: $thinkingBudget,
            httpClient: $httpClient,
            settingsManager: $settingsManager,
            idleTimeoutSeconds: $idleTimeoutSeconds,
            streamPollTimeoutSeconds: $streamPollTimeoutSeconds,
            timeProvider: $timeProvider,
        );
    }

    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator {
        $provider = $this->selectProvider();
        $this->lastUsed = $provider;

        yield from $provider->streamMessages(
            systemPrompt: $systemPrompt,
            messages: $messages,
            tools: $tools,
            onRawEvent: $onRawEvent,
            shouldAbort: $shouldAbort,
        );
    }

    public function getLastRateLimitHeaders(): array
    {
        return $this->lastUsed?->getLastRateLimitHeaders() ?? [];
    }

    /**
     * Clone the dispatcher and providers for an isolated agent run.
     *
     * Provider transports are preserved, while every dynamic API setting is
     * resolved from the supplied run-scoped SettingsManager.
     */
    public function withSettingsManager(SettingsManager $settingsManager): self
    {
        $client = clone $this;
        $client->settingsManager = $settingsManager;
        $client->anthropic = $this->anthropic->withSettingsManager($settingsManager);
        $client->openai = $this->openai->withSettingsManager($settingsManager);
        $client->openaiChat = $this->openaiChat->withSettingsManager($settingsManager);
        $client->lastUsed = null;

        return $client;
    }

    private function selectProvider(): LlmProvider
    {
        $type = $this->settingsManager?->getProviderType() ?? $this->defaultProviderType;

        return match ($type) {
            'openai' => $this->openai,
            'openai_chat' => $this->openaiChat,
            default => $this->anthropic,
        };
    }
}
