#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Basic Agent + Runner demo
 *
 * Shows the recommended new API: define a reusable Agent, then execute it with
 * Runner::run(). This is the same agent used in every run; only the prompt and
 * per-run options change.
 *
 * Usage:
 *   ANTHROPIC_API_KEY=sk-... php examples/agent-basic.php
 *
 * Or with an OpenAI-compatible gateway:
 *   AGENT_PROVIDER_TYPE=openai_chat \
 *   AGENT_API_KEY=sk-... \
 *   AGENT_BASE_URL=https://api.deepseek.com/anthropic \
 *   AGENT_MODEL=deepseek-v4-flash \
 *   php examples/agent-basic.php
 */
$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Support\Runtime\SdkRuntime;

SdkRuntime::boot(basePath: $packageRoot);

$apiKey = getenv('AGENT_API_KEY') ?: getenv('ANTHROPIC_API_KEY') ?: null;
$baseUrl = getenv('AGENT_BASE_URL') ?: null;
$providerType = getenv('AGENT_PROVIDER_TYPE') ?: null;
$model = getenv('AGENT_MODEL') ?: null;

$agent = new Agent(
    name: 'file-explainer',
    model: $model,
    apiKey: $apiKey,
    baseUrl: $baseUrl,
    providerType: $providerType,
    systemPrompt: 'You are a concise assistant. Read files when asked and answer in one sentence.',
    allowedTools: ['Read'],
    maxTurns: 10,
);

echo "Running agent: {$agent->name}\n";

$result = Runner::run(
    $agent,
    'Summarize the README in this project.',
    RunOptions::make(cwd: $packageRoot),
);

echo "\n--- Result ---\n";
echo $result->text;
echo "\n--- Usage ---\n";
echo json_encode($result->usage, JSON_PRETTY_PRINT)."\n";
