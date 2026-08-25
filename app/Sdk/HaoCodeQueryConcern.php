<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\HumanInterruptCoordinator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;

trait HaoCodeQueryConcern
{
    /**
     * Execute a one-shot query and return a QueryResult.
     *
     * QueryResult implements Stringable, so `echo HaoCode::query(...)` works.
     * But it also carries usage, cost, sessionId, and turnsUsed metadata.
     *
     * @api
     */
    public static function query(string $prompt, ?HaoCodeConfig $config = null): QueryResult
    {
        $config ??= new HaoCodeConfig(allowedTools: [], ephemeral: true);

        // Redirect to resume/continue if configured
        if ($config->sessionId !== null) {
            $conv = self::resume($config->sessionId, $config);
            try {
                return $conv->send($prompt, $config->images);
            } catch (HumanInterruptException $e) {
                $conv->preserveSandboxOnClose();
                throw $e;
            } finally {
                $conv->close();
            }
        }
        if ($config->continueSession) {
            $conv = self::continueLatest($config->cwd, $config);
            try {
                return $conv->send($prompt, $config->images);
            } catch (HumanInterruptException $e) {
                $conv->preserveSandboxOnClose();
                throw $e;
            } finally {
                $conv->close();
            }
        }

        $agent = Agent::fromConfig($config);
        $options = RunOptions::fromConfig($config);

        return Runner::run($agent, $prompt, $options);
    }

    /**
     * Execute a query and yield streaming Message objects in real time.
     *
     * Uses a PHP Fiber so each text delta / tool event is yielded to the caller
     * as it arrives from the API, rather than being buffered until the full
     * response completes.
     *
     * @api
     *
     * @return \Generator<int, Message>
     */
    public static function stream(string $prompt, ?HaoCodeConfig $config = null): \Generator
    {
        $config ??= new HaoCodeConfig(allowedTools: [], ephemeral: true);

        // Redirect to conversation stream if resuming
        if ($config->sessionId !== null) {
            $conversation = self::resume($config->sessionId, $config);
            yield from self::streamTemporaryConversation($conversation, $prompt, $config->images);

            return;
        }
        if ($config->continueSession) {
            $conversation = self::continueLatest($config->cwd, $config);
            yield from self::streamTemporaryConversation($conversation, $prompt, $config->images);

            return;
        }

        $agent = Agent::fromConfig($config);
        $options = RunOptions::fromConfig($config);

        yield from Runner::stream($agent, $prompt, $options);
    }

    /**
     * A facade-created Conversation is owned by this one-shot stream. Close it
     * before exposing a terminal message because callers commonly retain the
     * Generator at that yield and never advance it again.
     *
     * @param array<int, string|array<string, mixed>> $images
     * @return \Generator<int, Message>
     */
    private static function streamTemporaryConversation(
        Conversation $conversation,
        string $prompt,
        array $images,
    ): \Generator {
        try {
            foreach ($conversation->stream($prompt, $images) as $message) {
                if ($message->isInterrupt()) {
                    $conversation->preserveSandboxOnClose();
                }
                if ($message->isResult() || $message->isInterrupt() || $message->isError()) {
                    $conversation->close();
                    yield $message;

                    return;
                }

                yield $message;
            }
        } finally {
            $conversation->close();
        }
    }

    /**
     * Create a multi-turn conversation.
     *
     * @api
     */
    public static function conversation(?HaoCodeConfig $config = null): Conversation
    {
        $config ??= new HaoCodeConfig;

        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);
        return new Conversation($config, $factory);
    }

    /**
     * Resume a previous session by ID.
     *
     * Returns a Conversation pre-loaded with the session's message history.
     *
     * @api
     *
     * @example
     *   $conv = HaoCode::resume('20260407_143022_a1b2c3d4');
     *   $conv->send('Continue where we left off');
     */
    public static function resume(string $sessionId, ?HaoCodeConfig $config = null): Conversation
    {
        $config ??= new HaoCodeConfig(ephemeral: false);
        $config = self::resolveResumeWorkingDirectory($sessionId, $config);

        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);
        $conv = new Conversation($config, $factory);
        try {
            $conv->loadSession($sessionId);
        } catch (\Throwable $e) {
            $conv->close();

            throw $e;
        }

        return $conv;
    }

    /**
     * Align resume config cwd with the session transcript's canonical cwd.
     */
    private static function resolveResumeWorkingDirectory(string $sessionId, HaoCodeConfig $config): HaoCodeConfig
    {
        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $sessionCwd = $sessionManager->getSessionCanonicalCwd($sessionId);
        if ($sessionCwd === null || $sessionCwd === '') {
            return $config;
        }

        if ($config->cwd === null || $config->cwd === '') {
            $values = get_object_vars($config);
            $values['cwd'] = $sessionCwd;

            return new HaoCodeConfig(...$values);
        }

        $configReal = realpath($config->cwd) ?: $config->cwd;
        $sessionReal = realpath($sessionCwd) ?: $sessionCwd;
        if ($configReal === $sessionReal) {
            return $config;
        }

        if ($config->allowCwdOverride) {
            return $config;
        }

        throw new \RuntimeException(
            "Session {$sessionId} was recorded under working directory \"{$sessionCwd}\", "
            ."but resume config cwd is \"{$config->cwd}\". Pass the session cwd, or set "
            .'allowCwdOverride: true if you intentionally want tools to run elsewhere.',
        );
    }

    /**
     * Resolve a durable interrupt and continue the original session.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @param HaoCodeConfig|null $config Required at runtime to restore the original tool boundary.
     * @api
     */
    public static function resumeInterrupt(
        string $sessionId,
        string $interruptId,
        array $decisions,
        ?HaoCodeConfig $config = null,
    ): QueryResult|StructuredResult {
        if ($config === null) {
            throw new \InvalidArgumentException(
                'HaoCodeConfig is required to resume an interrupt so the original tool and sandbox boundary can be restored.',
            );
        }

        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $state = $sessionManager->getInterruptState($sessionId, $interruptId);
        $checkpoint = is_array($state['checkpoint'] ?? null) ? $state['checkpoint'] : [];
        $pendingInterrupt = HumanInterrupt::fromArray($state['interrupt'] ?? []);
        HumanInterruptCoordinator::assertValidDecisions($pendingInterrupt, $decisions);
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $runSnapshot = is_array($checkpoint['run_snapshot'] ?? null) ? $checkpoint['run_snapshot'] : [];
        if (is_array($checkpoint['allowed_tools'] ?? null)) {
            $runSnapshot['allowed_tools'] = $checkpoint['allowed_tools'];
        }
        $resumeConfig = self::restoreInterruptRunConfig($config, $pendingInterrupt, $runSnapshot, $checkpoint);
        $conversation = self::resumeWithSnapshot($sessionId, $resumeConfig, $runSnapshot);
        try {
            try {
                $result = $conversation->resumeInterrupt($interruptId, $decisions);
            } catch (HumanInterruptException $e) {
                $conversation->preserveSandboxOnClose();
                if ($parentLink !== null) {
                    $sessionManager->recordInterruptParentLink(
                        $e->interrupt->sessionId,
                        $e->interrupt->id,
                        (string) $parentLink['parent_session_id'],
                        (string) $parentLink['parent_interrupt_id'],
                        (string) $parentLink['parent_action_id'],
                    );
                }
                if ($pendingInterrupt->sourceAgentId !== null) {
                    \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                        ->markWaitingForInput($pendingInterrupt->sourceAgentId, $e->interrupt);
                }
                throw $e;
            }
            $result = self::finalizeResumedManagedWorktree(
                $result,
                $pendingInterrupt,
                $runSnapshot,
                ($checkpoint['operation'] ?? null) !== 'structured',
            );
            if ($parentLink !== null) {
                $parentResult = self::resumeInterrupt(
                    (string) $parentLink['parent_session_id'],
                    (string) $parentLink['parent_interrupt_id'],
                    [HumanDecision::respond((string) $parentLink['parent_action_id'], $result->text)],
                    $config,
                );

                self::completeBackgroundInterruptOwner($pendingInterrupt, $result->text, $runSnapshot);

                return self::propagateManagedWorktreeResult($parentResult, $result);
            }
            if (($checkpoint['operation'] ?? null) === 'structured') {
                $schema = is_array($checkpoint['response_schema'] ?? null)
                    ? $checkpoint['response_schema']
                    : ($resumeConfig->responseSchema ?? []);
                if ($schema === []) {
                    throw new StructuredResultValidationException(
                        'Structured interrupt resume is missing response_schema in the checkpoint.',
                        $result->text,
                        ['missing response_schema'],
                    );
                }
                $structuredResult = self::runStructuredStateMachine(
                    conversation: $conversation,
                    schema: $schema,
                    maxRetries: max(0, $resumeConfig->structuredMaxRetries),
                    seedResult: $result,
                );
                self::completeBackgroundInterruptOwner($pendingInterrupt, $result->text, $runSnapshot);

                return $structuredResult;
            }

            self::completeBackgroundInterruptOwner($pendingInterrupt, $result->text, $runSnapshot);

            return $result;
        } finally {
            $conversation->close();
        }
    }

    /**
     * Streaming counterpart of {@see resumeInterrupt()}.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @param HaoCodeConfig|null $config Required at runtime to restore the original tool boundary.
     * @return \Generator<int, Message>
     * @api
     */
    public static function streamResumeInterrupt(
        string $sessionId,
        string $interruptId,
        array $decisions,
        ?HaoCodeConfig $config = null,
    ): \Generator {
        if ($config === null) {
            throw new \InvalidArgumentException(
                'HaoCodeConfig is required to resume an interrupt so the original tool and sandbox boundary can be restored.',
            );
        }

        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $state = $sessionManager->getInterruptState($sessionId, $interruptId);
        $checkpoint = is_array($state['checkpoint'] ?? null) ? $state['checkpoint'] : [];
        $pendingInterrupt = HumanInterrupt::fromArray($state['interrupt'] ?? []);
        HumanInterruptCoordinator::assertValidDecisions($pendingInterrupt, $decisions);
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $runSnapshot = is_array($checkpoint['run_snapshot'] ?? null) ? $checkpoint['run_snapshot'] : [];
        if (is_array($checkpoint['allowed_tools'] ?? null)) {
            $runSnapshot['allowed_tools'] = $checkpoint['allowed_tools'];
        }
        $resumeConfig = self::restoreInterruptRunConfig($config, $pendingInterrupt, $runSnapshot, $checkpoint);
        $conversation = self::resumeWithSnapshot($sessionId, $resumeConfig, $runSnapshot);
        try {
            $final = null;
            foreach ($conversation->streamResumeInterrupt($interruptId, $decisions) as $message) {
                if ($message->isInterrupt()) {
                    $conversation->preserveSandboxOnClose();
                    if ($parentLink !== null && $message->interrupt !== null) {
                        $sessionManager->recordInterruptParentLink(
                            $message->interrupt->sessionId,
                            $message->interrupt->id,
                            (string) $parentLink['parent_session_id'],
                            (string) $parentLink['parent_interrupt_id'],
                            (string) $parentLink['parent_action_id'],
                        );
                    }
                    if ($pendingInterrupt->sourceAgentId !== null && $message->interrupt !== null) {
                        \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                            ->markWaitingForInput($pendingInterrupt->sourceAgentId, $message->interrupt);
                    }
                    $conversation->close();
                    yield $message;
                    return;
                }
                if ($message->isError()) {
                    $conversation->close();
                    yield $message;

                    return;
                }
                if ($message->isResult()) {
                    $final = $message;
                    continue;
                }
                yield $message;
            }
            if ($final === null) {
                return;
            }
            $final = self::finalizeResumedManagedWorktreeMessage(
                $final,
                $pendingInterrupt,
                $runSnapshot,
            );
            if ($parentLink !== null) {
                foreach (self::streamResumeInterrupt(
                    (string) $parentLink['parent_session_id'],
                    (string) $parentLink['parent_interrupt_id'],
                    [HumanDecision::respond((string) $parentLink['parent_action_id'], $final->text)],
                    $config,
                ) as $parentMessage) {
                    if ($parentMessage->isResult()) {
                        self::completeBackgroundInterruptOwner(
                            $pendingInterrupt,
                            $final->text ?? '',
                            $runSnapshot,
                        );
                        $conversation->close();
                        yield self::propagateManagedWorktreeMessage($parentMessage, $final);

                        return;
                    }
                    if ($parentMessage->isInterrupt() || $parentMessage->isError()) {
                        $conversation->close();
                        yield $parentMessage;

                        return;
                    }
                    yield $parentMessage;
                }
                return;
            }
            if (($checkpoint['operation'] ?? null) === 'structured') {
                $schema = is_array($checkpoint['response_schema'] ?? null)
                    ? $checkpoint['response_schema']
                    : ($resumeConfig->responseSchema ?? []);
                if ($schema === []) {
                    $conversation->close();
                    yield Message::error(
                        'Structured interrupt resume is missing response_schema in the checkpoint.',
                    );

                    return;
                }
                $seed = new QueryResult(
                    text: (string) ($final->text ?? ''),
                    usage: is_array($final->usage ?? null) ? $final->usage : [],
                    cost: (float) ($final->cost ?? 0.0),
                    sessionId: $final->sessionId ?? $sessionId,
                    turnsUsed: 0,
                    terminationReason: $final->terminationReason ?? \HaoCode\Contracts\RunTerminationReason::Normal,
                );
                try {
                    $structured = self::runStructuredStateMachine(
                        conversation: $conversation,
                        schema: $schema,
                        maxRetries: max(0, $resumeConfig->structuredMaxRetries),
                        seedResult: $seed,
                    );
                } catch (StructuredResultValidationException $e) {
                    $conversation->close();
                    yield Message::error($e->getMessage());

                    return;
                }
                self::completeBackgroundInterruptOwner($pendingInterrupt, $structured->rawText, $runSnapshot);
                $conversation->close();
                yield Message::result(
                    text: $structured->rawText,
                    usage: $structured->queryResult?->usage ?? $seed->usage,
                    cost: $structured->queryResult?->cost ?? $seed->cost,
                    sessionId: $structured->queryResult?->sessionId ?? $seed->sessionId,
                    terminationReason: $structured->queryResult?->terminationReason ?? $seed->terminationReason,
                );

                return;
            }
            self::completeBackgroundInterruptOwner($pendingInterrupt, $final->text ?? '', $runSnapshot);
            $conversation->close();
            yield $final;
        } finally {
            $conversation->close();
        }
    }
}
