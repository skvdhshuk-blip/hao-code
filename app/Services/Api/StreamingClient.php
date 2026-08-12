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
class StreamingClient implements ApiKeyAwareProvider, ForkSafeProvider, SettingsAwareProvider
{
    private AnthropicProvider $anthropic;
    private OpenAiProvider $openai;
    private OpenAiChatProvider $openaiChat;
    private ProviderRegistry $providerRegistry;
    private ?SettingsManager $settingsManager;
    private string $defaultProviderType;
    private ?LlmProvider $lastUsed = null;

    /** @var array<string, mixed> */
    private array $connectionConfig;

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
        bool $oauthBearer = false,
        array $headers = [],
    ) {
        $this->connectionConfig = [
            'apiKey' => $apiKey,
            'model' => $model,
            'baseUrl' => $baseUrl,
            'maxTokens' => $maxTokens,
            'apiVersion' => $apiVersion,
            'thinkingEnabled' => $thinkingEnabled,
            'thinkingBudget' => $thinkingBudget,
            'idleTimeoutSeconds' => $idleTimeoutSeconds,
            'streamPollTimeoutSeconds' => $streamPollTimeoutSeconds,
            'timeProvider' => $timeProvider,
            'providerType' => $providerType,
            'oauthBearer' => $oauthBearer,
            'headers' => RequestHeaders::sanitize($headers),
        ];
        $this->settingsManager = $settingsManager;
        $this->defaultProviderType = \HaoCode\Services\Settings\ProviderType::normalizeRequired($providerType);
        $isAnthropicSelected = $this->defaultProviderType === 'anthropic';
        $isOpenAiSelected = $this->defaultProviderType === 'openai';
        $isOpenAiChatSelected = $this->defaultProviderType === 'openai_chat';
        $this->anthropic = $anthropicProvider ?? new AnthropicProvider(
            apiKey: $isAnthropicSelected ? $apiKey : '',
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
            oauthBearer: $oauthBearer,
            headers: $this->connectionConfig['headers'],
        );

        // When a SettingsManager is attached it owns the base URL, so the
        // fallback defaults below only matter for SDK consumers who pass a
        // custom baseUrl without wiring up settings. In that case we honour
        // the caller's baseUrl on whichever provider they actually selected
        // via $providerType; the unused providers never fire.
        $this->openai = $openAiProvider ?? new OpenAiProvider(
            apiKey: $isOpenAiSelected ? $apiKey : '',
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
            headers: $this->connectionConfig['headers'],
        );

        $this->openaiChat = $openAiChatProvider ?? new OpenAiChatProvider(
            apiKey: $isOpenAiChatSelected ? $apiKey : '',
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
            headers: $this->connectionConfig['headers'],
        );
        $this->rebuildProviderRegistry();
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
     * Provider buckets for which the constructor supplied a fallback key.
     * The key itself is intentionally never exposed.
     *
     * @return list<string>
     * @internal
     */
    public function configuredCredentialProviderTypes(): array
    {
        return trim((string) $this->connectionConfig['apiKey']) === ''
            ? []
            : [$this->defaultProviderType];
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
        $client->rebuildProviderRegistry();
        $client->lastUsed = null;

        return $client;
    }

    public function withApiKey(string $apiKey): LlmProvider
    {
        $client = clone $this;
        $client->defaultProviderType = $this->settingsManager?->getProviderType() ?? $this->defaultProviderType;
        $client->settingsManager = null;
        // A pooled credential belongs to exactly one provider bucket. Apply it
        // only to the adapter selected for this request; keeping it out of the
        // unused adapters prevents a future dispatch bug from crossing vendor
        // credential boundaries.
        match ($client->defaultProviderType) {
            \HaoCode\Services\Settings\ProviderType::ANTHROPIC => $client->anthropic = $this->anthropic->withApiKey($apiKey),
            \HaoCode\Services\Settings\ProviderType::OPENAI => $client->openai = $this->openai->withApiKey($apiKey),
            \HaoCode\Services\Settings\ProviderType::OPENAI_CHAT => $client->openaiChat = $this->openaiChat->withApiKey($apiKey),
        };
        $client->rebuildProviderRegistry();
        $client->lastUsed = null;

        return $client;
    }

    public function freshAfterFork(?SettingsManager $settingsManager = null): LlmProvider
    {
        $config = $this->connectionConfig;

        return new self(
            apiKey: $settingsManager?->getApiKey() ?? $config['apiKey'],
            model: $settingsManager?->getModel() ?? $config['model'],
            baseUrl: $settingsManager?->getBaseUrl() ?? $config['baseUrl'],
            maxTokens: $settingsManager?->getMaxTokens() ?? $config['maxTokens'],
            apiVersion: $config['apiVersion'],
            thinkingEnabled: $settingsManager?->isThinkingEnabled() ?? $config['thinkingEnabled'],
            thinkingBudget: $settingsManager?->getThinkingBudget() ?? $config['thinkingBudget'],
            settingsManager: $settingsManager,
            idleTimeoutSeconds: $config['idleTimeoutSeconds'],
            streamPollTimeoutSeconds: $config['streamPollTimeoutSeconds'],
            timeProvider: $config['timeProvider'],
            providerType: $settingsManager?->getProviderType() ?? $config['providerType'],
            oauthBearer: $settingsManager?->isOauthBearer() ?? $config['oauthBearer'],
            headers: $settingsManager?->getHeaders() ?: $config['headers'],
        );
    }

    private function selectProvider(): LlmProvider
    {
        $type = $this->settingsManager?->getProviderType() ?? $this->defaultProviderType;

        return $this->providerRegistry->get($type);
    }

    private function rebuildProviderRegistry(): void
    {
        $this->providerRegistry = new ProviderRegistry([
            \HaoCode\Services\Settings\ProviderType::ANTHROPIC => $this->anthropic,
            \HaoCode\Services\Settings\ProviderType::OPENAI => $this->openai,
            \HaoCode\Services\Settings\ProviderType::OPENAI_CHAT => $this->openaiChat,
        ]);
    }
}
