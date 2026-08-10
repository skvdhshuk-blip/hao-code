<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolResult;

/**
 * Executes tools as they stream in from the API, not after the full response completes.
 *
 * When a tool_use content_block_stop event arrives during streaming:
 * - Safe tools (read-only + concurrency-safe) are forked immediately via pcntl_fork
 *   until a stateful tool creates an execution barrier
 * - Unsafe tools, and safe tools after a stateful predecessor, are queued for
 *   sequential execution after the stream ends
 *
 * After the stream completes, collectResults() waits for forked children
 * and executes queued unsafe tools, returning all results in block order.
 *
 * Sibling abort: When a Bash tool errors, all other running tool processes
 * are killed and pending tools receive synthetic error messages. This matches
 * claude-code's siblingAbortController behavior.
 */
class StreamingToolExecutor
{
    private const MAX_EARLY_EXECUTIONS = 8;
    private const MAX_IPC_PAYLOAD_BYTES = 1_000_000;
    private const MAX_IPC_TOOL_ID_BYTES = 4_096;
    private const DEFAULT_EARLY_TOOL_TIMEOUT_SECONDS = 120.0;

    /** @var array<int, array{pid: int, temp_file: string, block: array}> */
    private array $earlyPids = [];

    /** @var array<int, array> block index => tool_use block */
    private array $queuedBlocks = [];

    /** Whether a stateful tool has made later early execution unsafe. */
    private bool $hasSequentialBarrier = false;

    private bool $contextSet = false;
    private ToolUseContext $context;
    /** @var callable|null */
    private $onToolStart = null;
    /** @var callable|null */
    private $onToolComplete = null;

    /** Whether a Bash tool has errored, triggering sibling abort. */
    private bool $siblingAborted = false;
    /** Description of the tool that triggered the sibling abort. */
    private ?string $abortedByTool = null;

    public function __construct(
        private readonly ToolOrchestrator $toolOrchestrator,
        private readonly ToolRegistry $toolRegistry,
        private readonly ?CancellationToken $cancellationToken = null,
        private readonly bool $disableEarlyExecution = false,
        private readonly float $earlyToolTimeoutSeconds = self::DEFAULT_EARLY_TOOL_TIMEOUT_SECONDS,
    ) {
        if (! is_finite($this->earlyToolTimeoutSeconds) || $this->earlyToolTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Early tool timeout must be greater than zero.');
        }
    }

    public function setContext(ToolUseContext $context, ?callable $onStart, ?callable $onComplete): void
    {
        $this->context = $context;
        $this->onToolStart = $onStart;
        $this->onToolComplete = $onComplete;
        $this->contextSet = true;
    }

    /**
     * Called when a tool_use block completes during streaming (content_block_stop).
     * Safe tools are forked immediately until a stateful tool is queued; later
     * tools remain queued so they observe the stateful tool's effects.
     */
    public function onToolBlockReady(array $block, int $index): void
    {
        if (!$this->contextSet) return;
        if (isset($this->earlyPids[$index]) || isset($this->queuedBlocks[$index])) {
            return;
        }

        if ($this->disableEarlyExecution || ($block['input_json_error'] ?? null) !== null) {
            $this->queuedBlocks[$index] = $block;
            $this->hasSequentialBarrier = true;
            return;
        }

        if ($this->hasSequentialBarrier) {
            // Classification runs schema validation and context backfilling.
            // After a stateful predecessor, defer that work too so it sees
            // the state the real execution will observe.
            $this->queuedBlocks[$index] = $block;
            return;
        }

        $tool = $this->toolRegistry->getTool($block['name']);
        $input = $block['input'] ?? [];
        $isSafe = false;
        $preparedBlock = $block;
        if ($tool !== null) {
            $classificationInput = $this->prepareClassificationInput($tool, $input);
            if ($classificationInput !== null) {
                $isSafe = $tool->isConcurrencySafe($classificationInput)
                    && $tool->isReadOnly($classificationInput)
                    && ! $this->toolOrchestrator->mayRunToolHooks($tool->name())
                    && ! $this->toolOrchestrator->mayRunPermissionPrompts();
                if ($isSafe) {
                    // The parent-side start callback must observe the same
                    // normalized input that the eventual execution observes.
                    $preparedBlock['input'] = $classificationInput;
                }
            }
        }

        if ($isSafe
            && ! $this->hasSequentialBarrier
            && count($this->earlyPids) < self::MAX_EARLY_EXECUTIONS
            && function_exists('pcntl_fork')
            && function_exists('posix_kill')) {
            $this->forkTool($preparedBlock, $index);
        } else {
            $this->queuedBlocks[$index] = $block;
            if (! $isSafe) {
                $this->hasSequentialBarrier = true;
            }
        }
    }

    /**
     * Apply the execution validation/normalization pipeline before early safety
     * classification. Returning null fails closed into queued execution.
     */
    private function prepareClassificationInput(ToolInterface $tool, mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        try {
            $input = $tool->inputSchema()->validate($input);
            if ($tool->validateInput($input, $this->context) !== null) {
                return null;
            }

            return $tool->backfillObservableInput($input, $this->context);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fork a child process to execute the tool while the parent continues streaming.
     */
    private function forkTool(array $block, int $index): void
    {
        // Use tempnam() for an unpredictable, 0600 IPC path. Never splice the
        // model/gateway-provided tool-call id ($block['id']) into the filename:
        // a hostile gateway could inject "../" to write outside the temp dir.
        $tempFile = tempnam(sys_get_temp_dir(), 'haocode_stream_');
        if ($tempFile === false) {
            // Could not allocate an IPC file — degrade to sequential execution.
            $this->queuedBlocks[$index] = $block;
            return;
        }
        @chmod($tempFile, 0600);

        // Snapshot readFileState before fork so we can detect child additions.
        $stateBefore = $this->context->getReadFileStateSnapshot();

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed, queue for sequential execution
            @unlink($tempFile);
            $this->queuedBlocks[$index] = $block;
            return;
        }

        if ($pid === 0) {
            if (function_exists('posix_setsid')) {
                @posix_setsid();
            }
            // Child process: execute tool and serialize result + readFileState changes
            $completedResult = null;
            $result = $this->toolOrchestrator->executeToolBlock(
                $block,
                $this->context,
                null,
                function (string $toolName, ToolResult $toolResult) use (&$completedResult): void {
                    $completedResult = $toolResult;
                },
            );
            $childState = $this->context->getReadFileStateSnapshot();
            $newEntries = array_filter(
                $childState,
                static fn (array $value, string $path): bool =>
                    ! isset($stateBefore[$path]) || $stateBefore[$path] !== $value,
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

        // Parent process: record child and continue streaming
        $this->earlyPids[$index] = [
            'pid' => $pid,
            'temp_file' => $tempFile,
            'block' => $block,
        ];

        if ($this->onToolStart) {
            try {
                ($this->onToolStart)($block['name'], $block['input'] ?? []);
            } catch (\Throwable $e) {
                // A host callback is allowed to abort the stream by throwing,
                // but the child must not outlive that failure or leave its IPC
                // file behind for the next stream.
                $this->killPid($pid);
                @pcntl_waitpid($pid, $status);
                @unlink($tempFile);
                unset($this->earlyPids[$index]);
                throw $e;
            }
        }
    }

    /**
     * After the stream completes, collect all tool results.
     * Waits for early-forked safe tools, then executes queued tools sequentially.
     * If a Bash tool errors, remaining queued tools receive synthetic errors.
     *
     * @return array API-format tool_result blocks in original block order
     */
    public function collectResults(): array
    {
        $results = [];

        // 1. Collect early results as children finish. Waiting in block order
        // would delay a fast tool's completion event behind an earlier slow
        // sibling, defeating the streaming lifecycle contract. A single batch
        // deadline also prevents up to MAX_EARLY_EXECUTIONS sequential timeout
        // waits when several children hang at once.
        $finalizeEarlyChild = function (int $index, array $info, bool $aborted = false, bool $timedOut = false) use (&$results): void {
            $data = ($aborted || $timedOut) ? false : $this->readIpcPayload($info['temp_file']);
            // allowed_classes => false blocks PHP object injection — the temp
            // file is owned by us but written by the child fork, and a
            // compromised dependency could otherwise trigger a gadget chain.
            $payload = $data !== false
                ? @unserialize($data, ['allowed_classes' => false])
                : false;

            if ($aborted) {
                $result = $this->abortedResult($info['block']);
            } elseif ($timedOut) {
                $result = $this->timedOutResult($info['block']);
            } elseif (is_array($payload) && isset($payload['result'])) {
                // New format: result + readFileState from child
                $result = $payload['result'];
                if (!empty($payload['readState'])) {
                    $this->context->mergeReadFileStateSnapshot($payload['readState']);
                }
            } elseif (is_array($payload)) {
                // Legacy format: bare result
                $result = $payload;
            } else {
                $result = [
                    'tool_use_id' => $info['block']['id'],
                    'content' => 'Failed to read streaming tool result',
                    'is_error' => true,
                ];
            }

            @unlink($info['temp_file']);
            $results[$index] = $result;
            unset($this->earlyPids[$index]);

            if ($this->onToolComplete) {
                $toolResult = $aborted
                    ? ToolResult::aborted()
                    : ($timedOut ? ToolResult::error('Tool execution timed out.', ['timedOut' => true]) : null);
                if ($toolResult === null && is_array($payload) && is_array($payload['toolResult'] ?? null)) {
                    try {
                        $toolResult = ToolResult::fromArray($payload['toolResult']);
                    } catch (\InvalidArgumentException) {
                        // Corrupt or legacy IPC payload: fall back to the API result.
                    }
                }
                $toolResult ??= $this->resultArrayToToolResult($result);
                ($this->onToolComplete)($info['block']['name'], $toolResult);
            }
        };

        $remaining = $this->earlyPids;
        $deadline = microtime(true) + $this->earlyToolTimeoutSeconds;
        while ($remaining !== []) {
            $madeProgress = false;
            foreach ($remaining as $index => $info) {
                if (! isset($this->earlyPids[$index])) {
                    // A completion callback can cause the surrounding stream
                    // to be abandoned and cleanup() to reap siblings.
                    unset($remaining[$index]);
                    continue;
                }
                $status = 0;
                $waitResult = pcntl_waitpid($info['pid'], $status, WNOHANG);
                if ($waitResult === -1) {
                    // EINTR means the child was not reaped; retry instead of
                    // reading a potentially incomplete IPC payload as final.
                    $interrupted = defined('PCNTL_EINTR')
                        && function_exists('pcntl_get_last_error')
                        && pcntl_get_last_error() === constant('PCNTL_EINTR');
                    if ($interrupted) {
                        continue;
                    }
                    // A non-EINTR -1 means another handler already reaped the
                    // child or the PID is no longer waitable. Fall through to
                    // the bounded IPC read, which produces a controlled error
                    // if no complete payload was written.
                    $finalizeEarlyChild($index, $info);
                    unset($remaining[$index]);
                    $madeProgress = true;
                    continue;
                }
                if ($waitResult === $info['pid']) {
                    $finalizeEarlyChild($index, $info);
                    unset($remaining[$index]);
                    $madeProgress = true;
                    continue;
                }
                if ($this->isCancelled()) {
                    $this->killPid($info['pid']);
                    @pcntl_waitpid($info['pid'], $status);
                    $finalizeEarlyChild($index, $info, aborted: true);
                    unset($remaining[$index]);
                    $madeProgress = true;
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    $this->killPid($info['pid']);
                    @pcntl_waitpid($info['pid'], $status);
                    $finalizeEarlyChild($index, $info, timedOut: true);
                    unset($remaining[$index]);
                    $madeProgress = true;
                }
            }

            if ($remaining !== [] && ! $madeProgress) {
                usleep(10_000);
            }
        }

        // 2. Execute queued unsafe tools sequentially, with sibling abort
        foreach ($this->queuedBlocks as $index => $block) {
            if ($this->siblingAborted) {
                // Sibling abort: give synthetic error to remaining tools
                $results[$index] = [
                    'tool_use_id' => $block['id'],
                    'content' => "Tool execution skipped: a sibling Bash command ({$this->abortedByTool}) failed. Fix the error and retry.",
                    'is_error' => true,
                ];
                continue;
            }

            $completedResult = null;
            $result = $this->toolOrchestrator->executeToolBlock(
                $block,
                $this->context,
                $this->onToolStart,
                function (string $toolName, ToolResult $toolResult) use (&$completedResult): void {
                    $completedResult = $toolResult;
                    if ($this->onToolComplete) {
                        ($this->onToolComplete)($toolName, $toolResult);
                    }
                },
            );

            $results[$index] = $result;

            // Check for Bash tool errors → trigger sibling abort
            if ($block['name'] === 'Bash' && $completedResult?->isError) {
                $exitCode = $completedResult->metadata['exitCode'] ?? null;
                // Only abort on real errors, not semantic non-errors (grep no match, etc.)
                if ($exitCode !== null && $exitCode !== 0) {
                    $this->siblingAborted = true;
                    $this->abortedByTool = $block['input']['description']
                        ?? $block['input']['command']
                        ?? 'Bash';
                    $this->killEarlyPids();
                }
            }
        }

        // Sort by original block index and re-index
        ksort($results);
        $this->earlyPids = [];
        $this->queuedBlocks = [];
        $this->hasSequentialBarrier = false;
        $this->siblingAborted = false;
        $this->abortedByTool = null;
        return array_values($results);
    }

    /**
     * Whether any tools were started early during streaming.
     */
    public function hasEarlyExecutions(): bool
    {
        return !empty($this->earlyPids);
    }

    /**
     * Kill any running child processes (e.g. on stream error, abort, or sibling abort).
     */
    public function cleanup(bool $notifyCompletion = true): void
    {
        $this->killEarlyPids($notifyCompletion);
        $this->queuedBlocks = [];
        $this->hasSequentialBarrier = false;
    }

    /**
     * Count of tools that were started early via fork.
     */
    public function earlyExecutionCount(): int
    {
        return count($this->earlyPids);
    }

    /**
     * Kill all early-forked child processes and clean up temp files.
     */
    private function killEarlyPids(bool $notifyCompletion = true): void
    {
        $completedToolNames = [];
        foreach ($this->earlyPids as $info) {
            $this->killPid($info['pid']);
            if (function_exists('pcntl_waitpid')) {
                pcntl_waitpid($info['pid'], $status); // reap zombie
            }
            if (file_exists($info['temp_file'])) {
                @unlink($info['temp_file']);
            }
            if ($notifyCompletion && $this->onToolComplete) {
                $completedToolNames[] = $info['block']['name'];
            }
        }
        $this->earlyPids = [];

        // Resource cleanup must finish for every child before callbacks run.
        // SDK streaming callbacks suspend their Fiber; the consumer may abandon
        // the generator at the first terminal event and force-close that Fiber.
        if ($this->onToolComplete) {
            foreach ($completedToolNames as $toolName) {
                ($this->onToolComplete)(
                    $toolName,
                    ToolResult::aborted(),
                );
            }
        }
    }

    private function isCancelled(): bool
    {
        return $this->cancellationToken?->isCancelled() ?? $this->context->isAborted();
    }

    private function killPid(int $pid): void
    {
        // Cancellation and timeout are terminal states; force the whole tree
        // down so a detached descendant cannot outlive the stream cleanup.
        ProcessSupervisor::terminateTree($pid, true);
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

    private function abortedResult(array $block): array
    {
        return [
            'tool_use_id' => $block['id'],
            'content' => 'Tool execution aborted',
            'is_error' => true,
        ];
    }

    private function timedOutResult(array $block): array
    {
        return [
            'tool_use_id' => $block['id'],
            'content' => 'Tool execution timed out.',
            'is_error' => true,
        ];
    }

    private function resultArrayToToolResult(array $result): ToolResult
    {
        return new ToolResult(
            output: (string) ($result['content'] ?? ''),
            isError: (bool) ($result['is_error'] ?? false),
        );
    }
}
