<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentInvocation;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Sdk\Internal\FiberMessageStream;
use HaoCode\Sdk\Internal\ConversationBootstrap;

trait ConversationConstructConcern
{

    /**
     * @internal
     */
    public function __construct(
        private readonly HaoCodeConfig $config,
        private readonly AgentLoopFactory $factory,
        private readonly ?StreamingClient $streamingClient = null,
        ?ConversationBootstrap $bootstrap = null,
    ) {
        $this->agent = Agent::fromConfig($config);
        $this->options = RunOptions::fromConfig($config);
        $resumeSnapshot = $bootstrap?->resumeSnapshot;
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
        $stream = null;
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
                terminationReason: $result->terminationReason,
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
        $operationReleased = false;
        $release = function () use (&$operationReleased): void {
            if (! $operationReleased) {
                $this->endOperation();
                $operationReleased = true;
            }
        };

        try {
            $this->turnCount++;

            $userInput = $images !== []
                ? ImageContentBlock::buildUserContent($prompt, $images, $this->config->cwd)
                : $prompt;

            $stream = new FiberMessageStream(
                loop: $this->loop,
                operation: function (
                    callable $onText,
                    callable $onToolStart,
                    callable $onToolComplete,
                    callable $onTurnStart,
                ) use ($userInput) {
                    return (new AgentInvocation(
                        input: $userInput,
                        onTextDelta: $onText,
                        onToolStart: $onToolStart,
                        onToolComplete: $onToolComplete,
                        onTurnStart: $onTurnStart,
                        onThinkingDelta: $this->options->onThinking,
                    ))->invoke($this->loop);
                },
                terminalMessage: fn ($result): Message => Message::result(
                    text: $result->text,
                    usage: $result->usage,
                    cost: $result->cost,
                    sessionId: $this->options->ephemeral ? null : $result->sessionId,
                    terminationReason: $result->terminationReason,
                ),
                release: $release,
                preserveInterrupt: fn () => $this->run->preserveSandboxOnClose(),
                onText: $this->options->onText,
                onToolStart: $this->options->onToolStart,
                onToolComplete: $this->options->onToolComplete,
                onTurnStart: $this->options->onTurnStart,
            );

            while (($message = $stream->nextMessage()) !== null) {
                yield $message;
            }
        } finally {
            $stream?->abandon();
            $release();
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
                $outcome = $this->loop->resumeInterruptOutcome(
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
                text: $outcome->text,
                usage: self::extractUsage($this->loop),
                cost: $this->loop->getEstimatedCost(),
                sessionId: $this->loop->getSessionManager()->getSessionId(),
                turnsUsed: $this->loop->getLastRunTurns(),
                terminationReason: $outcome->terminationReason,
            );
        } finally {
            $this->endOperation();
        }
    }
}
