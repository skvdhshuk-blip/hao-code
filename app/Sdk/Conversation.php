<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;

/**
 * Multi-turn conversation handle.
 *
 * Maintains a persistent AgentLoop so subsequent send() calls
 * share the same message history and session.
 *
 * @api
 */
class Conversation
{
    private AgentLoop $loop;

    private bool $closed = false;

    private int $turnCount = 0;

    private SdkRun $run;

    private bool $snapshotRestored = false;

    /**
     * The agent definition backing this conversation, normalized from the
     * constructor config via {@see Agent::fromConfig()}. Everything that
     * defines the agent (model, tools, prompts, permissions, sandbox,
     * headers) is owned by this object; session/resume concerns stay on
     * the Conversation itself.
     */
    private readonly Agent $agent;

    /**
     * Per-run execution options (callbacks, persistence, budget, cwd),
     * derived from the same constructor config.
     */
    private readonly RunOptions $options;

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
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        $userInput = $images !== []
            ? ImageContentBlock::buildUserContent($prompt, $images)
            : $prompt;

        $response = $this->loop->run(
            userInput: $userInput,
            onTextDelta: $this->options->onText,
            onToolStart: $this->options->onToolStart,
            onToolComplete: $this->options->onToolComplete,
            onTurnStart: $this->options->onTurnStart,
            onThinkingDelta: $this->options->onThinking,
        );

        return new QueryResult(
            text: $response,
            usage: [
                'input_tokens' => $this->loop->getTotalInputTokens(),
                'output_tokens' => $this->loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                'cache_read_tokens' => $this->loop->getCacheReadTokens(),
                'cost_available' => $this->loop->isCostEstimateAvailable(),
            ],
            cost: $this->loop->getEstimatedCost(),
            sessionId: $this->options->ephemeral ? null : $this->loop->getSessionManager()->getSessionId(),
            turnsUsed: $this->turnCount,
        );
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
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        $userInput = $images !== []
            ? ImageContentBlock::buildUserContent($prompt, $images)
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

        $response = null;
        $thrownException = null;

        $fiber = new \Fiber(function () use ($userInput, $onText, $onToolStart, $onToolComplete, $onTurnStart, &$response, &$thrownException): void {
            try {
                $response = $this->loop->run(
                    userInput: $userInput,
                    onTextDelta: $onText,
                    onToolStart: $onToolStart,
                    onToolComplete: $onToolComplete,
                    onTurnStart: $onTurnStart,
                    onThinkingDelta: $this->options->onThinking,
                );
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
            yield Message::interrupt($thrownException->interrupt);

            return;
        }
        if ($thrownException !== null) {
            yield Message::error($thrownException->getMessage());

            return;
        }

        yield Message::result(
            text: $response ?? '',
            usage: [
                'input_tokens' => $this->loop->getTotalInputTokens(),
                'output_tokens' => $this->loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                'cache_read_tokens' => $this->loop->getCacheReadTokens(),
                'cost_available' => $this->loop->isCostEstimateAvailable(),
            ],
            cost: $this->loop->getEstimatedCost(),
            sessionId: $this->options->ephemeral ? null : $this->loop->getSessionManager()->getSessionId(),
        );
    }

    /**
     * Resolve an interrupt and continue this conversation.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @api
     */
    public function resumeInterrupt(string $interruptId, array $decisions): QueryResult
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        if (! $this->snapshotRestored) {
            $sessionId = $this->loop->getSessionManager()->getSessionId();
            $result = HaoCode::resumeInterrupt($sessionId, $interruptId, $decisions, $this->config);
            $queryResult = $result instanceof StructuredResult ? $result->queryResult : $result;
            if (! $queryResult instanceof QueryResult) {
                throw new \RuntimeException(
                    'Conversation interrupt resume returned a structured result without its query metadata.',
                );
            }
            $this->reloadAfterSnapshotResume($sessionId);

            return $queryResult;
        }

        $response = $this->loop->resumeInterrupt(
            interruptId: $interruptId,
            decisions: $decisions,
            onTextDelta: $this->options->onText,
            onToolStart: $this->options->onToolStart,
            onToolComplete: $this->options->onToolComplete,
            onTurnStart: $this->options->onTurnStart,
            onThinkingDelta: $this->options->onThinking,
        );

        return new QueryResult(
            text: $response,
            usage: [
                'input_tokens' => $this->loop->getTotalInputTokens(),
                'output_tokens' => $this->loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                'cache_read_tokens' => $this->loop->getCacheReadTokens(),
                'cost_available' => $this->loop->isCostEstimateAvailable(),
            ],
            cost: $this->loop->getEstimatedCost(),
            sessionId: $this->loop->getSessionManager()->getSessionId(),
            turnsUsed: $this->turnCount,
        );
    }

    /**
     * Streaming counterpart of {@see resumeInterrupt()}.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @return \Generator<int, Message>
     * @api
     */
    public function streamResumeInterrupt(string $interruptId, array $decisions): \Generator
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        if (! $this->snapshotRestored) {
            $sessionId = $this->loop->getSessionManager()->getSessionId();
            $completed = false;
            foreach (HaoCode::streamResumeInterrupt(
                $sessionId,
                $interruptId,
                $decisions,
                $this->config,
            ) as $message) {
                if ($message->isResult()) {
                    $completed = true;
                }
                yield $message;
            }
            if ($completed) {
                $this->reloadAfterSnapshotResume($sessionId);
            }

            return;
        }

        $queue = new \SplQueue;
        $this->loop->setAutoDecisionHandler(function (Message $message) use ($queue): void {
            $queue->enqueue($message);
            \Fiber::getCurrent()?->suspend();
        });
        $response = null;
        $thrown = null;
        $fiber = new \Fiber(function () use ($interruptId, $decisions, $queue, &$response, &$thrown): void {
            try {
                $response = $this->loop->resumeInterrupt(
                    $interruptId,
                    $decisions,
                    function (string $delta) use ($queue): void {
                        $queue->enqueue(Message::text($delta));
                        if ($this->options->onText) {
                            ($this->options->onText)($delta);
                        }
                        \Fiber::getCurrent()?->suspend();
                    },
                    function (string $name, array $input) use ($queue): void {
                        $queue->enqueue(Message::toolStart($name, $input));
                        if ($this->options->onToolStart) {
                            ($this->options->onToolStart)($name, $input);
                        }
                        \Fiber::getCurrent()?->suspend();
                    },
                    function (string $name, $result) use ($queue): void {
                        $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
                        if ($this->options->onToolComplete) {
                            ($this->options->onToolComplete)($name, $result);
                        }
                        \Fiber::getCurrent()?->suspend();
                    },
                    function (int $turn) use ($queue): void {
                        $queue->enqueue(Message::turn($turn));
                        if ($this->options->onTurnStart) {
                            ($this->options->onTurnStart)($turn);
                        }
                        \Fiber::getCurrent()?->suspend();
                    },
                    $this->options->onThinking,
                );
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });
        $fiber->start();
        while (! $fiber->isTerminated()) {
            while (! $queue->isEmpty()) {
                yield $queue->dequeue();
            }
            $fiber->resume();
        }
        while (! $queue->isEmpty()) {
            yield $queue->dequeue();
        }
        if ($thrown instanceof HumanInterruptException) {
            yield Message::interrupt($thrown->interrupt);
            return;
        }
        if ($thrown !== null) {
            yield Message::error($thrown->getMessage());
            return;
        }
        yield Message::result($response ?? '', [
            'input_tokens' => $this->loop->getTotalInputTokens(),
            'output_tokens' => $this->loop->getTotalOutputTokens(),
            'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
            'cache_read_tokens' => $this->loop->getCacheReadTokens(),
            'cost_available' => $this->loop->isCostEstimateAvailable(),
        ], $this->loop->getEstimatedCost(), $this->loop->getSessionManager()->getSessionId());
    }

    private function reloadAfterSnapshotResume(string $sessionId): void
    {
        $budgetLedger = $this->loop->getBudgetLedger();
        // Preserve lifetime usage so QueryResult tokens stay aligned with
        // the shared cumulative cost after the loop is rebuilt.
        $priorUsage = [
            'total_input_tokens' => $this->loop->getTotalInputTokens(),
            'total_output_tokens' => $this->loop->getTotalOutputTokens(),
            'total_cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
            'total_cache_read_tokens' => $this->loop->getCacheReadTokens(),
            'estimated_cost_usd' => $this->loop->getEstimatedCost(),
        ];
        $this->run->close();
        $this->run = SdkRunFactory::createFromAgent(
            $this->agent,
            $this->options,
            $this->factory,
            $this->streamingClient,
            budgetLedger: $budgetLedger,
        );
        $this->loop = $this->run->loop;
        $this->loop->restoreRunSnapshot($priorUsage);
        $this->snapshotRestored = false;
        $this->loadSession($sessionId);
    }

    /**
     * Load a previous session's message history into this conversation.
     *
     * @api
     */
    public function loadSession(string $sessionId): void
    {
        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $entries = $sessionManager->loadSession($sessionId);

        if ($entries === null || $entries === []) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        $history = $this->loop->getMessageHistory();
        $loadedPendingAssistants = [];

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? null;

            if ($type === 'user_message') {
                $history->addUserMessage($entry['content'] ?? '');
            } elseif ($type === 'assistant_turn' && isset($entry['message'])) {
                $history->addAssistantMessage($entry['message']);
                if (! empty($entry['tool_results'])) {
                    $history->addToolResultMessage($entry['tool_results']);
                }
            } elseif ($type === 'interrupt_pending' && isset($entry['checkpoint']['assistant_message'])) {
                $interruptId = (string) ($entry['interrupt']['id'] ?? '');
                if ($interruptId !== '' && isset($loadedPendingAssistants[$interruptId])) {
                    continue;
                }
                $history->addAssistantMessage($entry['checkpoint']['assistant_message']);
                if ($interruptId !== '') {
                    $loadedPendingAssistants[$interruptId] = true;
                }
            } elseif (in_array($type, ['interrupt_resolved', 'interrupt_cancelled'], true)
                && ! empty($entry['tool_results'])) {
                $history->addToolResultMessage($entry['tool_results']);
            }
        }

        // Point session manager at the loaded session. Use the canonical id
        // that loadSession resolved (it may differ from $sessionId when the
        // caller passed a partial prefix). Switching to the canonical id
        // keeps subsequent reads and writes on the same file (chatgpt #9:
        // previously a partial id read A but wrote to B).
        $canonicalId = $sessionManager->getLastResolvedSessionId() ?? $sessionId;
        $this->loop->getSessionManager()->switchToSession($canonicalId);
    }

    /**
     * @internal
     */
    public function getLoop(): AgentLoop
    {
        return $this->loop;
    }

    /**
     * @api
     */
    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    /**
     * @api
     */
    public function getSessionId(): ?string
    {
        return $this->loop->getSessionManager()->getSessionId();
    }

    /**
     * @api
     */
    public function getCost(): float
    {
        return $this->loop->getEstimatedCost();
    }

    /**
     * @api
     */
    public function abort(): void
    {
        $this->loop->abort();
    }

    /**
     * @api
     */
    public function close(): void
    {
        $this->run->close();
        $this->closed = true;
    }
}
