<?php

namespace HaoCode\Support\Runtime;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\AwaySummaryService;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Session\SessionTitleService;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Support\Container\Container;
use HaoCode\Tools\Agent\AgentTool;
use HaoCode\Tools\Agent\SendMessageTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\Config\ConfigTool;
use HaoCode\Tools\FileEdit\ApplyPatchTool;
use HaoCode\Tools\FileEdit\FileEditTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\Glob\GlobTool;
use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Tools\Lsp\LspTool;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\Notebook\NotebookEditTool;
use HaoCode\Tools\PlanMode\EnterPlanModeTool;
use HaoCode\Tools\PlanMode\ExitPlanModeTool;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\Skill\SkillTool;
use HaoCode\Tools\Sleep\SleepTool;
use HaoCode\Tools\Task\TaskCreateTool;
use HaoCode\Tools\Task\TaskGetTool;
use HaoCode\Tools\Task\TaskListTool;
use HaoCode\Tools\Task\TaskStopTool;
use HaoCode\Tools\Task\TaskUpdateTool;
use HaoCode\Tools\Team\TeamCreateTool;
use HaoCode\Tools\Team\TeamAwaitTool;
use HaoCode\Tools\Team\TeamCollectTool;
use HaoCode\Tools\Team\TeamDeleteTool;
use HaoCode\Tools\Team\TeamListTool;
use HaoCode\Tools\TodoWrite\TodoWriteTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\WebSearch\WebSearchTool;
use HaoCode\Tools\Worktree\EnterWorktreeTool;
use HaoCode\Tools\Worktree\ExitWorktreeTool;

final class SdkRuntime
{
    private static ?Container $container = null;

    public static function boot(?string $basePath = null, ?string $storagePath = null): Container
    {
        if (self::$container !== null) {
            if ($storagePath !== null) {
                $current = rtrim(self::$container->storagePath(), '/\\');
                $requested = rtrim($storagePath, '/\\');
                if ($requested !== $current) {
                    throw new \LogicException(
                        'SdkRuntime storage path cannot be changed after the runtime has booted. '
                        .'Call SdkRuntime::reset() before booting with a different path.',
                    );
                }
            }

            return self::$container;
        }

        $basePath ??= dirname(__DIR__, 3);
        $app = new Container($basePath);
        self::$container = $app;

        $resolvedStoragePath = $storagePath ?? self::resolveStoragePath($basePath);
        $app->useStoragePath($resolvedStoragePath);

        self::loadConfig($app);
        self::registerCoreServices($app);
        self::registerToolRegistry($app);

        return $app;
    }

    public static function app(?string $abstract = null): mixed
    {
        $app = self::boot();

        return $abstract === null ? $app : $app->make($abstract);
    }

    public static function config(null|string|array $key = null, mixed $default = null): mixed
    {
        $app = self::boot();
        if (is_array($key)) {
            foreach ($key as $configKey => $value) {
                $app->setConfig((string) $configKey, $value);
            }

            return null;
        }

        return $app->config($key, $default);
    }

    public static function environment(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }

    public static function storagePath(string $path = ''): string
    {
        return self::boot()->storagePath($path);
    }

    public static function resourcePath(string $path = ''): string
    {
        return self::boot()->resourcePath($path);
    }

    public static function reset(): void
    {
        BackgroundAgentManager::assertRuntimeResetSafe();
        BackgroundAgentManager::resetSignalReaper();
        self::$container = null;
    }

    private static function resolveStoragePath(string $basePath): string
    {
        $envPath = $_SERVER['HAOCODE_STORAGE_PATH'] ?? getenv('HAOCODE_STORAGE_PATH') ?: null;

        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        $autoloadPath = realpath($basePath.'/../../autoload.php') ?: realpath($basePath.'/vendor/autoload.php') ?: null;
        $installedPath = (new StoragePathResolver())->resolve($basePath, $autoloadPath);

        return $installedPath ?? $basePath.'/storage';
    }

    private static function loadConfig(Container $app): void
    {
        $path = $app->configPath('haocode.php');
        if (file_exists($path)) {
            $defaultSessionPath = $app->storagePath('app/haocode/sessions');
            /** @var array<string, mixed> $config */
            $config = require $path;
            $app->mergeConfig('haocode', $config);
        }
    }

    private static function registerCoreServices(Container $app): void
    {
        $app->singleton(SettingsManager::class);
        $app->singleton(SessionManager::class);
        $app->singleton(PhoenixTracer::class, fn (Container $app) => PhoenixTracer::fromSettings($app->make(SettingsManager::class)));
        $app->singleton(SessionTitleService::class, function (Container $app) {
            $settings = $app->make(SettingsManager::class);

            return new SessionTitleService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
                settingsManager: $settings,
            );
        });
        $app->singleton(AwaySummaryService::class, function (Container $app) {
            $settings = $app->make(SettingsManager::class);

            return new AwaySummaryService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
                settingsManager: $settings,
            );
        });
        $app->singleton(DenialTracker::class);
        $app->singleton(PermissionChecker::class);
        $app->singleton(HookExecutor::class);
        $app->singleton(OutputStyleLoader::class);
        $app->singleton(SessionMemory::class);
        $app->singleton(MemoryStoreInterface::class, fn (Container $app) => new JsonMemoryStore(
            $app->make(SettingsManager::class)->getMemoryStoragePath(),
        ));
        $app->singleton(SkillLoader::class);
        $app->singleton(CostTracker::class);
        $app->singleton(\HaoCode\Services\FileHistory\FileHistoryManager::class);
        $app->singleton(\HaoCode\Services\Task\TaskManager::class, fn (Container $app) => new \HaoCode\Services\Task\TaskManager(
            $app->storagePath('app/haocode/tasks'),
        ));
        $app->singleton(BackgroundAgentManager::class, fn (Container $app) => new BackgroundAgentManager(
            $app->storagePath('app/haocode/background-agents'),
            $app->make(\HaoCode\Services\Task\TaskManager::class),
        ));
        $app->singleton(TeamManager::class, fn (Container $app) => new TeamManager(
            $app->storagePath('app/haocode/teams'),
        ));
        $app->singleton(GitContext::class);
        $app->singleton(McpServerConfigManager::class);
        $app->singleton(McpConnectionManager::class, fn (Container $app) => new McpConnectionManager(
            configManager: $app->make(McpServerConfigManager::class),
        ));
        $app->singleton(ContextBuilder::class, fn (Container $app) => new ContextBuilder(
            settings: $app->make(SettingsManager::class),
            toolRegistry: $app->make(ToolRegistry::class),
            memoryStore: $app->make(MemoryStoreInterface::class),
            skillLoader: $app->make(SkillLoader::class),
            gitContext: $app->make(GitContext::class),
            outputStyleLoader: $app->make(OutputStyleLoader::class),
        ));
        $app->singleton(StreamingClient::class, function (Container $app) {
            $settings = $app->make(SettingsManager::class);

            return new StreamingClient(
                apiKey: $settings->getApiKey(),
                model: $settings->getModel(),
                baseUrl: $settings->getBaseUrl(),
                maxTokens: $settings->getMaxTokens(),
                thinkingEnabled: (bool) self::environment('HAOCODE_THINKING', false),
                thinkingBudget: (int) self::environment('HAOCODE_THINKING_BUDGET', 10000),
                settingsManager: $settings,
                idleTimeoutSeconds: (int) self::config('haocode.api_stream_idle_timeout', 60),
                streamPollTimeoutSeconds: (float) self::config('haocode.api_stream_poll_timeout', 1.0),
            );
        });
        $app->singleton(MessageHistory::class);
        $app->singleton(QueryEngine::class, fn (Container $app) => new QueryEngine(
            streamingClient: $app->make(StreamingClient::class),
            toolRegistry: $app->make(ToolRegistry::class),
            tracer: $app->make(PhoenixTracer::class),
            settings: $app->make(SettingsManager::class),
        ));
        $app->singleton(ToolOrchestrator::class, fn (Container $app) => new ToolOrchestrator(
            toolRegistry: $app->make(ToolRegistry::class),
            permissionChecker: $app->make(PermissionChecker::class),
            hookExecutor: $app->make(HookExecutor::class),
            tracer: $app->make(PhoenixTracer::class),
        ));
        $app->singleton(ContextCompactor::class);
        $app->singleton(AgentLoopFactory::class, fn (Container $app) => new AgentLoopFactory($app));
    }

    private static function registerToolRegistry(Container $app): void
    {
        $app->singleton(ToolRegistry::class, function (Container $app) {
            $registry = new ToolRegistry();

            // CronCreate/List/Delete are intentionally not advertised here:
            // the legacy JSON scheduler has no production execution driver.
            // Keep the tool classes available for compatibility until a
            // claim/execute/complete runtime contract is wired end to end.
            foreach ([
                BashTool::class,
                FileReadTool::class,
                FileEditTool::class,
                ApplyPatchTool::class,
                FileWriteTool::class,
                GlobTool::class,
                GrepTool::class,
                TodoWriteTool::class,
                WebFetchTool::class,
                WebSearchTool::class,
                AgentTool::class,
                SendMessageTool::class,
                SkillTool::class,
                LspTool::class,
                NotebookEditTool::class,
                EnterPlanModeTool::class,
                ExitPlanModeTool::class,
                ConfigTool::class,
                EnterWorktreeTool::class,
                ExitWorktreeTool::class,
                TaskCreateTool::class,
                TaskGetTool::class,
                TaskListTool::class,
                TaskUpdateTool::class,
                TaskStopTool::class,
                TeamCreateTool::class,
                TeamAwaitTool::class,
                TeamCollectTool::class,
                TeamListTool::class,
                TeamDeleteTool::class,
                ListMcpResourcesTool::class,
                ReadMcpResourceTool::class,
                MemoryReadTool::class,
                SleepTool::class,
            ] as $toolClass) {
                $registry->register($app->make($toolClass));
            }

            return $registry;
        });
    }
}
