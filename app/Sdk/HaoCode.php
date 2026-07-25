<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
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
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $runSnapshot = is_array($checkpoint['run_snapshot'] ?? null) ? $checkpoint['run_snapshot'] : [];
        if (is_array($checkpoint['allowed_tools'] ?? null)) {
            $runSnapshot['allowed_tools'] = $checkpoint['allowed_tools'];
        }
        $resumeConfig = self::restoreInterruptRunConfig($config, $pendingInterrupt, $runSnapshot);
        $conversation = self::resumeWithSnapshot($sessionId, $resumeConfig, $runSnapshot);
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
                $structuredResult = self::parseStructuredResult($result);
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
        $parentLink = $sessionManager->findInterruptParentLink($sessionId, $interruptId);
        $runSnapshot = is_array($checkpoint['run_snapshot'] ?? null) ? $checkpoint['run_snapshot'] : [];
        if (is_array($checkpoint['allowed_tools'] ?? null)) {
            $runSnapshot['allowed_tools'] = $checkpoint['allowed_tools'];
        }
        $resumeConfig = self::restoreInterruptRunConfig($config, $pendingInterrupt, $runSnapshot);
        $conversation = self::resumeWithSnapshot($sessionId, $resumeConfig, $runSnapshot);
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
                        yield self::propagateManagedWorktreeMessage($parentMessage, $final);

                        return;
                    }
                    yield $parentMessage;
                }
                return;
            }
            self::completeBackgroundInterruptOwner($pendingInterrupt, $final->text ?? '', $runSnapshot);
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
        $config ??= new HaoCodeConfig(ephemeral: false);
        if ($config->cwd === null || $config->cwd === '') {
            $values = get_object_vars($config);
            $values['cwd'] = $cwd;
            $config = new HaoCodeConfig(...$values);
        }

        /** @var SessionManager $sessionManager */
        $sessionManager = \HaoCode\Support\Runtime\SdkRuntime::app(SessionManager::class);
        $sessionId = $sessionManager->findMostRecentSessionId($cwd);

        if ($sessionId === null) {
            throw new \RuntimeException("No previous session found in {$cwd}");
        }

        return self::resume($sessionId, $config);
    }

    private static function restoreSourceAgentWorkingDirectory(
        HaoCodeConfig $config,
        HumanInterrupt $interrupt,
        ?array $runSnapshot = null,
    ): HaoCodeConfig {
        $worktreePath = null;
        if ($interrupt->sourceAgentId !== null) {
            $agent = \HaoCode\Support\Runtime\SdkRuntime::app(
                \HaoCode\Services\Agent\BackgroundAgentManager::class,
            )->get($interrupt->sourceAgentId);
            $worktreePath = $agent['worktree_path'] ?? null;
        }
        $snapshotCwd = is_string($runSnapshot['cwd'] ?? null) ? $runSnapshot['cwd'] : null;
        $worktreePath = is_string($worktreePath) && $worktreePath !== '' ? $worktreePath : $snapshotCwd;
        if (! is_string($worktreePath) || $worktreePath === '' || $worktreePath === $config->cwd) {
            return $config;
        }
        if (! is_dir($worktreePath)) {
            throw new \RuntimeException(
                "Cannot resume interrupted agent: its working directory no longer exists at {$worktreePath}.",
            );
        }
        $base = realpath(($config->cwd ?? getcwd()) ?: '/');
        $resolvedWorktree = realpath($worktreePath);
        if ($base !== false && $resolvedWorktree === $base) {
            $values = get_object_vars($config);
            $values['cwd'] = $worktreePath;

            return new HaoCodeConfig(...$values);
        }
        $parent = realpath(dirname($worktreePath, 3));
        $managed = preg_match('/^agent-[a-f0-9]{8}$/', basename($worktreePath)) === 1
            && basename(dirname($worktreePath)) === 'worktrees'
            && basename(dirname($worktreePath, 2)) === '.claude'
            && $base !== false
            && $parent === $base;
        if ($interrupt->sourceAgentId === null && ! $managed) {
            throw new \RuntimeException(
                "Refused to resume interrupted agent in an unmanaged working directory: {$worktreePath}.",
            );
        }

        $values = get_object_vars($config);
        $values['cwd'] = $worktreePath;

        return new HaoCodeConfig(...$values);
    }

    private static function completeBackgroundInterruptOwner(
        HumanInterrupt $interrupt,
        string $result,
        ?array $runSnapshot = null,
    ): void {
        $ownerId = $interrupt->sourceAgentId
            ?? (is_string($runSnapshot['background_owner_agent_id'] ?? null)
                ? $runSnapshot['background_owner_agent_id']
                : null);
        if ($ownerId === null || $ownerId === '') {
            return;
        }

        $backgroundAgents = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Agent\BackgroundAgentManager::class,
        );
        $backgroundAgents->markCompleted($ownerId, $result);
        $backgroundAgents->finalizeStoredWorktree($ownerId);
        \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\Task\TaskManager::class)
            ->update($ownerId, 'completed', $result);
    }

    private static function restoreInterruptRunConfig(
        HaoCodeConfig $config,
        HumanInterrupt $interrupt,
        array $runSnapshot,
    ): HaoCodeConfig {
        $config = self::restoreSourceAgentWorkingDirectory($config, $interrupt, $runSnapshot);
        $values = get_object_vars($config);
        if (is_int($runSnapshot['max_turns_remaining'] ?? null)
            && $runSnapshot['max_turns_remaining'] > 0) {
            $values['maxTurns'] = $runSnapshot['max_turns_remaining'];
        }
        if (($runSnapshot['read_only'] ?? false) === true) {
            $values['permissionMode'] = 'plan';
        }
        if (is_array($runSnapshot['allowed_tools'] ?? null)) {
            $snapshotTools = array_values(array_filter(
                $runSnapshot['allowed_tools'],
                static fn (mixed $name): bool => is_string($name) && $name !== '',
            ));
            $values['allowedTools'] = in_array('*', $config->allowedTools, true)
                ? $snapshotTools
                : array_values(array_intersect($snapshotTools, $config->allowedTools));
        }

        return new HaoCodeConfig(...$values);
    }

    private static function resumeWithSnapshot(
        string $sessionId,
        HaoCodeConfig $config,
        array $runSnapshot,
    ): Conversation {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);
        SdkRunFactory::stageResumeSnapshot($config, $runSnapshot);
        try {
            $conversation = new Conversation($config, $factory);
            $conversation->loadSession($sessionId);
        } catch (\Throwable $e) {
            if (isset($conversation)) {
                $conversation->close();
            }
            throw $e;
        } finally {
            SdkRunFactory::clearResumeSnapshot($config);
        }

        return $conversation;
    }

    private static function finalizeResumedManagedWorktree(
        QueryResult $result,
        HumanInterrupt $interrupt,
        array $runSnapshot,
        bool $appendNotice,
    ): QueryResult {
        $outcome = self::finalizeManagedWorktreeSnapshot($interrupt, $runSnapshot);
        if ($outcome === null) {
            return $result;
        }

        $usage = array_merge($result->usage, $outcome['metadata']);
        $text = $result->text;
        if ($appendNotice && $outcome['notice'] !== null) {
            $text .= "\n\n".$outcome['notice'];
        }

        return new QueryResult(
            text: $text,
            usage: $usage,
            cost: $result->cost,
            sessionId: $result->sessionId,
            turnsUsed: $result->turnsUsed,
        );
    }

    private static function finalizeResumedManagedWorktreeMessage(
        Message $message,
        HumanInterrupt $interrupt,
        array $runSnapshot,
    ): Message {
        $outcome = self::finalizeManagedWorktreeSnapshot($interrupt, $runSnapshot);
        if ($outcome === null || ! $message->isResult()) {
            return $message;
        }

        $text = $message->text ?? '';
        if ($outcome['notice'] !== null) {
            $text .= "\n\n".$outcome['notice'];
        }

        return Message::result(
            $text,
            array_merge($message->usage ?? [], $outcome['metadata']),
            $message->cost ?? 0.0,
            $message->sessionId,
        );
    }

    private static function propagateManagedWorktreeResult(
        QueryResult|StructuredResult $parent,
        QueryResult $child,
    ): QueryResult|StructuredResult {
        if ($parent instanceof StructuredResult) {
            if ($parent->queryResult === null) {
                return $parent;
            }
            $queryResult = self::propagateManagedWorktreeResult($parent->queryResult, $child);

            return new StructuredResult(
                $parent->toArray(),
                $parent->rawText,
                $queryResult instanceof QueryResult ? $queryResult : $parent->queryResult,
            );
        }

        $metadata = self::managedWorktreeMetadata($child->usage);
        if ($metadata === []) {
            return $parent;
        }

        return new QueryResult(
            text: self::appendManagedWorktreeNotice($parent->text, $metadata),
            usage: array_merge($parent->usage, $metadata),
            cost: $parent->cost,
            sessionId: $parent->sessionId,
            turnsUsed: $parent->turnsUsed,
        );
    }

    private static function propagateManagedWorktreeMessage(Message $parent, Message $child): Message
    {
        $metadata = self::managedWorktreeMetadata($child->usage ?? []);
        if ($metadata === []) {
            return $parent;
        }

        return Message::result(
            self::appendManagedWorktreeNotice($parent->text ?? '', $metadata),
            array_merge($parent->usage ?? [], $metadata),
            $parent->cost ?? 0.0,
            $parent->sessionId,
        );
    }

    /** @return array<string, mixed> */
    private static function managedWorktreeMetadata(array $usage): array
    {
        if (! is_string($usage['worktree_path'] ?? null)
            || ! is_string($usage['worktree_branch'] ?? null)
            || ! is_bool($usage['worktree_retained'] ?? null)) {
            return [];
        }

        return array_intersect_key($usage, array_flip([
            'worktree_path',
            'worktree_branch',
            'worktree_retained',
            'worktree_cleanup_error',
        ]));
    }

    /** @param array<string, mixed> $metadata */
    private static function appendManagedWorktreeNotice(string $text, array $metadata): string
    {
        $path = $metadata['worktree_path'];
        $branch = $metadata['worktree_branch'];
        if (($metadata['worktree_retained'] ?? false) !== true || str_contains($text, $path)) {
            return $text;
        }

        $error = $metadata['worktree_cleanup_error'] ?? null;
        $notice = is_string($error) && $error !== ''
            ? "Warning: {$error} Worktree: {$path} (branch: {$branch})."
            : "Worktree with changes retained at: {$path} (branch: {$branch})";

        return $text."\n\n".$notice;
    }

    /**
     * @return array{notice: string|null, metadata: array<string, mixed>}|null
     */
    private static function finalizeManagedWorktreeSnapshot(
        HumanInterrupt $interrupt,
        array $runSnapshot,
    ): ?array {
        if ($interrupt->sourceAgentId !== null || ($runSnapshot['managed_worktree'] ?? false) !== true) {
            return null;
        }

        $path = $runSnapshot['worktree_path'] ?? null;
        $branch = $runSnapshot['worktree_branch'] ?? null;
        if (! is_string($path) || $path === '' || ! is_string($branch) || $branch === '') {
            return null;
        }

        $outcome = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Agent\BackgroundAgentManager::class,
        )->finalizeManagedWorktree($path, $branch);
        $notice = $outcome['notice'];
        if ($notice === null && $outcome['error'] !== null) {
            $notice = "Warning: {$outcome['error']} Worktree: {$path} (branch: {$branch}).";
        }

        return [
            'notice' => $notice,
            'metadata' => [
                'worktree_path' => $path,
                'worktree_branch' => $branch,
                'worktree_retained' => $outcome['retained'],
                'worktree_cleanup_error' => $outcome['error'],
            ],
        ];
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

        // Durable structured runs always reuse one Conversation so retries
        // share session history (and tool side-effect context). Ephemeral
        // budgeted runs may still use a shared ledger without a conversation
        // when no session is requested.
        $conversation = null;
        $reuseConversation = ! $config->ephemeral
            || $config->sessionId !== null
            || $config->continueSession
            || $config->maxBudgetUsd !== null;
        if ($reuseConversation) {
            if ($config->sessionId !== null) {
                $conversation = self::resume($config->sessionId, $config);
            } elseif ($config->continueSession) {
                $conversation = self::continueLatest($config->cwd, $config);
            } elseif (! $config->ephemeral || $config->maxBudgetUsd !== null) {
                $conversation = self::conversation($config);
            }
        }
        $attempt = 0;
        $lastValidationErrors = [];
        $lastRawText = '';
        try {
            while (true) {
                $promptForAttempt = $attempt === 0
                    ? $basePrompt
                    : $basePrompt."\n\n".
                        "Your previous response did not match the schema. ".
                        "Fix these violations and reply with the corrected JSON only:\n".
                        implode("\n", $lastValidationErrors).
                        "\n\nPrevious tools may already have executed. Do not repeat completed side effects.";

                if ($conversation !== null) {
                    $queryResult = $conversation->send($promptForAttempt, $config->images);
                } else {
                    $queryResult = self::query($promptForAttempt, $config);
                }
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
        } finally {
            $conversation?->close();
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

    private static function queryWithBudgetLedger(
        string $prompt,
        HaoCodeConfig $config,
        BudgetLedger $budgetLedger,
    ): QueryResult {
        $run = self::createRun($config, $budgetLedger);
        $loop = $run->loop;
        $userInput = $config->images !== []
            ? ImageContentBlock::buildUserContent($prompt, $config->images)
            : $prompt;

        try {
            $response = $loop->run(
                userInput: $userInput,
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
                turnsUsed: $loop->getLastRunTurns(),
            );
        } finally {
            $run->close();
        }
    }

    private static function createRun(
        HaoCodeConfig $config,
        ?BudgetLedger $budgetLedger = null,
    ): SdkRun
    {
        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

        return SdkRunFactory::create($config, $factory, budgetLedger: $budgetLedger);
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
            'cost_available' => $loop->isCostEstimateAvailable(),
        ];
    }
}
