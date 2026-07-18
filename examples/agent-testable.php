#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unit-testable agent example
 *
 * Demonstrates how to run an Agent through the Runner with a fake AgentLoop so
 * no API key is required. This pattern is useful in CI: boot the SDK runtime,
 * replace the AgentLoopFactory with a test double, and assert on the returned
 * QueryResult.
 */
$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\QueryResult;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Support\Runtime\SdkRuntime;

SdkRuntime::boot(basePath: $packageRoot);

// A fake AgentLoop that returns a fixed answer and reports deterministic usage.
$fakeLoop = new class(\Closure::fromCallable(static fn () => 'fake response')) extends AgentLoop {
    private \Closure $fixedResponse;

    public function __construct(\Closure $fixedResponse)
    {
        $this->fixedResponse = $fixedResponse;
    }

    public function run(
        string|array $userInput = '',
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?callable $onThinkingDelta = null,
    ): string {
        return ($this->fixedResponse)();
    }

    public function getTotalInputTokens(): int { return 11; }
    public function getTotalOutputTokens(): int { return 3; }
    public function getCacheCreationTokens(): int { return 0; }
    public function getCacheReadTokens(): int { return 0; }
    public function getEstimatedCost(): float { return 0.00001; }
    public function getLastRunTurns(): int { return 1; }
};

$fakeFactory = new class($fakeLoop) extends AgentLoopFactory {
    private AgentLoop $fakeLoop;

    public function __construct(AgentLoop $fakeLoop)
    {
        $this->fakeLoop = $fakeLoop;
    }

    public function createIsolated(
        ?callable $toolFilter = null,
        ?string $workingDirectory = null,
        array $additionalTools = [],
        ?LlmProvider $streamingClient = null,
        ?AgentRunContext $runContext = null,
        bool $ephemeral = false,
        bool $afterFork = false,
        bool $readOnly = false,
    ): AgentLoop {
        return $this->fakeLoop;
    }
};

// Replace the real factory with the fake one for the duration of this script.
SdkRuntime::app()->instance(AgentLoopFactory::class, $fakeFactory);

$agent = new Agent(
    name: 'testable-agent',
    systemPrompt: 'You are a test agent.',
    allowedTools: [],
);

$result = Runner::run($agent, 'What is the answer?', RunOptions::make());

assert($result instanceof QueryResult, 'Runner::run returns a QueryResult');
assert($result->text === 'fake response', 'Result text comes from the fake loop');
assert($result->usage['input_tokens'] === 11, 'Usage is forwarded from the fake loop');
assert($result->turnsUsed === 1, 'Turns used is forwarded from the fake loop');

echo "Testable agent run succeeded.\n";
echo "Result: {$result->text}\n";
