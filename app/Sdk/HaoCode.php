<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;

/**
 * HaoCode SDK — programmatic access to the agent's capabilities.
 *
 * Six entry points covering the full spectrum from simple to advanced:
 *
 *   // 1. One-shot query
 *   $result = HaoCode::query('Explain this codebase');
 *   echo $result;        // Stringable
 *   echo $result->cost;  // plus metadata
 *
 *   // 2. Streaming messages
 *   foreach (HaoCode::stream('Explain PHP Fibers') as $msg) { ... }
 *
 *   // 3. Multi-turn conversation
 *   $conv = HaoCode::conversation();
 *   $conv->send('Create a User model');
 *
 *   // 4. Resume a previous session
 *   $conv = HaoCode::resume('20260407_abc123');
 *
 *   // 5. Structured output
 *   $data = HaoCode::structured('Classify this ticket', $schema);
 *   echo $data->category;
 *
 *   // 6. Custom tools
 *   HaoCode::query('Look up order #123', new HaoCodeConfig(
 *       tools: [new LookupOrderTool()],
 *   ));
 *
 * @api
 */
class HaoCode
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
                return $conv->send($prompt);
            } finally {
                $conv->close();
            }
        }
        if ($config->continueSession) {
            $conv = self::continueLatest($config->cwd, $config);
            try {
                return $conv->send($prompt);
            } finally {
                $conv->close();
            }
        }

        $run = self::createRun($config);
        $loop = $run->loop;

        try {
            $response = $loop->run(
                userInput: $prompt,
                onTextDelta: $config->onText,
                onToolStart: $config->onToolStart,
                onToolComplete: $config->onToolComplete,
                onTurnStart: $config->onTurnStart,
                onThinkingDelta: $config->onThinking,
            );

            return new QueryResult(
                text: $response,
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $config->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
            );
        } finally {
            $run->close();
        }
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
            try {
                yield from $conversation->stream($prompt);
            } finally {
                $conversation->close();
            }

            return;
        }
        if ($config->continueSession) {
            $conversation = self::continueLatest($config->cwd, $config);
            try {
                yield from $conversation->stream($prompt);
            } finally {
                $conversation->close();
            }

            return;
        }

        $run = self::createRun($config);
        $loop = $run->loop;
        $queue = new \SplQueue;

        // These callbacks are exclusively invoked from within the Fiber below.
        // Fiber::getCurrent()?->suspend() uses the nullable operator as a defensive
        // guard; in practice getCurrent() will always return the active Fiber here.
        $onText = function (string $delta) use ($queue, $config): void {
            $queue->enqueue(Message::text($delta));
            if ($config->onText) {
                ($config->onText)($delta);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolStart = function (string $name, array $input) use ($queue, $config): void {
            $queue->enqueue(Message::toolStart($name, $input));
            if ($config->onToolStart) {
                ($config->onToolStart)($name, $input);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolComplete = function (string $name, $result) use ($queue, $config): void {
            $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
            if ($config->onToolComplete) {
                ($config->onToolComplete)($name, $result);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onTurnStart = function (int $turn) use ($queue, $config): void {
            $queue->enqueue(Message::turn($turn));
            if ($config->onTurnStart) {
                ($config->onTurnStart)($turn);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $response = null;
        $thrownException = null;

        $fiber = new \Fiber(function () use ($loop, $prompt, $onText, $onToolStart, $onToolComplete, $onTurnStart, $config, &$response, &$thrownException): void {
            try {
                $response = $loop->run(
                    userInput: $prompt,
                    onTextDelta: $onText,
                    onToolStart: $onToolStart,
                    onToolComplete: $onToolComplete,
                    onTurnStart: $onTurnStart,
                    onThinkingDelta: $config->onThinking,
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
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $config->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
            );
        } finally {
            $run->close();
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
        $factory = app(AgentLoopFactory::class);
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
        $config ??= new HaoCodeConfig;

        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);
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
     * Resolve a durable interrupt and continue the original session.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @api
     */
    public static function resumeInterrupt(
        string $sessionId,
        string $interruptId,
        array $decisions,
        ?HaoCodeConfig $config = null,
    ): QueryResult|StructuredResult {
        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);
        $state = $sessionManager->getInterruptState($sessionId, $interruptId);
        $checkpoint = is_array($state['checkpoint'] ?? null) ? $state['checkpoint'] : [];
        $pendingInterrupt = HumanInterrupt::fromArray($state['interrupt'] ?? []);
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $conversation = self::resume($sessionId, $config ?? new HaoCodeConfig);
        try {
            try {
                $result = $conversation->resumeInterrupt($interruptId, $decisions);
            } catch (HumanInterruptException $e) {
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
                    app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                        ->markWaitingForInput($pendingInterrupt->sourceAgentId, $e->interrupt);
                }
                throw $e;
            }
            if ($pendingInterrupt->sourceAgentId !== null) {
                app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                    ->markCompleted($pendingInterrupt->sourceAgentId, $result->text);
                app(\HaoCode\Services\Task\TaskManager::class)
                    ->update($pendingInterrupt->sourceAgentId, 'completed', $result->text);
            }
            if ($parentLink !== null) {
                return self::resumeInterrupt(
                    (string) $parentLink['parent_session_id'],
                    (string) $parentLink['parent_interrupt_id'],
                    [HumanDecision::respond((string) $parentLink['parent_action_id'], $result->text)],
                    $config,
                );
            }
            if (($checkpoint['operation'] ?? null) === 'structured') {
                return self::parseStructuredResult($result);
            }

            return $result;
        } finally {
            $conversation->close();
        }
    }

    /**
     * Streaming counterpart of {@see resumeInterrupt()}.
     *
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @return \Generator<int, Message>
     * @api
     */
    public static function streamResumeInterrupt(
        string $sessionId,
        string $interruptId,
        array $decisions,
        ?HaoCodeConfig $config = null,
    ): \Generator {
        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);
        $state = $sessionManager->getInterruptState($sessionId, $interruptId);
        $pendingInterrupt = HumanInterrupt::fromArray($state['interrupt'] ?? []);
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $conversation = self::resume($sessionId, $config ?? new HaoCodeConfig);
        try {
            $final = null;
            foreach ($conversation->streamResumeInterrupt($interruptId, $decisions) as $message) {
                if ($message->isInterrupt()) {
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
                        app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                            ->markWaitingForInput($pendingInterrupt->sourceAgentId, $message->interrupt);
                    }
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
            if ($pendingInterrupt->sourceAgentId !== null) {
                app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                    ->markCompleted($pendingInterrupt->sourceAgentId, $final->text);
                app(\HaoCode\Services\Task\TaskManager::class)
                    ->update($pendingInterrupt->sourceAgentId, 'completed', $final->text ?? '');
            }
            if ($parentLink !== null) {
                yield from self::streamResumeInterrupt(
                    (string) $parentLink['parent_session_id'],
                    (string) $parentLink['parent_interrupt_id'],
                    [HumanDecision::respond((string) $parentLink['parent_action_id'], $final->text)],
                    $config,
                );
                return;
            }
            yield $final;
        } finally {
            $conversation->close();
        }
    }

    /**
     * Continue the most recent session in the working directory.
     *
     * @api
     *
     * @example
     *   $conv = HaoCode::continueLatest();
     *   $conv->send('What were we working on?');
     */
    public static function continueLatest(?string $cwd = null, ?HaoCodeConfig $config = null): Conversation
    {
        $cwd ??= getcwd() ?: '/';

        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);
        $sessionId = $sessionManager->findMostRecentSessionId($cwd);

        if ($sessionId === null) {
            throw new \RuntimeException("No previous session found in {$cwd}");
        }

        return self::resume($sessionId, $config);
    }

    /**
     * Execute a query and return structured (JSON) output.
     *
     * The agent is instructed to respond with JSON matching the given schema.
     * The result is parsed and wrapped in a StructuredResult with property/array access.
     *
     * @param  string  $prompt  The task or question.
     * @param  array  $jsonSchema  JSON schema defining the expected output structure.
     * @param  HaoCodeConfig|null  $config  Optional configuration.
     *
     * @example
     *   $result = HaoCode::structured('Classify this ticket: "My order is late"', [
     *       'type' => 'object',
     *       'properties' => [
     *           'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical']],
     *           'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
     *       ],
     *       'required' => ['category', 'priority'],
     *   ]);
     *   echo $result->category; // 'shipping'
     *
     * @api
     */
    public static function structured(string $prompt, array $jsonSchema, ?HaoCodeConfig $config = null): StructuredResult
    {
        $effectiveSchema = $config?->responseSchema ?? $jsonSchema;
        $schemaJson = json_encode($effectiveSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $structuredPrompt = $prompt."\n\n".
            'IMPORTANT: You MUST respond with ONLY a valid JSON object matching this schema. '.
            "No markdown fences, no explanation, no extra text — just the raw JSON.\n\n".
            "Schema:\n".$schemaJson;

        $config = ($config ?? new HaoCodeConfig)->withResponseSchema($effectiveSchema);
        $queryResult = self::query($structuredPrompt, $config);

        return self::parseStructuredResult($queryResult);
    }

    private static function parseStructuredResult(QueryResult $queryResult): StructuredResult
    {
        $text = trim($queryResult->text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException(
                "Failed to parse structured response as JSON.\nRaw response: ".mb_substr($text, 0, 500)
            );
        }

        return new StructuredResult($decoded, $queryResult->text, $queryResult);
    }

    private static function createRun(HaoCodeConfig $config): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        return SdkRunFactory::create($config, $factory);
    }

    /**
     * Build a standalone StreamingClient when SDK config overrides API settings.
     *
     * Returns null if no overrides are present (use container default).
     */
    private static function buildStreamingClient(
        HaoCodeConfig $config,
        ?SettingsManager $settings = null,
    ): ?StreamingClient
    {
        return SdkRunFactory::buildStreamingClient($config, $settings);
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
