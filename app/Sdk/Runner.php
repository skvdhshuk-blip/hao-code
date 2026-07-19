<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\ImageContentBlock;

/**
 * Execute a reusable {@see Agent} for a single run.
 *
 * Runner is the execution layer: it takes an Agent definition and a one-off
 * prompt (plus optional RunOptions) and runs the agent loop. The agent itself
 * stays unchanged and can be reused across many runs.
 *
 * @example
 *   $agent = new Agent(name: 'reviewer', model: 'claude-sonnet-4', tools: [new ReadTool()]);
 *   $result = Runner::run($agent, 'Review this file');
 *   echo $result->text;
 *
 * @api
 */
class Runner
{
    /**
     * Run the agent once and return the final result.
     *
     * @api
     */
    public static function run(Agent $agent, string $prompt, ?RunOptions $options = null): QueryResult
    {
        $options ??= new RunOptions();

        $run = self::createRun($agent, $options);
        $loop = $run->loop;

        $userInput = $options->images !== []
            ? ImageContentBlock::buildUserContent($prompt, $options->images)
            : $prompt;

        try {
            $response = $loop->run(
                userInput: $userInput,
                onTextDelta: $options->onText,
                onToolStart: $options->onToolStart,
                onToolComplete: $options->onToolComplete,
                onTurnStart: $options->onTurnStart,
                onThinkingDelta: $options->onThinking,
            );

            return new QueryResult(
                text: $response,
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $options->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
                turnsUsed: $loop->getLastRunTurns(),
            );
        } finally {
            $run->close();
        }
    }

    /**
     * Run the agent once and yield streaming messages.
     *
     * @api
     *
     * @return \Generator<int, Message>
     */
    public static function stream(Agent $agent, string $prompt, ?RunOptions $options = null): \Generator
    {
        $options ??= new RunOptions();

        $run = self::createRun($agent, $options);
        $loop = $run->loop;
        $queue = new \SplQueue;

        $userInput = $options->images !== []
            ? ImageContentBlock::buildUserContent($prompt, $options->images)
            : $prompt;

        $onText = function (string $delta) use ($queue, $options): void {
            $queue->enqueue(Message::text($delta));
            if ($options->onText) {
                ($options->onText)($delta);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolStart = function (string $name, array $input) use ($queue, $options): void {
            $queue->enqueue(Message::toolStart($name, $input));
            if ($options->onToolStart) {
                ($options->onToolStart)($name, $input);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolComplete = function (string $name, $result) use ($queue, $options): void {
            $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
            if ($options->onToolComplete) {
                ($options->onToolComplete)($name, $result);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onTurnStart = function (int $turn) use ($queue, $options): void {
            $queue->enqueue(Message::turn($turn));
            if ($options->onTurnStart) {
                ($options->onTurnStart)($turn);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $loop->setAutoDecisionHandler(function (Message $message) use ($queue): void {
            $queue->enqueue($message);
            \Fiber::getCurrent()?->suspend();
        });

        $response = null;
        $thrownException = null;

        $fiber = new \Fiber(function () use ($loop, $userInput, $onText, $onToolStart, $onToolComplete, $onTurnStart, $options, &$response, &$thrownException): void {
            try {
                $response = $loop->run(
                    userInput: $userInput,
                    onTextDelta: $onText,
                    onToolStart: $onToolStart,
                    onToolComplete: $onToolComplete,
                    onTurnStart: $onTurnStart,
                    onThinkingDelta: $options->onThinking,
                );
            } catch (\Throwable $e) {
                $thrownException = $e;
            }
        });

        try {
            $fiber->start();

            while (! $fiber->isTerminated()) {
                while (! $queue->isEmpty()) {
                    yield $queue->dequeue();
                }
                if (! $fiber->isTerminated()) {
                    $fiber->resume();
                }
            }

            while (! $queue->isEmpty()) {
                yield $queue->dequeue();
            }

            if ($thrownException instanceof HumanInterruptException) {
                yield Message::interrupt($thrownException->interrupt);

                return;
            }
            if ($thrownException !== null) {
                yield Message::error($thrownException->getMessage());

                return;
            }

            yield Message::result(
                text: $response ?? '',
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $options->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
            );
        } finally {
            $run->close();
        }
    }

    private static function createRun(Agent $agent, RunOptions $options): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

        return SdkRunFactory::createFromAgent($agent, $options, $factory);
    }

    private static function extractUsage(AgentLoop $loop): array
    {
        return [
            'input_tokens' => $loop->getTotalInputTokens(),
            'output_tokens' => $loop->getTotalOutputTokens(),
            'cache_creation_tokens' => $loop->getCacheCreationTokens(),
            'cache_read_tokens' => $loop->getCacheReadTokens(),
        ];
    }
}
