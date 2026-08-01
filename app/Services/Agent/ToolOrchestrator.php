<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Tools\Skill\SkillCapability;
use HaoCode\Tools\Skill\SkillModelResolver;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;

class ToolOrchestrator
{
    /** Appended to a successful Read's output once the same file has been read
     *  this many times without an intervening Write/Edit on the same path. */
    private const REPEATED_READ_HINT_THRESHOLD = 4;

    private const MAX_PARALLEL_TOOLS = 8;
    private const MAX_IPC_PAYLOAD_BYTES = 1_000_000;
    private const MAX_IPC_TOOL_ID_BYTES = 4_096;
    private const DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS = 120.0;

    private $permissionPromptHandler = null;
    private ?ToolResultStorage $toolResultStorage = null;
    /** @var array<string, int> raw file_path → successful Read count (this session) */
    private array $readCountsByFile = [];
    /**
     * Capability specs allowed by active skills (e.g. "Read", "Bash(cargo:*)").
     * null means unrestricted.
     *
     * @var list<string>|null
     */
    private ?array $activeSkillAllowedTools = null;

    /**
     * Optional base capability envelope (e.g. forked skill child runs). Survives
     * {@see resetSkillScope()} so fork constraints remain for the whole child run.
     *
     * @var list<string>|null
     */
    private ?array $baseSkillAllowedTools = null;

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
        private readonly float $parallelToolTimeoutSeconds = self::DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS,
    ) {
        if (! is_finite($this->parallelToolTimeoutSeconds) || $this->parallelToolTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Parallel tool timeout must be greater than zero.');
        }
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
        $this->activeSkillAllowedTools = $this->baseSkillAllowedTools;
        $this->activeSkillModelOverride = null;
        $this->activeSkillContext = 'inline';
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
        $this->baseSkillAllowedTools = $allowedTools === null
            ? null
            : SkillCapability::normalizeSpecs($allowedTools);
        $this->activeSkillAllowedTools = $this->baseSkillAllowedTools;
    }

    /** @internal */
    public function restoreSkillScope(
        ?array $allowedTools,
        ?string $modelOverride,
        ?string $context,
    ): void
    {
        $normalized = $allowedTools === null
            ? null
            : SkillCapability::normalizeSpecs($allowedTools);
        // Resume snapshots restore the active scope on top of any base envelope.
        $this->activeSkillAllowedTools = $normalized === null || $this->baseSkillAllowedTools === null
            ? $normalized
            : SkillCapability::intersect($this->baseSkillAllowedTools, $normalized);
        $this->activeSkillModelOverride = is_string($modelOverride) && trim($modelOverride) !== ''
            ? trim($modelOverride)
            : null;
        $this->activeSkillContext = in_array($context, ['inline', 'fork'], true)
            ? $context
            : 'inline';
    }

    /**
     * Active skill capability specs (may include patterns like Bash(cargo:*)).
     *
     * @return list<string>|null
     */
    public function getActiveSkillAllowedTools(): ?array
    {
        return $this->activeSkillAllowedTools;
    }

    /**
     * Tool names advertised to the model under the active scope (patterns stripped).
     *
     * @return string[]|null @internal
     */
    public function getAdvertisedAllowedTools(): ?array
    {
        $skillTools = $this->activeSkillAllowedTools === null
            ? null
            : SkillCapability::toolNames($this->activeSkillAllowedTools);

        if ($this->resumeAllowedTools === null) {
            return $skillTools;
        }
        if ($skillTools === null) {
            return $this->resumeAllowedTools;
        }

        return array_values(array_intersect($this->resumeAllowedTools, $skillTools));
    }

    public function getActiveSkillModelOverride(): ?string
    {
        return $this->activeSkillModelOverride;
    }

    public function getActiveSkillContext(): string
    {
        return $this->activeSkillContext;
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
            return array_map(
                static fn (array $block): array => ToolResult::aborted()->toApiFormat(
                    (string) ($block['id'] ?? ''),
                ),
                $toolUseBlocks,
            );
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
            $classificationInput = $block['input'] ?? [];
            if ($tool?->name() === 'Agent') {
                $classificationInput = $tool->backfillObservableInput($classificationInput, $context);
            }
            if ($tool
                && $tool->isConcurrencySafe($classificationInput)
                && $tool->isReadOnly($classificationInput)
                && ! $this->mayRunToolHooks($tool->name())) {
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

        if (count($blocks) > self::MAX_PARALLEL_TOOLS) {
            $results = [];
            foreach (array_chunk($blocks, self::MAX_PARALLEL_TOOLS, true) as $chunk) {
                foreach ($this->executeInParallel($chunk, $context, $onStart, $onComplete) as $idx => $result) {
                    $results[$idx] = $result;
                }
            }

            return $results;
        }

        // Use temp files for IPC
        $tempFiles = [];
        $pids = [];
        $results = [];
        $completedResults = [];

        // A parent-side cancellation callback cannot be observed by a child
        // after fork (the child owns a copy of the callback state). Keep the
        // terminal aborted result construction in one place so cancellation
        // while children are running does not leave missing results or
        // completion callbacks behind.
        $recordAborted = function (int $idx) use (&$results, &$completedResults, $blocks, $onComplete): void {
            $result = ToolResult::aborted();
            $results[$idx] = $result->toApiFormat((string) ($blocks[$idx]['id'] ?? ''));
            $completedResults[$idx] = $result;
            if ($onComplete) {
                $onComplete($blocks[$idx]['name'], $result);
            }
        };
        $recordTimedOut = function (int $idx) use (&$results, &$completedResults, $blocks, $onComplete): void {
            $result = ToolResult::error(
                'Tool execution timed out.',
                ['timedOut' => true],
            );
            $results[$idx] = $result->toApiFormat((string) ($blocks[$idx]['id'] ?? ''));
            $completedResults[$idx] = $result;
            if ($onComplete) {
                $onComplete($blocks[$idx]['name'], $result);
            }
        };

        // Capture the parent's readFileState snapshot before forking so we can
        // detect which entries the child added.
        $parentStateBefore = $context->getReadFileStateSnapshot();
        $deadline = microtime(true) + $this->parallelToolTimeoutSeconds;

        foreach ($blocks as $idx => $block) {
            // Do not start more children after a start callback (or another
            // concurrent signal) has cancelled the batch.
            if ($context->isAborted()) {
                $recordAborted($idx);
                continue;
            }

            // Use tempnam() for an unpredictable, 0600-mode filename instead of
            // a guessable "<prefix>_<idx>_<pid>" — predictable names let other
            // local users race or symlink-swap the IPC file.
            $tempFile = $this->allocateIpcTempFile('haocode_tool_');
            $tempFiles[$idx] = $tempFile;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Fork failed, execute inline
                @unlink($tempFile);
                $results[$idx] = $this->executeSingleTool($block, $context, $onStart, $onComplete);
                unset($tempFiles[$idx]);
                continue;
            }

            if ($pid === 0) {
                if (function_exists('posix_setsid')) {
                    @posix_setsid();
                }
                // Child process
                $completedResult = null;
                $result = $this->executeSingleTool(
                    $block,
                    $context,
                    null,
                    static function (string $toolName, ToolResult $toolResult) use (&$completedResult): void {
                        $completedResult = $toolResult;
                    },
                );
                // Serialize both the tool result and any readFileState changes so the
                // parent can merge them back (fixes read-before-write across fork).
                $childState = $context->getReadFileStateSnapshot();
                $newEntries = array_filter(
                    $childState,
                    static fn (array $value, string $path): bool =>
                        ! isset($parentStateBefore[$path]) || $parentStateBefore[$path] !== $value,
                    ARRAY_FILTER_USE_BOTH,
                );
                $payload = [
                    'result' => $result,
                    'toolResult' => $completedResult?->toArray(),
                    'readState' => $newEntries,
                ];
                $this->writeIpcPayload($tempFile, $payload, $block);
                exit(0);
            }

            // Parent
            $pids[$idx] = $pid;
            if ($onStart) {
                $onStart($block['name'], $block['input'] ?? []);
            }
        }

        $finalizeChild = function (int $idx) use (
            &$results,
            &$completedResults,
            &$tempFiles,
            $blocks,
            $context,
            $onComplete,
        ): void {
            if (! isset($results[$idx])) {
                $data = false;
                if (isset($tempFiles[$idx]) && file_exists($tempFiles[$idx])) {
                    // allowed_classes => false blocks PHP object injection even if a
                    // gadget chain is present in dependencies and an attacker can
                    // influence the file contents.
                    $rawPayload = $this->readIpcPayload($tempFiles[$idx]);
                    $data = $rawPayload === false
                        ? false
                        : @unserialize($rawPayload, ['allowed_classes' => false]);
                }

                if (is_array($data) && isset($data['result'])) {
                    // New format: result + readState
                    $results[$idx] = $data['result'];
                    if (is_array($data['toolResult'] ?? null)) {
                        try {
                            $completedResults[$idx] = ToolResult::fromArray($data['toolResult']);
                        } catch (\InvalidArgumentException) {
                            // Corrupt or legacy IPC payload: reconstruct below.
                        }
                    }
                    if (! empty($data['readState'])) {
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
            }

            if (isset($tempFiles[$idx])) {
                @unlink($tempFiles[$idx]);
            }
            if ($onComplete) {
                $toolName = $blocks[$idx]['name'];
                $result = $completedResults[$idx] ?? new ToolResult(
                    output: (string) ($results[$idx]['content'] ?? ''),
                    isError: (bool) ($results[$idx]['is_error'] ?? false),
                );
                $onComplete($toolName, $result);
            }
        };

        // Poll instead of blocking in pcntl_waitpid(). This keeps the parent
        // responsive to cancellation and lets it terminate every remaining
        // process tree instead of waiting for the first hung child forever.
        $remaining = $pids;
        while ($remaining !== []) {
            $madeProgress = false;
            foreach ($remaining as $idx => $pid) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === -1) {
                    // Signals can interrupt a non-blocking wait. Do not mark
                    // the child complete on EINTR; it may still be running.
                    // A non-EINTR -1 means the child is no longer waitable
                    // (for example, a host signal handler reaped it), so the
                    // existing payload/error finalization remains the safest
                    // terminal path.
                    $interrupted = defined('PCNTL_EINTR')
                        && function_exists('pcntl_get_last_error')
                        && pcntl_get_last_error() === constant('PCNTL_EINTR');
                    if ($interrupted) {
                        continue;
                    }
                }
                if ($waited === $pid || $waited === -1) {
                    $finalizeChild($idx);
                    unset($remaining[$idx]);
                    $madeProgress = true;
                }
            }

            if ($remaining === []) {
                break;
            }

            if ($context->isAborted()) {
                foreach ($remaining as $pid) {
                    \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                }
                foreach (array_keys($remaining) as $idx) {
                    $status = 0;
                    @pcntl_waitpid($pids[$idx], $status);
                    $recordAborted($idx);
                    if (isset($tempFiles[$idx])) {
                        @unlink($tempFiles[$idx]);
                    }
                    unset($remaining[$idx]);
                }
                break;
            }

            if (microtime(true) >= $deadline) {
                foreach ($remaining as $pid) {
                    \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                }
                foreach (array_keys($remaining) as $idx) {
                    $status = 0;
                    @pcntl_waitpid($pids[$idx], $status);
                    $recordTimedOut($idx);
                    if (isset($tempFiles[$idx])) {
                        @unlink($tempFiles[$idx]);
                    }
                    unset($remaining[$idx]);
                }
                break;
            }

            if (! $madeProgress) {
                usleep(10_000);
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

    /** @param array<string, mixed> $payload */
    private function writeIpcPayload(string $tempFile, array $payload, array $block): void
    {
        $serialized = serialize($payload);
        if (strlen($serialized) > self::MAX_IPC_PAYLOAD_BYTES) {
            $fallback = ToolResult::error('Tool result exceeded IPC size limit.');
            $serialized = serialize([
                'result' => $fallback->toApiFormat($this->boundedToolUseId($block)),
                'toolResult' => $fallback->toArray(),
                'readState' => [],
            ]);
        }

        file_put_contents($tempFile, $serialized);
    }

    /** @param array<string, mixed> $block */
    private function boundedToolUseId(array $block): string
    {
        $id = $block['id'] ?? '';
        if (! is_scalar($id)) {
            return '';
        }

        return substr((string) $id, 0, self::MAX_IPC_TOOL_ID_BYTES);
    }

    private function readIpcPayload(string $tempFile): string|false
    {
        $size = @filesize($tempFile);
        if ($size === false || $size > self::MAX_IPC_PAYLOAD_BYTES) {
            return false;
        }

        return @file_get_contents($tempFile);
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
        if ($this->resumeAllowedTools !== null && ! in_array($toolName, $this->resumeAllowedTools, true)) {
            return false;
        }
        if ($this->activeSkillAllowedTools === null || $toolName === 'Skill') {
            return true;
        }

        return SkillCapability::allows($this->activeSkillAllowedTools, $toolName, $input);
    }

    private function activateSkillScope(string $toolName, ToolResult $result, ?ToolUseContext $toolContext = null): void
    {
        if ($toolName !== 'Skill' || $result->isError || $result->metadata === null) {
            return;
        }

        $allowedTools = $result->metadata['allowed_tools'] ?? [];
        $context = $result->metadata['context'] ?? 'inline';
        if ($context !== 'fork' && is_array($allowedTools) && $allowedTools !== []) {
            try {
                $normalized = SkillCapability::normalizeSpecs($allowedTools);
            } catch (\InvalidArgumentException $e) {
                // Invalid capability specs must not widen permissions.
                $this->activeSkillAllowedTools = $this->activeSkillAllowedTools ?? [];

                return;
            }

            $combined = $this->activeSkillAllowedTools === null
                ? $normalized
                : SkillCapability::intersect($this->activeSkillAllowedTools, $normalized);
            // Never escape a forked skill's base envelope.
            $this->activeSkillAllowedTools = $this->baseSkillAllowedTools === null
                ? $combined
                : SkillCapability::intersect($this->baseSkillAllowedTools, $combined);
        }

        $modelOverride = $result->metadata['model_override'] ?? null;
        if ($context !== 'fork' && is_string($modelOverride) && trim($modelOverride) !== '') {
            $providerType = $toolContext?->runContext?->settings->getProviderType() ?? 'anthropic';
            try {
                $resolved = SkillModelResolver::resolve(trim($modelOverride), $providerType);
                $this->activeSkillModelOverride = $resolved;
            } catch (\InvalidArgumentException) {
                // Keep prior override rather than applying an invalid alias.
            }
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
