<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;

class ToolOrchestrator
{
    /** Appended to a successful Read's output once the same file has been read
     *  this many times without an intervening Write/Edit on the same path. */
    private const REPEATED_READ_HINT_THRESHOLD = 4;

    private const DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS = 120.0;

    private $permissionPromptHandler = null;
    private ?ToolResultStorage $toolResultStorage = null;
    /** @var array<string, int> raw file_path → successful Read count (this session) */
    private array $readCountsByFile = [];
    private ?SkillScopeState $skillScope = null;

    /** @var array<string, bool|array<string, mixed>> */
    private array $interruptOn = [];

    private bool $enableAskUser = false;

    private bool $enablePermissionInterrupts = false;

    /** @var string[]|null */
    private ?array $resumeAllowedTools = null;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly PermissionChecker $permissionChecker,
        private readonly HookExecutor $hookExecutor,
        private readonly ?PhoenixTracer $tracer = null,
        private readonly float $parallelToolTimeoutSeconds = self::DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS,
    ) {
        $this->skillScope = new SkillScopeState();
        if (! is_finite($this->parallelToolTimeoutSeconds) || $this->parallelToolTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Parallel tool timeout must be greater than zero.');
        }
    }

    private function skillScope(): SkillScopeState
    {
        return $this->skillScope ??= new SkillScopeState();
    }

    public function setToolResultStorage(ToolResultStorage $storage): void
    {
        $this->toolResultStorage = $storage;
    }

    public function getToolResultStorage(): ?ToolResultStorage
    {
        return $this->toolResultStorage;
    }

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->permissionPromptHandler = $handler;
    }

    /** @internal */
    public function configureHumanInterrupts(array $interruptOn, bool $enableAskUser): void
    {
        $this->interruptOn = $interruptOn;
        $this->enableAskUser = $enableAskUser;
    }

    /** @internal */
    public function getInterruptOn(): array
    {
        return $this->interruptOn;
    }

    /** @internal */
    public function isAskUserEnabled(): bool
    {
        return $this->enableAskUser;
    }

    /** @internal */
    public function hasHumanInterruptsConfigured(): bool
    {
        return $this->interruptOn !== [] || $this->enableAskUser || $this->enablePermissionInterrupts;
    }

    /** @internal */
    public function enablePermissionInterrupts(bool $enabled): void
    {
        $this->enablePermissionInterrupts = $enabled;
    }

    /** @internal */
    public function arePermissionInterruptsEnabled(): bool
    {
        return $this->enablePermissionInterrupts;
    }

    /** @internal */
    public function setResumeAllowedTools(?array $toolNames): void
    {
        $this->resumeAllowedTools = $toolNames === null ? null : array_values(array_unique($toolNames));
    }

    /**
     * Validate, normalize, hook and permission-check a complete assistant tool batch
     * before any gated action is executed.
     *
     * @return array{prepared: array<int, array>, results: array<int, array>, actions: array<int, HumanActionRequest>}
     * @internal
     */
    public function prepareHumanReview(array $blocks, ToolUseContext $context, bool $suppressConfiguredGate = false): array
    {
        $prepared = [];
        $results = [];
        $actions = [];

        foreach ($blocks as $index => $block) {
            $outcome = $this->prepareOneForHumanReview($block, $context, $suppressConfiguredGate);
            if (isset($outcome['result'])) {
                $results[$index] = $outcome['result'];
            } else {
                $prepared[$index] = $outcome['block'];
                if (isset($outcome['action'])) {
                    $actions[$index] = $outcome['action'];
                }
            }
        }

        return compact('prepared', 'results', 'actions');
    }

    /** @internal */
    public function executePreparedToolBlock(
        array $block,
        ToolUseContext $context,
        ?callable $onStart = null,
        ?callable $onComplete = null,
    ): array {
        $block['_haocode_prepared'] = true;

        return $this->executeSingleTool($block, $context, $onStart, $onComplete);
    }

    public function resetSkillScope(): void
    {
        $this->skillScope()->reset();
    }

    /**
     * Install a run-lifetime capability envelope (forked skills). Call before
     * {@see AgentLoop::run()} so resetSkillScope restores this envelope.
     *
     * @param  list<string>|null  $allowedTools
     * @internal
     */
    public function setBaseSkillScope(?array $allowedTools): void
    {
        $this->skillScope()->setBase($allowedTools);
    }

    /** @internal */
    public function restoreSkillScope(
        ?array $allowedTools,
        ?string $modelOverride,
        ?string $context,
    ): void
    {
        $this->skillScope()->restore($allowedTools, $modelOverride, $context);
    }

    /**
     * Active skill capability specs (may include patterns like Bash(cargo:*)).
     *
     * @return list<string>|null
     */
    public function getActiveSkillAllowedTools(): ?array
    {
        return $this->skillScope()->getAllowed();
    }

    /**
     * Tool names advertised to the model under the active scope (patterns stripped).
     *
     * @return string[]|null @internal
     */
    public function getAdvertisedAllowedTools(): ?array
    {
        return $this->skillScope()->getAdvertised($this->resumeAllowedTools);
    }

    public function getActiveSkillModelOverride(): ?string
    {
        return $this->skillScope()->getModelOverride();
    }

    public function getActiveSkillContext(): string
    {
        return $this->skillScope()->getContext();
    }

    /** @internal */
    public function mayRunPreToolUseHook(string $toolName): bool
    {
        return $this->hookExecutor->hasHooksFor('PreToolUse', $toolName);
    }

    /**
     * Parallel workers must not run any tool lifecycle hook.  Post hooks can
     * still mutate files or external state even when the tool itself is
     * read-only, and failure hooks can have the same side effects.
     *
     * @internal
     */
    public function mayRunToolHooks(string $toolName): bool
    {
        foreach (['PreToolUse', 'PostToolUse', 'PostToolUseFailure'] as $event) {
            if ($this->hookExecutor->hasHooksFor($event, $toolName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parallel workers must not execute a tool while an approval callback may
     * need to run.  The callback is owned by the parent conversation and is
     * not safe to invoke from a forked child; a child cannot suspend the
     * parent's model turn waiting for a human decision.
     *
     * @internal
     */
    public function mayRunPermissionPrompts(): bool
    {
        return $this->permissionPromptHandler !== null || $this->enablePermissionInterrupts;
    }

    /**
     * Execute a single tool block (public entry point for streaming executor).
     */
    public function executeToolBlock(
        array $block,
        ToolUseContext $context,
        ?callable $onStart = null,
        ?callable $onComplete = null,
    ): array {
        return $this->executeSingleTool($block, $context, $onStart, $onComplete);
    }

    /**
     * Execute a set of tool_use blocks from the API response.
     * Parallelizes execution of concurrency-safe (read-only) tools.
     *
     * @param array $toolUseBlocks Array of {id, name, input} from API
     * @return array Array of API-format tool_result blocks
     */
    public function executeTools(
        array $toolUseBlocks,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        $ownsBatch = ! $context->hasReadReceiptBatch();
        if ($ownsBatch) {
            $context->beginReadReceiptBatch();
        }
        try {
            $results = $this->executeToolsInBatch(
                $toolUseBlocks,
                $context,
                $onToolStart,
                $onToolComplete,
            );

            if ($ownsBatch) {
                $context->commitReadReceiptBatch();
            }

            return $results;
        } catch (\Throwable $e) {
            if ($ownsBatch) {
                $context->discardReadReceiptBatch();
            }

            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $toolUseBlocks
     * @return array<int, array<string, mixed>>
     */
    private function executeToolsInBatch(
        array $toolUseBlocks,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        if ($context->isAborted()) {
            $results = [];
            foreach ($toolUseBlocks as $block) {
                $result = ToolResult::aborted();
                if ($onToolComplete) {
                    $onToolComplete((string) ($block['name'] ?? ''), $result);
                }
                $results[] = $result->toApiFormat((string) ($block['id'] ?? ''));
            }

            return $results;
        }

        if (count($toolUseBlocks) <= 1) {
            // Single tool: no need for parallelism
            $results = [];
            foreach ($toolUseBlocks as $block) {
                $results[] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
            }
            return $results;
        }

        // Partition into safe (parallelizable) and unsafe (sequential).
        // Preserve original indices so the final results can be re-sorted into
        // call order.  Without this, interleaved blocks like [safe A, unsafe B,
        // safe C] would produce [A, C, B] instead of [A, B, C].
        $safeBlocks = [];   // origIdx => block
        $unsafeBlocks = []; // origIdx => block

        foreach ($toolUseBlocks as $origIdx => $block) {
            $tool = $this->toolRegistry->getTool($block['name']);
            $isSafe = false;
            $preparedBlock = $block;
            $rawInput = $block['input'] ?? null;
            if ($tool !== null && is_array($rawInput)) {
                try {
                    // Run the same validation and normalization pipeline that
                    // sequential execution uses before deciding whether a
                    // worker may be forked.  Otherwise an invalid invocation can
                    // emit onStart from the parent before the child rejects it,
                    // and context-derived fields are missing from that callback.
                    $preparedInput = $this->validateAndNormalizeInput($tool, $rawInput, $context);
                    if ($preparedInput['error'] === null) {
                        $classificationInput = $preparedInput['input'];
                        $isSafe = $tool->isConcurrencySafe($classificationInput)
                            && $tool->isReadOnly($classificationInput)
                            && ! $this->mayRunToolHooks($tool->name())
                            && ! $this->mayRunPermissionPrompts();
                        if ($isSafe) {
                            // Keep the parent callback and the child invocation
                            // on the same observable input.  The child still
                            // re-runs the normal execution pipeline.
                            $preparedBlock['input'] = $classificationInput;
                        }
                    }
                } catch (\Throwable) {
                    // A validation/normalization/classification failure must
                    // fail closed and use the sequential error path.
                    $isSafe = false;
                }
            }
            if ($isSafe) {
                $safeBlocks[$origIdx] = $preparedBlock;
            } else {
                $unsafeBlocks[$origIdx] = $block;
            }
        }

        $results = [];

        // Execute safe tools in parallel using child processes
        if (!empty($safeBlocks)) {
            $parallelResults = $this->executeInParallel($safeBlocks, $context, $onToolStart, $onToolComplete);
            foreach ($parallelResults as $origIdx => $result) {
                $results[$origIdx] = $result;
            }
        }

        // Execute unsafe tools sequentially
        foreach ($unsafeBlocks as $origIdx => $block) {
            $results[$origIdx] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
        }

        // Re-sort by original call order and strip keys
        ksort($results);
        return array_values($results);
    }

    /**
     * Execute safe tools in isolated child processes while preserving call order.
     */
    private function executeInParallel(
        array $blocks,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $executor = new ParallelToolExecutor(
            executeTool: function (
                array $block,
                ToolUseContext $toolContext,
                ?callable $start,
                ?callable $complete,
            ): array {
                return $this->executeSingleTool($block, $toolContext, $start, $complete);
            },
            timeoutSeconds: $this->parallelToolTimeoutSeconds,
        );

        return $executor->execute($blocks, $context, $onStart, $onComplete);
    }

    private function executeSingleTool(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = $block['id'];
        $toolName = $block['name'];
        $input = $block['input'] ?? [];

        $toolSpan = $this->tracer?->startSpan(
            name: "tool.{$toolName}",
            openInferenceKind: PhoenixTracer::KIND_TOOL,
            attributes: [
                'tool.name' => $toolName,
                'tool.call_id' => (string) $toolUseId,
                'input.value' => json_encode($input, JSON_UNESCAPED_UNICODE) ?: '',
                'input.mime_type' => 'application/json',
            ],
        );
        $toolScope = $toolSpan?->activate();

        try {
            $apiResult = $this->executeSingleToolInner($block, $context, $onStart, $onComplete);

            if ($toolSpan !== null) {
                // Route through PhoenixTracer::setAttribute so tool output is
                // masked when redact_messages is on. A direct setAttribute
                // here used to leak file contents / Bash output / MCP payloads
                // regardless of the redaction flag.
                $this->tracer?->setAttribute($toolSpan, 'output.value', (string) ($apiResult['content'] ?? ''));
                $this->tracer?->setAttribute($toolSpan, 'tool.is_error', (bool) ($apiResult['is_error'] ?? false));
            }

            return $apiResult;
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($toolSpan, $e);
            throw $e;
        } finally {
            $toolScope?->detach();
            $toolSpan?->end();
        }
    }

    /**
     * Original executeSingleTool body, wrapped by {@see executeSingleTool()} so
     * the span lifecycle stays out of the permission / hook / validation logic.
     */
    private function executeSingleToolInner(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = $block['id'];
        $toolName = $block['name'];
        $input = $block['input'] ?? [];
        $isPrepared = ($block['_haocode_prepared'] ?? false) === true;

        if ($context->isAborted()) {
            return ToolResult::aborted()->toApiFormat($toolUseId);
        }

        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null || !$tool->isEnabled()) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Unknown tool: {$toolName}",
                'is_error' => true,
            ];
        }

        if (! $isPrepared) {
            // Stages 1-2b: validate and normalize before hooks observe the input.
            $preparedInput = $this->validateAndNormalizeInput($tool, $input, $context);
            if ($preparedInput['error'] !== null) {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => $preparedInput['error'],
                    'is_error' => true,
                ];
            }
            $input = $preparedInput['input'];

            // Stage 3: PreToolUse hooks
            if ($context->isAborted()) {
                return ToolResult::aborted()->toApiFormat($toolUseId);
            }
            $hookResult = $this->hookExecutor->execute('PreToolUse', [
                'tool' => $toolName,
                'input' => $input,
            ], static fn (): bool => $context->isAborted());

            if ($context->isAborted()) {
                return ToolResult::aborted()->toApiFormat($toolUseId);
            }

            if (! $hookResult->allowed) {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => 'Blocked by hook: '.$hookResult->output,
                    'is_error' => true,
                ];
            }

            if ($hookResult->modifiedInput !== null) {
                $preparedInput = $this->validateAndNormalizeInput($tool, $hookResult->modifiedInput, $context);
                if ($preparedInput['error'] !== null) {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => $preparedInput['error'],
                        'is_error' => true,
                    ];
                }
                $input = $preparedInput['input'];
            }

            // Stage 4: Permission check
            $decision = $this->permissionChecker->check($tool, $input, $context);

            if (! $decision->allowed) {
                // Only prompt the user for "ask" decisions (needsPrompt=true).
                // Hard "deny" decisions (deny rules, plan-mode writes) must never be
                // overridden by a permission prompt — they should always fail immediately.
                if ($decision->needsPrompt && $this->permissionPromptHandler) {
                    $userApproved = ($this->permissionPromptHandler)($toolName, $input);
                    if (! $userApproved) {
                        return [
                            'tool_use_id' => $toolUseId,
                            'content' => 'Permission denied by user',
                            'is_error' => true,
                        ];
                    }
                } else {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => "Permission denied: ".($decision->reason ?? 'Not allowed'),
                        'is_error' => true,
                    ];
                }
            }
        }

        if (! $this->isAllowedByActiveSkillScope($toolName, is_array($input) ? $input : [])) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Tool {$toolName} is not allowed by the active skill scope.",
                'is_error' => true,
            ];
        }

        if ($context->isAborted()) {
            return ToolResult::aborted()->toApiFormat($toolUseId);
        }

        if ($onStart) {
            $onStart($toolName, $input);
        }

        if ($context->isAborted()) {
            $result = ToolResult::aborted();
            if ($onComplete) {
                $onComplete($toolName, $result);
            }

            return $result->toApiFormat($toolUseId);
        }

        // Execute the tool
        try {
            $result = $tool->call($input, $context);
            if ($context->isAborted() || $result->outcome() === ToolOutcome::Aborted) {
                $result = $result->outcome() === ToolOutcome::Aborted
                    ? $result
                    : ToolResult::aborted();
            } else {
                $this->activateSkillScope($toolName, $result, $context);

                // PostToolUse hooks (success path)
                $postHookResult = $this->hookExecutor->execute('PostToolUse', [
                    'tool' => $toolName,
                    'input' => $input,
                    'output' => $result->output,
                    'isError' => $result->isError,
                ], static fn (): bool => $context->isAborted());

                if ($context->isAborted()) {
                    $result = ToolResult::aborted();
                } elseif ($postHookResult->output) {
                    $result = new ToolResult(
                        output: $result->output . "\n[Hook] " . $postHookResult->output,
                        isError: $result->isError,
                        metadata: $result->metadata,
                    );
                }
            }
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($context->isAborted()) {
                $result = ToolResult::aborted();
            } else {
                $result = ToolResult::error("Tool execution error: " . $e->getMessage());

                // PostToolUseFailure hooks (error path)
                $failHookResult = $this->hookExecutor->execute('PostToolUseFailure', [
                    'tool' => $toolName,
                    'input' => $input,
                    'error' => $e->getMessage(),
                ], static fn (): bool => $context->isAborted());

                if ($context->isAborted()) {
                    $result = ToolResult::aborted();
                } elseif ($failHookResult->output) {
                    $result = new ToolResult(
                        output: $result->output . "\n[Hook] " . $failHookResult->output,
                        isError: true,
                        metadata: $result->metadata,
                    );
                }
            }
        }

        // Repeated-Read nudge: when an agent reads the same file many times
        // without editing it, it's almost always "I forgot what I just saw"
        // rather than a legitimate use case. Append a short hint into the
        // tool_result so the agent is reminded to reuse the content it already
        // has. A Write/Edit on the same path resets the counter because after
        // a mutation re-reading is expected.
        if ($result->outcome() !== ToolOutcome::Aborted) {
            $result = $this->annotateRepeatedReads($toolName, $input, $result);
        }

        // Persist large results to disk (or truncate as fallback)
        $toolMaxChars = $tool->maxResultSizeChars();
        $maxChars = min($toolMaxChars, ToolResultStorage::MAX_SINGLE_RESULT_CHARS);
        $resultWasCompacted = false;
        if ($result->outcome() !== ToolOutcome::Aborted
            && mb_strlen($result->output) > $maxChars
        ) {
            if ($toolMaxChars < PHP_INT_MAX && $this->toolResultStorage !== null) {
                $persisted = $this->toolResultStorage->persist($toolUseId, $result->output);
                if ($persisted !== null) {
                    $result = new ToolResult(
                        output: $persisted['message'],
                        isError: $result->isError,
                        metadata: $result->metadata,
                    );
                    $resultWasCompacted = true;
                }
            }
            // Fallback: inline truncation if persistence failed or unavailable
            if (mb_strlen($result->output) > $maxChars) {
                $storage = $this->toolResultStorage ?? new ToolResultStorage();
                $preview = $storage->generatePreview($result->output, ToolResultStorage::PREVIEW_SIZE_BYTES);
                $sizeLabel = round(mb_strlen($result->output) / 1024, 1) . 'K chars';
                $result = new ToolResult(
                    output: "<persisted-output>\nOutput too large ({$sizeLabel}). Showing first 2KB preview:\n\n{$preview}\n...(truncated)\n</persisted-output>",
                    isError: $result->isError,
                    metadata: $result->metadata,
                );
                $resultWasCompacted = true;
            }
        }
        if ($resultWasCompacted && $toolName === 'Read'
            && is_string($input['file_path'] ?? null)
        ) {
            $context->markFileReadIncomplete($input['file_path']);
        }

        if ($onComplete) {
            $onComplete($toolName, $result);
        }

        return $result->toApiFormat($toolUseId);
    }

    /** @return array{block?: array, result?: array, action?: HumanActionRequest} */
    private function prepareOneForHumanReview(array $block, ToolUseContext $context, bool $suppressConfiguredGate): array
    {
        $toolUseId = (string) ($block['id'] ?? '');
        $toolName = (string) ($block['name'] ?? '');
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
        $tool = $this->toolRegistry->getTool($toolName);

        $error = static fn (string $message): array => ['result' => [
            'tool_use_id' => $toolUseId,
            'content' => $message,
            'is_error' => true,
        ]];

        if ($context->isAborted()) {
            return $error('Tool execution aborted');
        }

        if ($tool === null || ! $tool->isEnabled()) {
            return $error("Unknown tool: {$toolName}");
        }

        $preparedInput = $this->validateAndNormalizeInput($tool, $input, $context);
        if ($preparedInput['error'] !== null) {
            return $error($preparedInput['error']);
        }
        $input = $preparedInput['input'];

        $hookResult = $this->hookExecutor->execute(
            'PreToolUse',
            ['tool' => $toolName, 'input' => $input],
            static fn (): bool => $context->isAborted(),
        );
        if ($context->isAborted()) {
            return $error('Tool execution aborted');
        }
        if (! $hookResult->allowed) {
            return $error('Blocked by hook: '.$hookResult->output);
        }
        if ($hookResult->modifiedInput !== null) {
            $preparedInput = $this->validateAndNormalizeInput($tool, $hookResult->modifiedInput, $context);
            if ($preparedInput['error'] !== null) {
                return $error($preparedInput['error']);
            }
            $input = $preparedInput['input'];
        }

        if (! $this->isAllowedByActiveSkillScope($toolName, $input)) {
            return $error("Tool {$toolName} is not allowed by the active skill scope.");
        }

        $decision = $this->permissionChecker->check($tool, $input, $context);
        if (! $decision->allowed && ! $decision->needsPrompt) {
            return $error('Permission denied: '.($decision->reason ?? 'Not allowed'));
        }

        $configured = $this->interruptOn[$toolName] ?? false;
        $shouldInterrupt = ! $suppressConfiguredGate && (
            $decision->needsPrompt
            || $configured !== false
            || ($toolName === 'AskUserQuestion' && $this->enableAskUser)
        );

        $preparedBlock = $block;
        $preparedBlock['input'] = $input;

        if (! $shouldInterrupt) {
            return ['block' => $preparedBlock];
        }

        $allowed = ['approve', 'edit', 'reject', 'respond'];
        $description = $decision->reason ?? "Approve {$toolName}";
        if (is_array($configured)) {
            if (is_array($configured['allowedDecisions'] ?? null)) {
                $allowed = array_values(array_intersect($allowed, $configured['allowedDecisions']));
            }
            if (is_string($configured['description'] ?? null) && trim($configured['description']) !== '') {
                $description = trim($configured['description']);
            }
        }
        if ($toolName === 'AskUserQuestion') {
            $allowed = ['respond', 'reject'];
            $description = 'Answer the agent question';
        }
        if ($allowed === []) {
            return $error("No valid human decisions configured for {$toolName}.");
        }

        return [
            'block' => $preparedBlock,
            'action' => new HumanActionRequest(
                id: $toolUseId,
                toolName: $toolName,
                input: $input,
                description: $description,
                allowedDecisions: $allowed,
                agentId: $context->runContext?->agentId,
            ),
        ];
    }

    /**
     * Apply the complete validation and observable-input normalization pipeline.
     *
     * @return array{input: array, error: ?string}
     */
    private function validateAndNormalizeInput(
        ToolInterface $tool,
        array $input,
        ToolUseContext $context,
    ): array {
        try {
            $input = $tool->inputSchema()->validate($input);
        } catch (\InvalidArgumentException $e) {
            return [
                'input' => [],
                'error' => '<tool_use_error>InputValidationError: '.$e->getMessage().'</tool_use_error>',
            ];
        }

        $validationError = $tool->validateInput($input, $context);
        if ($validationError !== null) {
            return [
                'input' => [],
                'error' => '<tool_use_error>Validation: '.$validationError.'</tool_use_error>',
            ];
        }

        return [
            'input' => $tool->backfillObservableInput($input, $context),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isAllowedByActiveSkillScope(string $toolName, array $input = []): bool
    {
        return $this->skillScope()->allows($toolName, $input, $this->resumeAllowedTools);
    }

    private function activateSkillScope(string $toolName, ToolResult $result, ?ToolUseContext $toolContext = null): void
    {
        $this->skillScope()->activate($toolName, $result, $toolContext);
    }

    /**
     * Track per-file Read counts and, above the threshold, append a short
     * hint to the Read result. Write/Edit on the same path resets the count.
     */
    private function annotateRepeatedReads(string $toolName, array $input, ToolResult $result): ToolResult
    {
        if ($result->isError) {
            return $result;
        }

        $path = $input['file_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return $result;
        }

        if ($toolName === 'Write' || $toolName === 'Edit' || $toolName === 'NotebookEdit') {
            unset($this->readCountsByFile[$path]);

            return $result;
        }

        if ($toolName !== 'Read') {
            return $result;
        }

        $count = ($this->readCountsByFile[$path] ?? 0) + 1;
        $this->readCountsByFile[$path] = $count;

        if ($count < self::REPEATED_READ_HINT_THRESHOLD) {
            return $result;
        }

        $hint = "\n\n[hint] You have now read {$path} {$count} times this session without modifying it. "
              . 'If you are paginating a large file that is fine, but otherwise prefer reusing the content '
              . 'you already have in memory rather than re-reading.';

        return new ToolResult(
            output: $result->output . $hint,
            isError: false,
            metadata: $result->metadata,
        );
    }
}
