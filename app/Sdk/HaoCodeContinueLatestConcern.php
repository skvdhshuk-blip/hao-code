<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\HumanInterruptCoordinator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;

trait HaoCodeContinueLatestConcern
{

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
        $snapshotWorktree = is_string($runSnapshot['worktree_path'] ?? null)
            ? $runSnapshot['worktree_path']
            : null;
        $hasSandboxLease = is_array($runSnapshot['sandbox_lease'] ?? null);
        $worktreePath = is_string($worktreePath) && $worktreePath !== ''
            ? $worktreePath
            : ($snapshotWorktree
                ?? ($hasSandboxLease ? null : $snapshotCwd));
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
        array $checkpoint = [],
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
        // Restore structured schema from the durable checkpoint so resume
        // re-enters the same parse/validate state machine without the caller
        // re-supplying responseSchema.
        if (is_array($checkpoint['response_schema'] ?? null)) {
            $values['responseSchema'] = $checkpoint['response_schema'];
        }

        // Reattach the same sandbox root/session after durable HITL interrupt.
        $lease = is_array($runSnapshot['sandbox_lease'] ?? null)
            ? $runSnapshot['sandbox_lease']
            : null;
        if ($lease !== null) {
            $values['sandbox'] = \HaoCode\Sdk\Sandbox\SandboxRuntime::configFromLease(
                $lease,
                $config->sandbox,
            );
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
        try {
            $conversation = new Conversation(
                $config,
                $factory,
                bootstrap: new \HaoCode\Sdk\Internal\ConversationBootstrap($runSnapshot),
            );
            $conversation->loadSession($sessionId);
        } catch (\Throwable $e) {
            if (isset($conversation)) {
                $conversation->close();
            }
            throw $e;
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
            terminationReason: $result->terminationReason,
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
            $message->terminationReason ?? \HaoCode\Contracts\RunTerminationReason::Normal,
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
            terminationReason: $parent->terminationReason,
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
            $parent->terminationReason ?? \HaoCode\Contracts\RunTerminationReason::Normal,
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

        // Fail closed on a broken schema before spending model calls.
        $schemaSetupErrors = self::validateSchemaIsUsable($effectiveSchema);
        if ($schemaSetupErrors !== []) {
            throw new StructuredResultValidationException(
                'Structured response schema is invalid: '.implode('; ', $schemaSetupErrors),
                '',
                $schemaSetupErrors,
            );
        }

        $config = ($config ?? new HaoCodeConfig)->withResponseSchema($effectiveSchema);
        $basePrompt = self::buildStructuredBasePrompt($prompt, $effectiveSchema);

        // Always reuse one Conversation for parse/schema retries so tool history
        // and side effects stay in a single agent run. ephemeral still means
        // "no durable session id for the caller", not "rebuild Agent each retry".
        if ($config->sessionId !== null) {
            $conversation = self::resume($config->sessionId, $config);
        } elseif ($config->continueSession) {
            $conversation = self::continueLatest($config->cwd, $config);
        } else {
            $conversation = self::conversation($config);
        }

        try {
            return self::runStructuredStateMachine(
                conversation: $conversation,
                schema: $effectiveSchema,
                maxRetries: $maxRetries,
                initialPrompt: $basePrompt,
                initialImages: $config->images,
            );
        } finally {
            $conversation->close();
        }
    }
}
