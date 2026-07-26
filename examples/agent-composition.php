#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Agent composition demo
 *
 * A higher-level agent can use another agent as a tool. Define the specialist
 * once, then expose it to the parent via Agent::asTool().
 *
 * Usage:
 *   ANTHROPIC_API_KEY=sk-... php examples/agent-composition.php
 *
 * The "coder" agent handles implementation tasks; the "planner" agent decides
 * when to call it. Both share the same provider credentials for simplicity.
 */
$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Support\Runtime\SdkRuntime;

SdkRuntime::boot(basePath: $packageRoot);

$apiKey = getenv('ANTHROPIC_API_KEY') ?: null;

$coder = new Agent(
    name: 'coder',
    apiKey: $apiKey,
    systemPrompt: 'You are a terse PHP programmer. Write only the requested code.',
    allowedTools: ['Read', 'Bash'],
    maxTurns: 20,
);

$planner = new Agent(
    name: 'planner',
    apiKey: $apiKey,
    systemPrompt: 'Plan tasks, then delegate implementation to the coder tool when needed.',
    tools: [
        $coder->asTool('coder', 'Delegate a PHP implementation task to a specialist coder agent.'),
    ],
    allowedTools: ['coder'],
    maxTurns: 10,
);

echo "Running planner agent: {$planner->name}\n";

$result = Runner::run(
    $planner,
    'Create a small CLI script at /tmp/greeter.php that prints "Hello HaoCode".',
    RunOptions::make(),
);

echo "\n--- Result ---\n";
echo $result->text;
