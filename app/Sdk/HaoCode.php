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
 *       allowedTools: ['LookupOrder'],
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
                return $conv->send($prompt, $config->images);
            } finally {
                $conv->close();
            }
        }
        if ($config->continueSession) {
            $conv = self::continueLatest($config->cwd, $config);
            try {
                return $conv->send($prompt, $config->images);
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
            try {
                yield from $conversation->stream($prompt, $config->images);
            } finally {
                $conversation->close();
            }

            return;
        }
        if ($config->continueSession) {
            $conversation = self::continueLatest($config->cwd, $config);
            try {
                yield from $conversation->stream($prompt, $config->images);
            } finally {
                $conversation->close();
            }

            return;
        }

        $agent = Agent::fromConfig($config);
        $options = RunOptions::fromConfig($config);

        yield from Runner::stream($agent, $prompt, $options);
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
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $conversation = self::resume($sessionId, $config);
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
                    \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                        ->markWaitingForInput($pendingInterrupt->sourceAgentId, $e->interrupt);
                }
                throw $e;
            }
            if ($pendingInterrupt->sourceAgentId !== null) {
                \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                    ->markCompleted($pendingInterrupt->sourceAgentId, $result->text);
                \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Task\TaskManager::class)
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
        $pendingInterrupt = HumanInterrupt::fromArray($state['interrupt'] ?? []);
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $conversation = self::resume($sessionId, $config);
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
                        \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
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
                \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Agent\BackgroundAgentManager::class)
                    ->markCompleted($pendingInterrupt->sourceAgentId, $final->text);
                \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Task\TaskManager::class)
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
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
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
        $maxRetries = max(0, $config?->structuredMaxRetries ?? 1);

        $schemaJson = json_encode($effectiveSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $basePrompt = $prompt."\n\n".
            'IMPORTANT: You MUST respond with ONLY a valid JSON object matching this schema. '.
            "No markdown fences, no explanation, no extra text — just the raw JSON.\n\n".
            "Schema:\n".$schemaJson;

        $config = ($config ?? new HaoCodeConfig)->withResponseSchema($effectiveSchema);

        $attempt = 0;
        $lastValidationErrors = [];
        $lastRawText = '';
        while (true) {
            $promptForAttempt = $attempt === 0
                ? $basePrompt
                : $basePrompt."\n\n".
                    "Your previous response did not match the schema. ".
                    "Fix these violations and reply with the corrected JSON only:\n".
                    implode("\n", $lastValidationErrors);

            $queryResult = self::query($promptForAttempt, $config);
            $lastRawText = $queryResult->text;

            $parsed = self::parseStructuredResult($queryResult);
            // parseStructuredResult already guarantees $parsed is a JSON array;
            // now validate it against the supplied schema.
            $errors = self::validateAgainstSchema($parsed->toArray(), $effectiveSchema);
            if ($errors === []) {
                return $parsed;
            }

            $lastValidationErrors = $errors;
            if ($attempt >= $maxRetries) {
                throw new StructuredResultValidationException(
                    'Structured response failed schema validation after '.($attempt + 1).
                    ' attempt(s). Violations: '.implode('; ', $errors),
                    $lastRawText,
                    $errors,
                );
            }
            $attempt++;
        }
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

    /**
     * Validate the decoded structured response against the JSON Schema.
     *
     * Returns a list of human-readable error strings (empty when valid). Each
     * error includes the JSON-pointer path produced by the validator so the
     * retry prompt can point the model at the offending field.
     *
     * @return list<string>
     */
    private static function validateAgainstSchema(array $data, array $schema): array
    {
        try {
            $schemaObj = json_decode((string) json_encode($schema, JSON_UNESCAPED_SLASHES));
            $dataObj = json_decode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
            \Swaggest\JsonSchema\Schema::import($schemaObj)->in($dataObj);

            return [];
        } catch (\Swaggest\JsonSchema\InvalidValue $e) {
            return [trim($e->getMessage())];
        } catch (\Throwable $e) {
            // Schema itself was malformed (e.g. unsupported draft) — surface
            // as a single validation error so the caller can diagnose.
            return ['Schema validation setup failed: '.$e->getMessage()];
        }
    }

    private static function createRun(HaoCodeConfig $config): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

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
    ): ?StreamingClient {
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
