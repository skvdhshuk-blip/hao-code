<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolResult;

class ToolOrchestrator
{
    /** Appended to a successful Read's output once the same file has been read
     *  this many times without an intervening Write/Edit on the same path. */
    private const REPEATED_READ_HINT_THRESHOLD = 4;

    private $permissionPromptHandler = null;
    private ?ToolResultStorage $toolResultStorage = null;
    /** @var array<string, int> raw file_path → successful Read count (this session) */
    private array $readCountsByFile = [];
    /** @var string[]|null Tool names allowed by active skills; null means unrestricted. */
    private ?array $activeSkillAllowedTools = null;
    private ?string $activeSkillModelOverride = null;
    private string $activeSkillContext = 'inline';

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
    ) {}

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
        $this->activeSkillAllowedTools = null;
        $this->activeSkillModelOverride = null;
        $this->activeSkillContext = 'inline';
    }

    /** @return string[]|null */
    public function getActiveSkillAllowedTools(): ?array
    {
        return $this->activeSkillAllowedTools;
    }

    /** @return string[]|null @internal */
    public function getAdvertisedAllowedTools(): ?array
    {
        if ($this->resumeAllowedTools === null) {
            return $this->activeSkillAllowedTools;
        }
        if ($this->activeSkillAllowedTools === null) {
            return $this->resumeAllowedTools;
        }

        return array_values(array_intersect($this->resumeAllowedTools, $this->activeSkillAllowedTools));
    }

    public function getActiveSkillModelOverride(): ?string
    {
        return $this->activeSkillModelOverride;
    }

    public function getActiveSkillContext(): string
    {
        return $this->activeSkillContext;
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
            if ($tool && $tool->isConcurrencySafe($block['input'] ?? []) && $tool->isReadOnly($block['input'] ?? [])) {
                $safeBlocks[$origIdx] = $block;
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
     * Execute safe tools in parallel using proc_open.
     */
    private function executeInParallel(
        array $blocks,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        // For small counts, just run concurrently with non-blocking approach
        // PHP doesn't have native async, so use fork-based parallelism when available
        if (!function_exists('pcntl_fork')) {
            // Fallback to sequential. Preserve original block indices so the
            // caller can still re-sort into call order — using $results[] here
            // would re-index from 0 and overwrite interleaved unsafe results.
            $results = [];
            foreach ($blocks as $idx => $block) {
                $results[$idx] = $this->executeSingleTool($block, $context, $onStart, $onComplete);
            }
            return $results;
        }

        // Use temp files for IPC
        $tempFiles = [];
        $pids = [];
        $results = [];

        // Capture the parent's readFileState snapshot before forking so we can
        // detect which entries the child added.
        $parentStateBefore = $context->getReadFileStateSnapshot();

        foreach ($blocks as $idx => $block) {
            // Use tempnam() for an unpredictable, 0600-mode filename instead of
            // a guessable "<prefix>_<idx>_<pid>" — predictable names let other
            // local users race or symlink-swap the IPC file.
            $tempFile = $this->allocateIpcTempFile('haocode_tool_');
            $tempFiles[$idx] = $tempFile;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Fork failed, execute inline
                $results[$idx] = $this->executeSingleTool($block, $context, $onStart, $onComplete);
                unset($tempFiles[$idx]);
                continue;
            }

            if ($pid === 0) {
                // Child process
                $result = $this->executeSingleTool($block, $context, null, null);
                // Serialize both the tool result and any readFileState changes so the
                // parent can merge them back (fixes read-before-write across fork).
                $childState = $context->getReadFileStateSnapshot();
                $newEntries = array_diff_key($childState, $parentStateBefore);
                $payload = ['result' => $result, 'readState' => $newEntries];
                file_put_contents($tempFile, serialize($payload));
                exit(0);
            }

            // Parent
            $pids[$idx] = $pid;
            if ($onStart) {
                $onStart($block['name'], $block['input'] ?? []);
            }
        }

        // Wait for all children
        foreach ($pids as $idx => $pid) {
            pcntl_waitpid($pid, $status);
            if (isset($tempFiles[$idx]) && file_exists($tempFiles[$idx])) {
                // allowed_classes => false blocks PHP object injection even if a
                // gadget chain is present in dependencies and an attacker can
                // influence the file contents.
                $data = @unserialize(
                    (string) file_get_contents($tempFiles[$idx]),
                    ['allowed_classes' => false],
                );
                if (is_array($data) && isset($data['result'])) {
                    // New format: result + readState
                    $results[$idx] = $data['result'];
                    if (!empty($data['readState'])) {
                        $context->mergeReadFileStateSnapshot($data['readState']);
                    }
                } elseif (is_array($data)) {
                    // Legacy format: bare result (backward compat)
                    $results[$idx] = $data;
                } else {
                    $results[$idx] = [
                        'tool_use_id' => $blocks[$idx]['id'],
                        'content' => 'Failed to read parallel result',
                        'is_error' => true,
                    ];
                }
                @unlink($tempFiles[$idx]);
            }
            if ($onComplete) {
                $toolName = $blocks[$idx]['name'];
                $result = new ToolResult(
                    output: (string) ($results[$idx]['content'] ?? ''),
                    isError: (bool) ($results[$idx]['is_error'] ?? false),
                );
                $onComplete($toolName, $result);
            }
        }

        // Return with original block indices intact so the caller can re-sort them
        // into the correct call order.
        return $results;
    }

    /**
     * Allocate a private, unpredictable IPC temp file.
     *
     * tempnam() returns a path under the system temp dir with a random suffix
     * and 0600 permissions, replacing the previous guessable
     * "haocode_tool_<idx>_<pid>" / "haocode_stream_<idx>_<pid>_<toolCallId>"
     * names that were vulnerable to symlink-swap and predictable-file races.
     * The caller owns the returned file and must unlink it when done.
     */
    private function allocateIpcTempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Could not allocate IPC temp file.');
        }
        // tempnam() already creates the file with 0600 on most platforms; chmod
        // again defensively in case umask or a custom tmpdir changed that.
        @chmod($path, 0600);

        return $path;
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

        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null || !$tool->isEnabled()) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Unknown tool: {$toolName}",
                'is_error' => true,
            ];
        }

        if (! $this->isAllowedByActiveSkillScope($toolName)) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Tool {$toolName} is not allowed by the active skill scope.",
                'is_error' => true,
            ];
        }

        if (! $isPrepared) {
        // Stage 1: Schema validation
        try {
            $input = $tool->inputSchema()->validate($input);
        } catch (\InvalidArgumentException $e) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => '<tool_use_error>InputValidationError: ' . $e->getMessage() . '</tool_use_error>',
                'is_error' => true,
            ];
        }

        // Stage 2: Tool-specific semantic validation
        $validationError = $tool->validateInput($input, $context);
        if ($validationError !== null) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => '<tool_use_error>Validation: ' . $validationError . '</tool_use_error>',
                'is_error' => true,
            ];
        }

        // Stage 2b: Normalize input paths before hooks/permissions observe them
        $input = $tool->backfillObservableInput($input, $context);

        // Stage 3: PreToolUse hooks
        $hookResult = $this->hookExecutor->execute('PreToolUse', [
            'tool' => $toolName,
            'input' => $input,
        ]);

        if (!$hookResult->allowed) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => 'Blocked by hook: ' . $hookResult->output,
                'is_error' => true,
            ];
        }

        if ($hookResult->modifiedInput !== null) {
            $input = $hookResult->modifiedInput;
        }

        // Stage 4: Permission check
        $decision = $this->permissionChecker->check($tool, $input, $context);

        if (!$decision->allowed) {
            // Only prompt the user for "ask" decisions (needsPrompt=true).
            // Hard "deny" decisions (deny rules, plan-mode writes) must never be
            // overridden by a permission prompt — they should always fail immediately.
            if ($decision->needsPrompt && $this->permissionPromptHandler) {
                $userApproved = ($this->permissionPromptHandler)($toolName, $input);
                if (!$userApproved) {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => 'Permission denied by user',
                        'is_error' => true,
                    ];
                }
            } else {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => "Permission denied: " . ($decision->reason ?? 'Not allowed'),
                    'is_error' => true,
                ];
            }
        }
        }

        if ($onStart) {
            $onStart($toolName, $input);
        }

        // Execute the tool
        try {
            $result = $tool->call($input, $context);
            $this->activateSkillScope($toolName, $result);

            // PostToolUse hooks (success path)
            $postHookResult = $this->hookExecutor->execute('PostToolUse', [
                'tool' => $toolName,
                'input' => $input,
                'output' => $result->output,
                'isError' => $result->isError,
            ]);

            if ($postHookResult->output) {
                $result = new ToolResult(
                    output: $result->output . "\n[Hook] " . $postHookResult->output,
                    isError: $result->isError,
                    metadata: $result->metadata,
                );
            }
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $result = ToolResult::error("Tool execution error: " . $e->getMessage());

            // PostToolUseFailure hooks (error path)
            $failHookResult = $this->hookExecutor->execute('PostToolUseFailure', [
                'tool' => $toolName,
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            if ($failHookResult->output) {
                $result = new ToolResult(
                    output: $result->output . "\n[Hook] " . $failHookResult->output,
                    isError: true,
                    metadata: $result->metadata,
                );
            }
        }

        // Repeated-Read nudge: when an agent reads the same file many times
        // without editing it, it's almost always "I forgot what I just saw"
        // rather than a legitimate use case. Append a short hint into the
        // tool_result so the agent is reminded to reuse the content it already
        // has. A Write/Edit on the same path resets the counter because after
        // a mutation re-reading is expected.
        $result = $this->annotateRepeatedReads($toolName, $input, $result);

        // Persist large results to disk (or truncate as fallback)
        $toolMaxChars = $tool->maxResultSizeChars();
        $maxChars = min($toolMaxChars, ToolResultStorage::MAX_SINGLE_RESULT_CHARS);
        if (mb_strlen($result->output) > $maxChars) {
            if ($toolMaxChars < PHP_INT_MAX && $this->toolResultStorage !== null) {
                $persisted = $this->toolResultStorage->persist($toolUseId, $result->output);
                if ($persisted !== null) {
                    $result = new ToolResult(
                        output: $persisted['message'],
                        isError: $result->isError,
                        metadata: $result->metadata,
                    );
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
            }
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

        if ($tool === null || ! $tool->isEnabled()) {
            return $error("Unknown tool: {$toolName}");
        }
        if (! $this->isAllowedByActiveSkillScope($toolName)) {
            return $error("Tool {$toolName} is not allowed by the active skill scope.");
        }

        try {
            $input = $tool->inputSchema()->validate($input);
        } catch (\InvalidArgumentException $e) {
            return $error('<tool_use_error>InputValidationError: '.$e->getMessage().'</tool_use_error>');
        }

        $validationError = $tool->validateInput($input, $context);
        if ($validationError !== null) {
            return $error('<tool_use_error>Validation: '.$validationError.'</tool_use_error>');
        }

        $input = $tool->backfillObservableInput($input, $context);
        $hookResult = $this->hookExecutor->execute('PreToolUse', ['tool' => $toolName, 'input' => $input]);
        if (! $hookResult->allowed) {
            return $error('Blocked by hook: '.$hookResult->output);
        }
        if ($hookResult->modifiedInput !== null) {
            $input = $hookResult->modifiedInput;
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

    private function isAllowedByActiveSkillScope(string $toolName): bool
    {
        if ($this->resumeAllowedTools !== null && ! in_array($toolName, $this->resumeAllowedTools, true)) {
            return false;
        }
        if ($this->activeSkillAllowedTools === null || $toolName === 'Skill') {
            return true;
        }

        return in_array($toolName, $this->activeSkillAllowedTools, true);
    }

    private function activateSkillScope(string $toolName, ToolResult $result): void
    {
        if ($toolName !== 'Skill' || $result->isError || $result->metadata === null) {
            return;
        }

        $allowedTools = $result->metadata['allowed_tools'] ?? [];
        $context = $result->metadata['context'] ?? 'inline';
        if ($context !== 'fork' && is_array($allowedTools) && $allowedTools !== []) {
            $normalized = array_values(array_unique(array_filter(array_map(
                static function (mixed $name): string {
                    $name = trim((string) $name);
                    $patternStart = strpos($name, '(');

                    return $patternStart === false ? $name : substr($name, 0, $patternStart);
                },
                $allowedTools,
            ))));

            $this->activeSkillAllowedTools = $this->activeSkillAllowedTools === null
                ? $normalized
                : array_values(array_intersect($this->activeSkillAllowedTools, $normalized));
        }

        $modelOverride = $result->metadata['model_override'] ?? null;
        if ($context !== 'fork' && is_string($modelOverride) && trim($modelOverride) !== '') {
            $this->activeSkillModelOverride = trim($modelOverride);
        }

        if (is_string($context) && in_array($context, ['inline', 'fork'], true)) {
            $this->activeSkillContext = $context;
        }
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
