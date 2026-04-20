<?php

namespace HaoCode\Providers;

use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Notification\Notifier;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Session\AwaySummaryService;
use HaoCode\Services\Session\SessionTitleService;
use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Support\Terminal\PromptHudState;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register Validator (not included by default in Laravel Zero)
        if (!$this->app->bound('validator')) {
            $this->app->singleton('validator', fn ($app) => new \Illuminate\Validation\Factory(
                new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'),
                $app,
            ));
        }

        $this->app->singleton(SettingsManager::class);
        $this->app->singleton(SessionManager::class);

        $this->app->singleton(PhoenixTracer::class, function ($app) {
            return PhoenixTracer::fromSettings($app->make(SettingsManager::class));
        });

        $this->app->singleton(SessionTitleService::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new SessionTitleService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
                settingsManager: $settings,
            );
        });

        $this->app->singleton(AwaySummaryService::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new AwaySummaryService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
                settingsManager: $settings,
            );
        });
        $this->app->singleton(DenialTracker::class);
        $this->app->singleton(PermissionChecker::class);
        $this->app->singleton(HookExecutor::class);
        $this->app->singleton(OutputStyleLoader::class);
        $this->app->singleton(SessionMemory::class);
        $this->app->singleton(PromptHudState::class);

        $this->app->singleton(Notifier::class, function ($app) {
            return new Notifier(
                channel: null, // auto-detect
                hookExecutor: $app->make(HookExecutor::class),
            );
        });
        $this->app->singleton(SkillLoader::class);
        $this->app->singleton(CostTracker::class);
        $this->app->singleton(\HaoCode\Services\FileHistory\FileHistoryManager::class);
        $this->app->singleton(\HaoCode\Services\Task\TaskManager::class);
        $this->app->singleton(BackgroundAgentManager::class);
        $this->app->singleton(GitContext::class);
        $this->app->singleton(McpServerConfigManager::class);
        $this->app->singleton(McpConnectionManager::class, function ($app) {
            return new McpConnectionManager(
                configManager: $app->make(McpServerConfigManager::class),
            );
        });

        // Register ContextBuilder with its dependencies
        $this->app->singleton(ContextBuilder::class, function ($app) {
            return new ContextBuilder(
                settings: $app->make(SettingsManager::class),
                toolRegistry: $app->make(ToolRegistry::class),
                sessionMemory: $app->make(SessionMemory::class),
                skillLoader: $app->make(SkillLoader::class),
                gitContext: $app->make(GitContext::class),
                outputStyleLoader: $app->make(OutputStyleLoader::class),
            );
        });

        $this->app->singleton(StreamingClient::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new StreamingClient(
                apiKey: $settings->getApiKey(),
                model: $settings->getModel(),
                baseUrl: $settings->getBaseUrl(),
                maxTokens: $settings->getMaxTokens(),
                thinkingEnabled: (bool) env('HAOCODE_THINKING', false),
                thinkingBudget: (int) env('HAOCODE_THINKING_BUDGET', 10000),
                settingsManager: $settings,
                idleTimeoutSeconds: (int) config('haocode.api_stream_idle_timeout', 60),
                streamPollTimeoutSeconds: (float) config('haocode.api_stream_poll_timeout', 1.0),
            );
        });

        $this->app->singleton(MessageHistory::class);
        $this->app->singleton(QueryEngine::class, function ($app) {
            return new QueryEngine(
                streamingClient: $app->make(StreamingClient::class),
                toolRegistry: $app->make(ToolRegistry::class),
                tracer: $app->make(PhoenixTracer::class),
                settings: $app->make(SettingsManager::class),
            );
        });
        $this->app->singleton(ToolOrchestrator::class, function ($app) {
            return new ToolOrchestrator(
                toolRegistry: $app->make(ToolRegistry::class),
                permissionChecker: $app->make(PermissionChecker::class),
                hookExecutor: $app->make(HookExecutor::class),
                tracer: $app->make(PhoenixTracer::class),
            );
        });
        $this->app->singleton(ContextCompactor::class);
        $this->app->singleton(AgentLoopFactory::class);

        $this->app->singleton(AgentLoop::class, function ($app) {
            return new AgentLoop(
                queryEngine: $app->make(QueryEngine::class),
                toolOrchestrator: $app->make(ToolOrchestrator::class),
                contextBuilder: $app->make(ContextBuilder::class),
                messageHistory: $app->make(MessageHistory::class),
                permissionChecker: $app->make(PermissionChecker::class),
                sessionManager: $app->make(SessionManager::class),
                contextCompactor: $app->make(ContextCompactor::class),
                costTracker: $app->make(CostTracker::class),
                toolRegistry: $app->make(ToolRegistry::class),
                hookExecutor: $app->make(HookExecutor::class),
                tracer: $app->make(PhoenixTracer::class),
            );
        });
    }
}
