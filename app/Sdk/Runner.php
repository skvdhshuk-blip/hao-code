<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentInvocation;
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
 *   $agent = new Agent(name: 'reviewer', model: 'claude-sonnet-4', allowedTools: ['Read'], tools: [new ReadTool()]);
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
        $ephemeral = $options->effectiveEphemeral($agent);

        $run = self::createRun($agent, $options);
        $loop = $run->loop;

        try {
            $userInput = $options->images !== []
                ? ImageContentBlock::buildUserContent($prompt, $options->images, $options->cwd)
                : $prompt;

            $result = (new AgentInvocation(
                input: $userInput,
                onTextDelta: $options->onText,
                onToolStart: $options->onToolStart,
                onToolComplete: $options->onToolComplete,
                onTurnStart: $options->onTurnStart,
                onThinkingDelta: $options->onThinking,
            ))->invoke($loop);

            return new QueryResult(
                text: $result->text,
                usage: $result->usage,
                cost: $result->cost,
                sessionId: $ephemeral ? null : $result->sessionId,
                turnsUsed: $result->turnsUsed,
            );
        } catch (HumanInterruptException $e) {
            $run->preserveSandboxOnClose();
            throw $e;
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
        $ephemeral = $options->effectiveEphemeral($agent);

        $run = self::createRun($agent, $options);
        $loop = $run->loop;
        $fiber = null;
        $autoDecisionHandlerRegistered = false;
        $thrownException = null;
        try {
            $queue = new \SplQueue;

            $userInput = $options->images !== []
                ? ImageContentBlock::buildUserContent($prompt, $options->images, $options->cwd)
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
            $autoDecisionHandlerRegistered = true;

            $invocationResult = null;

            $fiber = new \Fiber(function () use ($loop, $userInput, $onText, $onToolStart, $onToolComplete, $onTurnStart, $options, &$invocationResult, &$thrownException): void {
                try {
                    $invocationResult = (new AgentInvocation(
                        input: $userInput,
                        onTextDelta: $onText,
                        onToolStart: $onToolStart,
                        onToolComplete: $onToolComplete,
                        onTurnStart: $onTurnStart,
                        onThinkingDelta: $options->onThinking,
                    ))->invoke($loop);
                } catch (\Throwable $e) {
                    $thrownException = $e;
                }
            });

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
                $run->preserveSandboxOnClose();
                self::releaseTerminalStreamResources($run, $loop, $autoDecisionHandlerRegistered);
                yield Message::interrupt($thrownException->interrupt);

                return;
            }
            if ($thrownException !== null) {
                self::releaseTerminalStreamResources($run, $loop, $autoDecisionHandlerRegistered);
                yield Message::error($thrownException->getMessage());

                return;
            }

            self::releaseTerminalStreamResources($run, $loop, $autoDecisionHandlerRegistered);
            yield Message::result(
                text: $invocationResult?->text ?? '',
                usage: $invocationResult?->usage ?? [],
                cost: $invocationResult?->cost ?? 0.0,
                sessionId: $ephemeral ? null : $invocationResult?->sessionId,
            );
        } finally {
            if ($fiber instanceof \Fiber && $fiber->isStarted() && ! $fiber->isTerminated()) {
                $loop->abort();
                // Abandonment is cancellation. Do not resume model/tool work
                // after the caller stopped consuming the stream. Dropping the
                // suspended fiber also works on PHP 8.1-8.3, where switching
                // fibers from a Generator destructor is forbidden.
                $fiber = null;
            }
            if ($thrownException instanceof HumanInterruptException) {
                $run->preserveSandboxOnClose();
            }
            if ($autoDecisionHandlerRegistered) {
                $loop->setAutoDecisionHandler(null);
            }
            $run->close();
        }
    }

    /**
     * A one-shot stream owns its SDK run. Once it has produced a terminal
     * message, the run must not remain open merely because the caller retains
     * the Generator at that final yield.
     */
    private static function releaseTerminalStreamResources(
        SdkRun $run,
        AgentLoop $loop,
        bool &$autoDecisionHandlerRegistered,
    ): void {
        if ($autoDecisionHandlerRegistered) {
            $loop->setAutoDecisionHandler(null);
            $autoDecisionHandlerRegistered = false;
        }

        $run->close();
    }

    private static function createRun(Agent $agent, RunOptions $options): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

        return SdkRunFactory::createFromAgent($agent, $options, $factory);
    }

}
