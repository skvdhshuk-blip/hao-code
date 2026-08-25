<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentInvocation;
use HaoCode\Sdk\Internal\FiberMessageStream;
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
        $stream = null;

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
                terminationReason: $result->terminationReason,
            );
        } catch (HumanInterruptException $e) {
            $run->preserveSandboxOnClose();
            throw $e;
        } finally {
            $stream?->abandon();
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
        try {
            $userInput = $options->images !== []
                ? ImageContentBlock::buildUserContent($prompt, $options->images, $options->cwd)
                : $prompt;
            $stream = new FiberMessageStream(
                loop: $loop,
                operation: static function (
                    callable $onText,
                    callable $onToolStart,
                    callable $onToolComplete,
                    callable $onTurnStart,
                ) use ($loop, $userInput, $options) {
                    return (new AgentInvocation(
                        input: $userInput,
                        onTextDelta: $onText,
                        onToolStart: $onToolStart,
                        onToolComplete: $onToolComplete,
                        onTurnStart: $onTurnStart,
                        onThinkingDelta: $options->onThinking,
                    ))->invoke($loop);
                },
                terminalMessage: static fn ($result): Message => Message::result(
                    text: $result->text,
                    usage: $result->usage,
                    cost: $result->cost,
                    sessionId: $ephemeral ? null : $result->sessionId,
                    terminationReason: $result->terminationReason,
                ),
                release: static fn () => $run->close(),
                preserveInterrupt: static fn () => $run->preserveSandboxOnClose(),
                onText: $options->onText,
                onToolStart: $options->onToolStart,
                onToolComplete: $options->onToolComplete,
                onTurnStart: $options->onTurnStart,
            );

            while (($message = $stream->nextMessage()) !== null) {
                yield $message;
            }
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

}
