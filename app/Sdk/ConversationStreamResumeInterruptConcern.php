<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;

trait ConversationStreamResumeInterruptConcern
{

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
        $operationReleased = false;

        try {
            if (! $this->snapshotRestored) {
                $sessionId = $this->loop->getSessionManager()->getSessionId();
                $currentSandboxLease = $this->run->getSandboxLease();
                $sandboxLease = $this->interruptSandboxLease($sessionId, $interruptId)
                    ?? $currentSandboxLease;
                $preserveCurrentSandboxOnInterrupt = self::sameSandboxLease(
                    $currentSandboxLease,
                    $sandboxLease,
                );
                foreach (HaoCode::streamResumeInterrupt(
                    $sessionId,
                    $interruptId,
                    $decisions,
                    $this->configWithSandboxLease($sandboxLease, retainUntilRebuilt: true),
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
                            $sandboxLease,
                        );
                        $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                    } elseif ($message->isInterrupt()) {
                        if ($preserveCurrentSandboxOnInterrupt) {
                            $this->run->preserveSandboxOnClose();
                        }
                        $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                    } elseif ($message->isError()) {
                        $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                    }
                    yield $message;
                    if ($message->isResult() || $message->isInterrupt() || $message->isError()) {
                        return;
                    }
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
                $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                yield Message::interrupt($thrown->interrupt);

                return;
            }
            if ($thrown !== null) {
                $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
                yield Message::error($thrown->getMessage());

                return;
            }
            $this->releaseTerminalStreamOperation($autoDecisionHandlerRegistered, $operationReleased);
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
            if (! $operationReleased) {
                $this->endOperation();
            }
        }
    }

    /**
     * @param array<string, mixed>|null $resumedUsage
     */
    private function reloadAfterSnapshotResume(
        string $sessionId,
        ?array $resumedUsage = null,
        ?float $resumedCost = null,
        ?array $sandboxLease = null,
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
                'context_preset' => $this->loop->getRunContext()?->contextPreset,
                'read_only' => $this->loop->getRunContext()?->readOnly ?? false,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $runConfig = $this->configWithSandboxLease($sandboxLease);
        $this->run->close();
        $this->run = SdkRunFactory::create(
            $runConfig,
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

    /**
     * A Conversation loaded through HaoCode::resume() owns a fresh runtime
     * sandbox. The pending interrupt checkpoint remains authoritative for the
     * resumed operation and any follow-up on this handle.
     *
     * @return array<string, mixed>|null
     */
    private function interruptSandboxLease(string $sessionId, string $interruptId): ?array
    {
        $state = $this->loop->getSessionManager()->getInterruptState($sessionId, $interruptId);
        $checkpoint = is_array($state['checkpoint'] ?? null) ? $state['checkpoint'] : [];
        $runSnapshot = is_array($checkpoint['run_snapshot'] ?? null) ? $checkpoint['run_snapshot'] : [];
        $lease = $runSnapshot['sandbox_lease'] ?? null;

        return is_array($lease) ? $lease : null;
    }

    /**
     * The outer Conversation owns a sandbox only when it has the durable
     * checkpoint identity. A handle loaded with HaoCode::resume() has a fresh
     * sandbox of its own, which must still be cleaned after a re-interrupt.
     *
     * @param array<string, mixed>|null $first
     * @param array<string, mixed>|null $second
     */
    private static function sameSandboxLease(?array $first, ?array $second): bool
    {
        if ($first === null || $second === null) {
            return false;
        }

        $firstProvider = is_string($first['provider'] ?? null) ? $first['provider'] : null;
        $secondProvider = is_string($second['provider'] ?? null) ? $second['provider'] : null;
        if ($firstProvider === null
            || ($secondProvider !== null && $secondProvider !== $firstProvider)) {
            return false;
        }

        $identityKey = match ($firstProvider) {
            'agentrun' => 'sandbox_id',
            'tokimo' => 'vm_dir',
            default => 'root',
        };
        $legacyOptionKey = match ($firstProvider) {
            'agentrun' => 'sandboxId',
            'tokimo' => 'vmDir',
            default => null,
        };
        $firstIdentity = is_array($first['identity'] ?? null) ? $first['identity'] : [];
        $secondIdentity = is_array($second['identity'] ?? null) ? $second['identity'] : [];
        $firstOptions = is_array($first['options'] ?? null) ? $first['options'] : [];
        $secondOptions = is_array($second['options'] ?? null) ? $second['options'] : [];
        $firstValue = $firstIdentity[$identityKey]
            ?? $first[$identityKey]
            ?? ($legacyOptionKey !== null ? $firstOptions[$legacyOptionKey] ?? null : null);
        $secondValue = $secondIdentity[$identityKey]
            ?? $second[$identityKey]
            ?? ($legacyOptionKey !== null ? $secondOptions[$legacyOptionKey] ?? null : null);

        return is_string($firstValue) && $firstValue !== ''
            && is_string($secondValue) && $secondValue !== ''
            && $firstValue === $secondValue;
    }

    /**
     * A facade-based durable resume uses a temporary Conversation. Keep its
     * reattached sandbox alive until this long-lived handle has claimed the
     * same lease, then restore the caller's cleanup policy on the replacement
     * run. Without this handoff, a follow-up after resume starts in a fresh
     * sandbox and loses files created before the interrupt.
     *
     * @param array<string, mixed>|null $sandboxLease
     */
    private function configWithSandboxLease(?array $sandboxLease, bool $retainUntilRebuilt = false): HaoCodeConfig
    {
        if ($sandboxLease === null) {
            return $this->config;
        }

        $sandbox = \HaoCode\Sdk\Sandbox\SandboxRuntime::configFromLease(
            $sandboxLease,
            $this->config->sandbox,
        );
        if ($retainUntilRebuilt) {
            $sandbox = new \HaoCode\Sdk\Sandbox\SandboxConfig(
                provider: $sandbox->provider,
                mode: $sandbox->mode,
                remoteCwd: $sandbox->remoteCwd,
                sync: $sandbox->sync,
                cleanup: 'never',
                root: $sandbox->root,
                exclude: $sandbox->exclude,
                options: $sandbox->options,
            );
        }

        $values = get_object_vars($this->config);
        $values['sandbox'] = $sandbox;

        return new HaoCodeConfig(...$values);
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
}
