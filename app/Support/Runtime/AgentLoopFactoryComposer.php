<?php

declare(strict_types=1);

namespace HaoCode\Support\Runtime;

use HaoCode\Services\Agent\AgentLoopBuilder;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentPersistenceFactory;
use HaoCode\Services\Agent\AgentToolSetBuilder;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Support\Container\Container;
use HaoCode\Tools\ToolRegistry;

/** SDK composition-edge adapter for AgentLoopFactory. @internal */
final class AgentLoopFactoryComposer
{
    public static function make(Container $app): AgentLoopFactory
    {
        $sessionPath = $app->config(
            'haocode.session_path',
            $app->storagePath('app/haocode/sessions'),
        );
        if (! is_string($sessionPath) || trim($sessionPath) === '') {
            throw new \RuntimeException('Session storage path must be a non-empty string.');
        }
        $databasePath = $app->config('haocode.run_database_path');

        return new AgentLoopFactory(
            new AgentToolSetBuilder($app->make(ToolRegistry::class)),
            new AgentPersistenceFactory(
                $sessionPath,
                (string) $app->config('haocode.run_store', 'jsonl'),
                is_string($databasePath) ? $databasePath : null,
            ),
            new AgentLoopBuilder(
                $app->make(SettingsManager::class),
                $app->make(ContextBuilder::class),
                $app->make(PermissionChecker::class),
                $app->make(HookExecutor::class),
                $app->make(PhoenixTracer::class),
                $app->make(LlmProvider::class),
                $app->resourcePath('prompts/system.md'),
                is_string($app->config('haocode.global_settings_path'))
                    ? $app->config('haocode.global_settings_path')
                    : null,
            ),
        );
    }
}
