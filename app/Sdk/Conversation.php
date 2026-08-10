<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
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

    private bool $operationActive = false;

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
                $response = $this->loop->run(
                    userInput: $userInput,
                    onTextDelta: $this->options->onText,
                    onToolStart: $this->options->onToolStart,
                    onToolComplete: $this->options->onToolComplete,
                    onTurnStart: $this->options->onTurnStart,
                    onThinkingDelta: $this->options->onThinking,
                );
            } catch (HumanInterruptException $exception) {
                $this->run->preserveSandboxOnClose();

                throw $exception;
            } finally {
                if (! $toolsEnabled) {
                    $this->loop->forceNoTools(false);
                }
            }

            return new QueryResult(
                text: $response,
                usage: self::extractUsage($this->loop),
                cost: $this->loop->getEstimatedCost(),
                sessionId: $this->options->ephemeral ? null : $this->loop->getSessionManager()->getSessionId(),
                // Per-operation Agent loop turns (not cumulative conversation sends).
                turnsUsed: $this->loop->getLastRunTurns(),
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

            $response = null;

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
                $this->run->preserveSandboxOnClose();
                yield Message::interrupt($thrownException->interrupt);

                return;
            }
            if ($thrownException !== null) {
                yield Message::error($thrownException->getMessage());

                return;
            }

            yield Message::result(
                text: $response ?? '',
                usage: self::extractUsage($this->loop),
                cost: $this->loop->getEstimatedCost(),
                sessionId: $this->options->ephemeral ? null : $this->loop->getSessionManager()->getSessionId(),
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
            $this->endOperation();
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
                $result = HaoCode::resumeInterrupt($sessionId, $interruptId, $decisions, $this->config);
                $queryResult = $result instanceof StructuredResult ? $result->queryResult : $result;
                if (! $queryResult instanceof QueryResult) {
                    throw new \RuntimeException(
                        'Conversation interrupt resume returned a structured result without its query metadata.',
                    );
                }
                $this->reloadAfterSnapshotResume($sessionId, $queryResult->usage, $queryResult->cost);

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

    /**
     * Streaming counterpart of {@see resumeInterrupt()}.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @return \Generator<int, Message>
     * @api
     */
    public function streamResumeInterrupt(string $interruptId, array $decisions): \Generator
    {
        $this->beginOperation();
        $fiber = null;
        $autoDecisionHandlerRegistered = false;
        $thrown = null;

        try {
            if (! $this->snapshotRestored) {
                $sessionId = $this->loop->getSessionManager()->getSessionId();
                foreach (HaoCode::streamResumeInterrupt(
                    $sessionId,
                    $interruptId,
                    $decisions,
                    $this->config,
                ) as $message) {
                    if ($message->isResult()) {
                        // A caller is allowed to stop consuming as soon as it
                        // receives the terminal result. Rebuild before yielding
                        // it so the next Conversation operation never depends
                        // on the generator being advanced one final time.
                        $this->reloadAfterSnapshotResume(
                            $sessionId,
                            $message->usage,
                            $message->cost,
                        );
                    }
                    yield $message;
                }

                return;
            }

            $queue = new \SplQueue;
            $this->loop->setAutoDecisionHandler(function (Message $message) use ($queue): void {
                $queue->enqueue($message);
                \Fiber::getCurrent()?->suspend();
            });
            $autoDecisionHandlerRegistered = true;
            $response = null;
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
                if (! $fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
            while (! $queue->isEmpty()) {
                yield $queue->dequeue();
            }
            if ($thrown instanceof HumanInterruptException) {
                $this->run->preserveSandboxOnClose();
                yield Message::interrupt($thrown->interrupt);

                return;
            }
            if ($thrown !== null) {
                yield Message::error($thrown->getMessage());

                return;
            }
            yield Message::result(
                $response ?? '',
                self::extractUsage($this->loop),
                $this->loop->getEstimatedCost(),
                $this->loop->getSessionManager()->getSessionId(),
            );
        } finally {
            if ($fiber instanceof \Fiber && $fiber->isStarted() && ! $fiber->isTerminated()) {
                $this->loop->abort();
                $fiber = null;
            }
            if ($thrown instanceof HumanInterruptException) {
                $this->run->preserveSandboxOnClose();
            }
            if ($autoDecisionHandlerRegistered) {
                $this->loop->setAutoDecisionHandler(null);
            }
            $this->endOperation();
        }
    }

    /**
     * @param array<string, mixed>|null $resumedUsage
     */
    private function reloadAfterSnapshotResume(
        string $sessionId,
        ?array $resumedUsage = null,
        ?float $resumedCost = null,
    ): void
    {
        $budgetLedger = $this->loop->getBudgetLedger();
        $resumedUsage ??= [];
        // Preserve lifetime usage from the independently restored loop as well
        // as this live handle. The shared budget ledger already advances cost,
        // but token accumulators are process-local and must be explicitly
        // seeded or the next send() reports a lower cumulative total.
        $priorUsage = [
            'total_input_tokens' => max(
                $this->loop->getTotalInputTokens(),
                self::usageCount($resumedUsage, 'input_tokens'),
            ),
            'total_output_tokens' => max(
                $this->loop->getTotalOutputTokens(),
                self::usageCount($resumedUsage, 'output_tokens'),
            ),
            'total_cache_creation_tokens' => max(
                $this->loop->getCacheCreationTokens(),
                self::usageCount($resumedUsage, 'cache_creation_tokens'),
            ),
            'total_cache_read_tokens' => max(
                $this->loop->getCacheReadTokens(),
                self::usageCount($resumedUsage, 'cache_read_tokens'),
            ),
            'last_turn_input_tokens' => array_key_exists('last_turn_input_tokens', $resumedUsage)
                ? self::usageCount($resumedUsage, 'last_turn_input_tokens')
                : $this->loop->getLastTurnInputTokens(),
            'estimated_cost_usd' => max(
                $this->loop->getEstimatedCost(),
                $resumedCost !== null && is_finite($resumedCost) ? max(0.0, $resumedCost) : 0.0,
            ),
        ];

        // Prefer the live run context (worktree / snapshot resume), then the
        // session transcript, then the original RunOptions. Rebuilding only
        // from agent+options can fall back to getcwd() and lose the original
        // session working directory on the next send().
        $liveCwd = $this->loop->getCurrentWorkingDirectory();
        $liveProject = $this->loop->getRunContext()?->projectDirectory;
        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $sessionCwd = $sessionManager->getSessionCanonicalCwd($sessionId);
        $cwd = $liveCwd
            ?? ((is_string($sessionCwd) && $sessionCwd !== '') ? $sessionCwd : null)
            ?? $this->options->cwd;
        $projectDirectory = $liveProject
            ?? ((is_string($sessionCwd) && $sessionCwd !== '') ? $sessionCwd : null)
            ?? $this->options->cwd;

        $resumeSnapshot = array_filter(
            [
                'cwd' => $cwd,
                'project_directory' => $projectDirectory,
                'worktree_path' => $this->loop->getRunContext()?->worktreePath,
                'worktree_branch' => $this->loop->getRunContext()?->worktreeBranch,
                'managed_worktree' => $this->loop->getRunContext()?->managedWorktree ?? false,
                'background_owner_agent_id' => $this->loop->getRunContext()?->backgroundOwnerAgentId,
                'omit_project_instructions' => $this->loop->getRunContext()?->omitProjectInstructions ?? false,
                'agent_type' => $this->loop->getRunContext()?->agentType,
                'read_only' => $this->loop->getRunContext()?->readOnly ?? false,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $this->run->close();
        $this->run = SdkRunFactory::createFromAgent(
            $this->agent,
            $this->options,
            $this->factory,
            $this->streamingClient,
            resumeSnapshot: $resumeSnapshot === [] ? null : $resumeSnapshot,
            budgetLedger: $budgetLedger,
        );
        $this->loop = $this->run->loop;
        $this->loop->restoreRunSnapshot($priorUsage);
        $this->snapshotRestored = false;
        $this->loadSessionInternal($sessionId);
    }

    /** @param array<string, mixed> $usage */
    private static function usageCount(array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @return array<string, int|bool> */
    private static function extractUsage(AgentLoop $loop): array
    {
        return [
            'input_tokens' => $loop->getTotalInputTokens(),
            'output_tokens' => $loop->getTotalOutputTokens(),
            'cache_creation_tokens' => $loop->getCacheCreationTokens(),
            'cache_read_tokens' => $loop->getCacheReadTokens(),
            'last_turn_input_tokens' => $loop->getLastTurnInputTokens(),
            'cost_available' => $loop->isCostEstimateAvailable(),
        ];
    }

    /**
     * Load a previous session's message history into this conversation.
     *
     * Existing in-memory history is replaced only after the requested session
     * has been loaded and reconstructed successfully.
     *
     * @api
     */
    public function loadSession(string $sessionId): void
    {
        $this->beginOperation();
        try {
            $this->loadSessionInternal($sessionId);
        } finally {
            $this->endOperation();
        }
    }

    private function loadSessionInternal(string $sessionId): void
    {
        $history = $this->loop->getMessageHistory();

        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $entries = $sessionManager->loadSession($sessionId);

        if ($entries === null || $entries === []) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        $loadedHistory = new MessageHistory;
        $loadedPendingAssistants = [];

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? null;

            if ($type === 'user_message') {
                $loadedHistory->addUserMessage($entry['content'] ?? '');
            } elseif ($type === 'assistant_turn' && isset($entry['message'])) {
                $loadedHistory->addAssistantMessage($entry['message']);
                if (! empty($entry['tool_results'])) {
                    $loadedHistory->addToolResultMessage($entry['tool_results']);
                }
            } elseif ($type === 'interrupt_pending' && isset($entry['checkpoint']['assistant_message'])) {
                $interruptId = (string) ($entry['interrupt']['id'] ?? '');
                if ($interruptId !== '' && isset($loadedPendingAssistants[$interruptId])) {
                    continue;
                }
                $loadedHistory->addAssistantMessage($entry['checkpoint']['assistant_message']);
                if ($interruptId !== '') {
                    $loadedPendingAssistants[$interruptId] = true;
                }
            } elseif (in_array($type, ['interrupt_resolved', 'interrupt_cancelled'], true)
                && ! empty($entry['tool_results'])) {
                $loadedHistory->addToolResultMessage($entry['tool_results']);
            }
        }

        // Point session manager at the loaded session. Use the canonical id
        // that loadSession resolved (it may differ from $sessionId when the
        // caller passed a partial prefix). Switching to the canonical id
        // keeps subsequent reads and writes on the same file (chatgpt #9:
        // previously a partial id read A but wrote to B).
        $canonicalId = $sessionManager->getLastResolvedSessionId() ?? $sessionId;
        $this->loop->getSessionManager()->switchToSession($canonicalId);
        $history->replaceMessages($loadedHistory->getMessages());
        $this->loop->markSessionResumed();
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
     * Keep the sandbox filesystem when closing after a durable HITL interrupt.
     *
     * @internal
     */
    public function preserveSandboxOnClose(): void
    {
        $this->run->preserveSandboxOnClose();
    }

    private function beginOperation(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }
        if ($this->operationActive) {
            throw new \RuntimeException('Another conversation operation is already in progress.');
        }

        $this->operationActive = true;
    }

    private function endOperation(): void
    {
        $this->operationActive = false;
    }

    /**
     * @api
     */
    public function close(): void
    {
        if ($this->operationActive) {
            throw new \RuntimeException(
                'Cannot close a conversation while an operation is already in progress.',
            );
        }

        try {
            $this->run->close();
        } finally {
            // SdkRun closes itself in finally even when sandbox/MCP cleanup
            // reports an error; Conversation must mirror that terminal state.
            $this->closed = true;
        }
    }
}
