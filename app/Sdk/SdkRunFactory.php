<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Sandbox\SandboxManager;

/** @internal */
final class SdkRunFactory
{
    public static function create(
        HaoCodeConfig $config,
        AgentLoopFactory $factory,
        ?StreamingClient $streamingClient = null,
    ): SdkRun {
        $runContext = self::createValidatedRunContext($config);
        $provider = $streamingClient
            ?? self::buildStreamingClient($config, $runContext->settings)
            ?? app(StreamingClient::class)->withSettingsManager($runContext->settings);

        $providerType = self::resolveProviderType($config, $runContext->settings);
        if ($config->credentialPool !== null) {
            $provider = new PooledProvider($provider, $config->credentialPool, $providerType);
        }

        $sandboxRuntime = $config->sandbox !== null
            ? SandboxManager::create($config->sandbox, $config->cwd)
            : null;
        $additionalTools = $config->tools;
        if ($sandboxRuntime !== null) {
            $additionalTools = array_merge($sandboxRuntime->tools(), $additionalTools);
        }

        try {
            $loop = $factory->createIsolated(
                toolFilter: $config->toolFilter(),
                workingDirectory: $config->effectiveWorkingDirectory(),
                additionalTools: $additionalTools,
                streamingClient: $provider,
                runContext: $runContext,
                ephemeral: $config->ephemeral,
            );
        } catch (\Throwable $e) {
            $sandboxRuntime?->close();

            throw $e;
        }

        $loop->setPermissionPromptHandler(fn () => true);
        $loop->setMaxTurns($config->maxTurns);

        if ($config->maxBudgetUsd !== null) {
            $loop->getCostTracker()->setThresholds(
                warn: $config->maxBudgetUsd * 0.8,
                stop: $config->maxBudgetUsd,
            );
        }

        if ($config->abortController !== null) {
            $config->abortController->onAbort(fn () => $loop->abort());
        }

        return new SdkRun($loop, $sandboxRuntime);
    }

    public static function createValidatedRunContext(HaoCodeConfig $config): AgentRunContext
    {
        $runContext = AgentRunContextFactory::make($config);
        $providerType = self::resolveProviderType($config, $runContext->settings);
        $hasPooledCredential = $config->credentialPool?->hasProvider($providerType) ?? false;
        $apiKey = $config->apiKey ?? $runContext->settings->getApiKey();

        if (trim($apiKey) === '' && ! $hasPooledCredential) {
            throw new \RuntimeException(
                'API key is required. Pass HaoCodeConfig(apiKey: ...), configure credentialPool, '.
                'or set ANTHROPIC_API_KEY in the process environment. .env files are not loaded automatically.',
            );
        }

        return $runContext;
    }

    public static function buildStreamingClient(
        HaoCodeConfig $config,
        ?SettingsManager $settings = null,
    ): ?StreamingClient {
        if ($config->apiKey === null
            && $config->baseUrl === null
            && $config->model === null
            && $config->maxTokens === null
            && $config->providerType === null) {
            return null;
        }

        $settings ??= AgentRunContextFactory::make($config)->settings;
        $providerType = self::resolveProviderType($config, $settings);
        $defaultBaseUrl = in_array($providerType, ['openai', 'openai_chat'], true)
            ? 'https://api.openai.com'
            : 'https://api.anthropic.com';
        $baseUrl = $config->baseUrl
            ?? ($config->providerType !== null ? $defaultBaseUrl : ($settings->getBaseUrl() ?: $defaultBaseUrl));

        return new StreamingClient(
            apiKey: $config->apiKey ?? $settings->getApiKey(),
            model: $config->model ?? $settings->getModel(),
            baseUrl: $baseUrl,
            maxTokens: $config->maxTokens ?? $settings->getMaxTokens(),
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            settingsManager: null,
            idleTimeoutSeconds: (int) config('haocode.api_stream_idle_timeout', 60),
            streamPollTimeoutSeconds: (float) config('haocode.api_stream_poll_timeout', 1.0),
            providerType: $providerType,
        );
    }

    private static function resolveProviderType(HaoCodeConfig $config, SettingsManager $settings): string
    {
        return match ($config->providerType) {
            'openai', 'openai_responses', 'responses' => 'openai',
            'openai_chat', 'openai_chat_completions', 'chat_completions' => 'openai_chat',
            'anthropic' => 'anthropic',
            null => $settings->getProviderType(),
            default => 'anthropic',
        };
    }
}
