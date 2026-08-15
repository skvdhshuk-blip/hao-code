<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentInvocation;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;

trait ConversationConstructConcern
{

    /**
     * @internal
     */
    public function __construct(
        private readonly HaoCodeConfig $config,
        private readonly AgentLoopFactory $factory,
        private readonly ?StreamingClient $streamingClient = null,
    ) {
        $this->agent = Agent::fromConfig($config);
        $this->options = RunOptions::fromConfig($config);
        $resumeSnapshot = SdkRunFactory::consumeResumeSnapshot($config);
        $this->snapshotRestored = $resumeSnapshot !== null;
        $this->run = SdkRunFactory::createFromAgent(
            $this->agent,
            $this->options,
            $factory,
            $streamingClient,
            $resumeSnapshot,
        );
        $this->loop = $this->run->loop;
    }

    /**
     * Send a message and get the agent's response as a QueryResult.
     *
     * @api
     */
    public function send(string $prompt, array $images = []): QueryResult
    {
        return $this->sendInternal($prompt, $images, toolsEnabled: true);
    }

    /**
     * Structured JSON correction: same conversation, but no tools are advertised.
     *
     * @internal
     */
    public function sendWithoutTools(string $prompt): QueryResult
    {
        return $this->sendInternal($prompt, [], toolsEnabled: false);
    }

    /**
     * @param  list<string|array<string, mixed>>  $images
     */
    private function sendInternal(string $prompt, array $images, bool $toolsEnabled): QueryResult
    {
        $this->beginOperation();
        try {
            $this->turnCount++;

            $userInput = $images !== []
                ? ImageContentBlock::buildUserContent($prompt, $images, $this->config->cwd)
                : $prompt;

            if (! $toolsEnabled) {
                $this->loop->forceNoTools(true);
            }
            try {
                $result = (new AgentInvocation(
                    input: $userInput,
                    onTextDelta: $this->options->onText,
                    onToolStart: $this->options->onToolStart,
                    onToolComplete: $this->options->onToolComplete,
                    onTurnStart: $this->options->onTurnStart,
                    onThinkingDelta: $this->options->onThinking,
                ))->invoke($this->loop);
            } catch (HumanInterruptException $exception) {
                $this->run->preserveSandboxOnClose();

                throw $exception;
            } finally {
                if (! $toolsEnabled) {
                    $this->loop->forceNoTools(false);
                }
            }

            return new QueryResult(
                text: $result->text,
                usage: $result->usage,
                cost: $result->cost,
                sessionId: $this->options->ephemeral ? null : $result->sessionId,
                // Per-operation Agent loop turns (not cumulative conversation sends).
                turnsUsed: $result->turnsUsed,
            );
        } finally {
            $this->endOperation();
        }
    }

    /**
     * Send a message and yield streaming Message objects in real time.
     *
     * Uses a PHP Fiber so each text delta / tool event is yielded to the caller
     * as it arrives from the API, rather than being buffered until the full
     * response completes.
     *
     * @api
     *
     * @return \Generator<int, Message>
     */
    public function stream(string $prompt, array $images = []): \Generator
    {
        $this->beginOperation();
        $fiber = null;
        $autoDecisionHandlerRegistered = false;
        $thrownException = null;
        $operationReleased = false;

        try {
            $this->turnCount++;

            $userInput = $images !== []
                ? ImageContentBlock::buildUserContent($prompt, $images, $this->config->cwd)
                : $prompt;

            $queue = new \SplQueue;

            // These callbacks are exclusively invoked from within the Fiber below.
            // Fiber::getCurrent()?->suspend() uses the nullable operator as a defensive
            // guard; in practice getCurrent() will always return the active Fiber here.
            $onText = function (string $delta) use ($queue): void {
                $queue->enqueue(Message::text($delta));
                if ($this->options->onText) {
                    ($this->options->onText)($delta);
                }
                \Fiber::getCurrent()?->suspend();
            };

            $onToolStart = function (string $name, array $input) use ($queue): void {
                $queue->enqueue(Message::toolStart($name, $input));
                if ($this->options->onToolStart) {
                    ($this->options->onToolStart)($name, $input);
                }
                \Fiber::getCurrent()?->suspend();
            };

            $onToolComplete = function (string $name, $result) use ($queue): void {
                $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
                if ($this->options->onToolComplete) {
                    ($this->options->onToolComplete)($name, $result);
                }
                \Fiber::getCurrent()?->suspend();
            };

            $onTurnStart = function (int $turn) use ($queue): void {
                $queue->enqueue(Message::turn($turn));
                if ($this->options->onTurnStart) {
                    ($this->options->onTurnStart)($turn);
                }
                \Fiber::getCurrent()?->suspend();
            };

            // Smart/auto HITL decision events flow through the same fiber queue.
            $this->loop->setAutoDecisionHandler(function (Message $message) use ($queue): void {
                $queue->enqueue($message);
                \Fiber::getCurrent()?->suspend();
            });
            $autoDecisionHandlerRegistered = true;

            $invocationResult = null;

            $fiber = new \Fiber(function () use ($userInput, $onText, $onToolStart, $onToolComplete, $onTurnStart, &$invocationResult, &$thrownException): void {
                try {
                    $invocationResult = (new AgentInvocation(
                        input: $userInput,
                        onTextDelta: $onText,
                        onToolStart: $onToolStart,
                        onToolComplete: $onToolComplete,
                        onTurnStart: $onTurnStart,
                        onThinkingDelta: $this->options->onThinking,
                    ))->invoke($this->loop);
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

            // Drain any messages enqueued before the fiber's final termination
            while (! $queue->isEmpty()) {
                yield $queue->dequeue();
            }

            if ($thrownException instanceof HumanInterruptException) {
                $this->run->preserveSandboxOnClose();
                $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                yield Message::interrupt($thrownException->interrupt);

                return;
            }
            if ($thrownException !== null) {
                $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                yield Message::error($thrownException->getMessage());

                return;
            }

            $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
            yield Message::result(
                text: $invocationResult?->text ?? '',
                usage: $invocationResult?->usage ?? [],
                cost: $invocationResult?->cost ?? 0.0,
                sessionId: $this->options->ephemeral ? null : $invocationResult?->sessionId,
            );
        } finally {
            if ($fiber instanceof \Fiber && $fiber->isStarted() && ! $fiber->isTerminated()) {
                $this->loop->abort();
                // Abandonment is cancellation. Resuming here would continue
                // agent/tool work after the caller stopped consuming and is
                // forbidden from Generator destruction on PHP 8.1-8.3.
                $fiber = null;
            }
            if ($thrownException instanceof HumanInterruptException) {
                $this->run->preserveSandboxOnClose();
            }
            if ($autoDecisionHandlerRegistered) {
                $this->loop->setAutoDecisionHandler(null);
            }
            if (! $operationReleased) {
                $this->endOperation();
            }
        }
    }

    /**
     * Resolve an interrupt and continue this conversation.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @api
     */
    public function resumeInterrupt(string $interruptId, array $decisions): QueryResult
    {
        $this->beginOperation();

        try {
            if (! $this->snapshotRestored) {
                $sessionId = $this->loop->getSessionManager()->getSessionId();
                $sandboxLease = $this->interruptSandboxLease($sessionId, $interruptId)
                    ?? $this->run->getSandboxLease();
                $result = HaoCode::resumeInterrupt(
                    $sessionId,
                    $interruptId,
                    $decisions,
                    $this->configWithSandboxLease($sandboxLease, retainUntilRebuilt: true),
                );
                $queryResult = $result instanceof StructuredResult ? $result->queryResult : $result;
                if (! $queryResult instanceof QueryResult) {
                    throw new \RuntimeException(
                        'Conversation interrupt resume returned a structured result without its query metadata.',
                    );
                }
                $this->reloadAfterSnapshotResume(
                    $sessionId,
                    $queryResult->usage,
                    $queryResult->cost,
                    $sandboxLease,
                );

                return $queryResult;
            }

            try {
                $response = $this->loop->resumeInterrupt(
                    interruptId: $interruptId,
                    decisions: $decisions,
                    onTextDelta: $this->options->onText,
                    onToolStart: $this->options->onToolStart,
                    onToolComplete: $this->options->onToolComplete,
                    onTurnStart: $this->options->onTurnStart,
                    onThinkingDelta: $this->options->onThinking,
                );
            } catch (HumanInterruptException $exception) {
                $this->run->preserveSandboxOnClose();

                throw $exception;
            }

            return new QueryResult(
                text: $response,
                usage: self::extractUsage($this->loop),
                cost: $this->loop->getEstimatedCost(),
                sessionId: $this->loop->getSessionManager()->getSessionId(),
                turnsUsed: $this->loop->getLastRunTurns(),
            );
        } finally {
            $this->endOperation();
        }
    }
}
