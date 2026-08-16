<?php

namespace HaoCode\Services\Agent;

use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Executes concurrency-safe tool blocks in isolated child processes.
 *
 * The executor owns only the fork/IPC mechanics. Tool lifecycle, hooks and
 * permission decisions remain in ToolOrchestrator through the injected
 * callback, so extracting this code does not change execution semantics.
 *
 * @internal
 */
final class ParallelToolExecutor
{
    private const MAX_PARALLEL_TOOLS = 8;
    private const MAX_IPC_PAYLOAD_BYTES = 1_000_000;
    private const MAX_IPC_TOOL_ID_BYTES = 4_096;

    /** @var \Closure(array, ToolUseContext, ?callable, ?callable): array */
    private readonly \Closure $executeTool;

    public function __construct(
        callable $executeTool,
        private readonly float $timeoutSeconds,
    ) {
        $this->executeTool = \Closure::fromCallable($executeTool);
    }

    public function execute(
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
                $results[$idx] = ($this->executeTool)($block, $context, $onStart, $onComplete);
            }
            return $results;
        }

        if (count($blocks) > self::MAX_PARALLEL_TOOLS) {
            $results = [];
            foreach (array_chunk($blocks, self::MAX_PARALLEL_TOOLS, true) as $chunk) {
                foreach ($this->execute($chunk, $context, $onStart, $onComplete) as $idx => $result) {
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
        $recordOversizedId = function (int $idx) use (&$results, &$completedResults, $blocks, $onComplete): void {
            $result = ToolResult::error('Tool result exceeded IPC size limit.');
            $results[$idx] = $result->toApiFormat($this->boundedToolUseId($blocks[$idx]));
            $completedResults[$idx] = $result;
            if ($onComplete) {
                $onComplete($blocks[$idx]['name'], $result);
            }
        };

        $cleanupParallel = function () use (&$pids, &$tempFiles): void {
            foreach ($pids as $pid) {
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, true);
            }
            foreach ($pids as $pid) {
                $status = 0;
                @pcntl_waitpid($pid, $status);
            }
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
            $pids = [];
            $tempFiles = [];
        };

        // Capture the parent's readFileState snapshot before forking so we can
        // detect which entries the child added.
        $parentStateBefore = $context->getReadFileStateSnapshot();
        $deadline = microtime(true) + $this->timeoutSeconds;

        try {
            foreach ($blocks as $idx => $block) {
                // Do not start more children after a start callback (or another
                // concurrent signal) has cancelled the batch.
                if ($context->isAborted()) {
                    $recordAborted($idx);
                    continue;
                }
                if ($this->toolUseIdExceedsIpcPayload($block)) {
                    $recordOversizedId($idx);
                    continue;
                }

                // Use tempnam() for an unpredictable, 0600-mode filename instead of
                // a guessable "<prefix>_<idx>_<pid>" — predictable names let other
                // local users race or symlink-swap the IPC file.
                $tempFile = $this->allocateIpcTempFile('haocode_tool_');
                $tempFiles[$idx] = $tempFile;

                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Fork failed, execute inline.
                    @unlink($tempFile);
                    $results[$idx] = ($this->executeTool)($block, $context, $onStart, $onComplete);
                    unset($tempFiles[$idx]);
                    continue;
                }

                if ($pid === 0) {
                    if (function_exists('posix_setsid')) {
                        @posix_setsid();
                    }
                    // Child process.
                    $completedResult = null;
                    $result = ($this->executeTool)(
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

                // Parent.
                $pids[$idx] = $pid;
                if ($onStart) {
                    $onStart($block['name'], $block['input'] ?? []);
                }
            }

            $finalizeChild = function (int $idx) use (
                &$results,
                &$completedResults,
                &$tempFiles,
                &$pids,
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
                        // New format: result + readState.
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
                        // Legacy format: bare result (backward compat).
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
                unset($pids[$idx]);
                if ($onComplete) {
                    $toolName = $blocks[$idx]['name'];
                    $result = $completedResults[$idx]
                        ?? ToolResult::fromApiFormat($results[$idx]);
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
                        \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, true);
                    }
                    foreach (array_keys($remaining) as $idx) {
                        $status = 0;
                        @pcntl_waitpid($pids[$idx], $status);
                        unset($pids[$idx]);
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
                        \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, true);
                    }
                    foreach (array_keys($remaining) as $idx) {
                        $status = 0;
                        @pcntl_waitpid($pids[$idx], $status);
                        unset($pids[$idx]);
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
        } finally {
            $cleanupParallel();
        }
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

    /** @param array<string, mixed> $block */
    private function toolUseIdExceedsIpcPayload(array $block): bool
    {
        $id = $block['id'] ?? '';

        return is_scalar($id) && strlen((string) $id) >= self::MAX_IPC_PAYLOAD_BYTES;
    }

    private function readIpcPayload(string $tempFile): string|false
    {
        $size = @filesize($tempFile);
        if ($size === false || $size > self::MAX_IPC_PAYLOAD_BYTES) {
            return false;
        }

        return @file_get_contents($tempFile);
    }

}
