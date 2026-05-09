#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Weather Agent Demo
 *
 * A small SDK demo that runs the Hao Code agent against any supported
 * provider wire format and exercises:
 *   - HaoCode::query()       (one-shot)
 *   - HaoCode::stream()      (streaming with tool callbacks)
 *   - HaoCode::structured()  (JSON-schema constrained output)
 *
 * Tools call Open-Meteo (free, no key) for geocoding + current weather,
 * so you only need the provider credentials for the LLM itself.
 *
 * Usage — Anthropic (default; reads ~/.haocode/settings.json or ANTHROPIC_API_KEY):
 *   php examples/weather-agent.php
 *
 * Usage — OpenAI Chat Completions gateway (aihubmix, DeepSeek, vLLM, ...):
 *   WEATHER_PROVIDER_TYPE=openai_chat \
 *   WEATHER_API_KEY=sk-... \
 *   WEATHER_BASE_URL=https://aihubmix.com \
 *   WEATHER_MODEL=gpt-4o-mini \
 *   php examples/weather-agent.php
 *
 * Usage — OpenAI Responses API (official OpenAI endpoint):
 *   WEATHER_PROVIDER_TYPE=openai \
 *   WEATHER_API_KEY=sk-... \
 *   WEATHER_BASE_URL=https://api.openai.com \
 *   WEATHER_MODEL=gpt-5 \
 *   php examples/weather-agent.php
 */
$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

use HaoCode\Sdk\Examples\WeatherAgent;
use HaoCode\Sdk\HaoCodeConfig;

\HaoCode\Support\Runtime\SdkRuntime::boot(basePath: $packageRoot);

$providerType = getenv('WEATHER_PROVIDER_TYPE') ?: null;
$apiKey = getenv('WEATHER_API_KEY') ?: null;
$baseUrl = getenv('WEATHER_BASE_URL') ?: null;
$model = getenv('WEATHER_MODEL') ?: null;

$baseConfig = new HaoCodeConfig(
    apiKey: $apiKey,
    model: $model,
    baseUrl: $baseUrl,
    providerType: $providerType,
);

$workspaceDir = $packageRoot.'/examples/output/weather-agent';

try {
    $agent = new WeatherAgent($workspaceDir, $baseConfig);
    $result = $agent->run();

    echo "\nWeather Agent completed successfully.\n";
    echo 'Workspace: '.$workspaceDir."\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Weather Agent failed: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString()."\n");
    exit(1);
}
