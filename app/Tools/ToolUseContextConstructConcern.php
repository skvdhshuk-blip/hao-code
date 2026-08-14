<?php

namespace HaoCode\Tools;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Cache\FileState;
use HaoCode\Services\Cache\FileStateCache;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;

trait ToolUseContextConstructConcern
{

    public function __construct(
        public string $workingDirectory,
        public readonly string $sessionId,
        \Closure|null $onProgress = null,
        \Closure|null $shouldAbort = null,
        ?FileStateCache $fileStateCache = null,
        public readonly ?AgentRunContext $runContext = null,
        public readonly ?LlmProvider $provider = null,
        public readonly ?ToolRegistry $toolRegistry = null,
        \Closure|null $onWorkingDirectoryChanged = null,
    ) {
        $this->onProgress = $onProgress;
        $this->shouldAbort = $shouldAbort;
        $this->onWorkingDirectoryChanged = $onWorkingDirectoryChanged;
        $this->fileStateCache = $fileStateCache ?? new FileStateCache();
    }

    public function isAborted(): bool
    {
        return $this->shouldAbort ? (bool) ($this->shouldAbort)() : false;
    }

    /** @internal */
    public function setWorkingDirectory(string $workingDirectory): void
    {
        if ($this->workingDirectory === $workingDirectory) {
            return;
        }

        $this->workingDirectory = $workingDirectory;
        if ($this->onWorkingDirectoryChanged !== null) {
            ($this->onWorkingDirectoryChanged)($workingDirectory);
        }
    }

    /** @internal */
    public function rememberWorktreeOriginalDirectory(string $directory): void
    {
        $this->worktreeOriginalDirectory = $directory;
    }

    /** @internal */
    public function getWorktreeOriginalDirectory(): ?string
    {
        return $this->worktreeOriginalDirectory;
    }

    /** @internal */
    public function clearWorktreeOriginalDirectory(): void
    {
        $this->worktreeOriginalDirectory = null;
    }

    /**
     * Record that a file was read and cache its content.
     */
    public function recordFileRead(
        string $filePath,
        ?string $content = null,
        ?int $offset = null,
        ?int $limit = null,
        bool $isPartialView = false,
        ?int $totalLines = null,
    ): void
    {
        $revision = $content !== null
            ? FileRevision::fromRead($filePath, $content, ! $isPartialView)
            : FileRevision::capture($filePath, ! $isPartialView);
        if ($revision === null) {
            return;
        }

        $receipt = $revision->toArray();
        if ($isPartialView) {
            $receipt['complete'] = false;
            $receipt = $this->withLineCoverage(
                $receipt,
                $offset,
                $limit,
                $totalLines ?? ($content !== null ? $this->countContentLines($content) : null),
            );
        }
        $this->storeReadRevision($revision->canonicalPath, $receipt);

        if ($content !== null) {
            $this->fileStateCache->set($revision->canonicalPath, new FileState(
                content: $content,
                timestamp: time(),
                offset: $offset,
                limit: $limit,
                isPartialView: $isPartialView,
            ));
        }
    }

    /**
     * Record bytes from a virtual/remote filesystem without probing a
     * same-named path on the host.
     *
     * @internal
     */
    public function recordVirtualFileRead(
        string $filePath,
        string $content,
        ?int $offset = null,
        ?int $limit = null,
        bool $isPartialView = false,
    ): void {
        $key = $this->virtualRevisionKey($filePath);
        $revision = new FileRevision(
            canonicalPath: $key,
            device: null,
            inode: null,
            size: strlen($content),
            mtime: null,
            sha256: hash('sha256', $content),
            complete: ! $isPartialView,
            observedAtMicros: (int) round(microtime(true) * 1_000_000),
            local: false,
        );
        $this->storeReadRevision($key, $revision->toArray());
        $this->fileStateCache->set($key, new FileState(
            content: $content,
            timestamp: time(),
            offset: $offset,
            limit: $limit,
            isPartialView: $isPartialView,
        ));
    }

    /**
     * Record a virtual/remote filesystem revision when the caller streamed the
     * bytes and intentionally did not retain the full content in memory.
     *
     * @internal
     */
    public function recordObservedVirtualFileRevision(
        string $filePath,
        int $size,
        string $sha256,
        ?int $offset = null,
        ?int $limit = null,
        bool $isPartialView = false,
        ?int $totalLines = null,
    ): void {
        $key = $this->virtualRevisionKey($filePath);
        $revision = new FileRevision(
            canonicalPath: $key,
            device: null,
            inode: null,
            size: $size,
            mtime: null,
            sha256: $sha256,
            complete: ! $isPartialView,
            observedAtMicros: (int) round(microtime(true) * 1_000_000),
            local: false,
        );
        $receipt = $revision->toArray();
        if ($isPartialView) {
            $receipt['complete'] = false;
            $receipt = $this->withLineCoverage($receipt, $offset, $limit, $totalLines);
        }
        $this->storeReadRevision($key, $receipt);
    }

    /**
     * Record an already-captured local file revision.
     *
     * @internal
     */
    public function recordObservedFileRevision(
        FileRevision $revision,
        ?string $content = null,
        ?int $offset = null,
        ?int $limit = null,
        bool $isPartialView = false,
        ?int $totalLines = null,
    ): void {
        $receipt = $revision->toArray();
        if ($isPartialView) {
            $receipt['complete'] = false;
            $receipt = $this->withLineCoverage(
                $receipt,
                $offset,
                $limit,
                $totalLines ?? ($content !== null ? $this->countContentLines($content) : null),
            );
        }
        $this->storeReadRevision($revision->canonicalPath, $receipt);

        if ($content !== null) {
            $this->fileStateCache->set($revision->canonicalPath, new FileState(
                content: $content,
                timestamp: time(),
                offset: $offset,
                limit: $limit,
                isPartialView: $isPartialView,
            ));
        }
    }

    public function wasFileRead(string $filePath): bool
    {
        return $this->getFileRevision($filePath)?->complete === true;
    }

    public function getFileRevision(string $filePath): ?FileRevision
    {
        $key = $this->existingRevisionKey($filePath);
        $value = $key === null ? null : ($this->readFileState[$key] ?? null);

        return is_array($value) ? FileRevision::fromArray($value) : null;
    }

    /**
     * Revoke complete-read authorization while retaining the observed
     * revision for diagnostics and fork-state merging.
     *
     * @internal
     */
    public function markFileReadIncomplete(string $filePath): void
    {
        $key = $this->existingRevisionKey($filePath);
        if ($key === null) {
            return;
        }
        $value = $this->pendingReadFileState[$key] ?? ($this->readFileState[$key] ?? null);
        if (! is_array($value) || FileRevision::fromArray($value) === null) {
            return;
        }

        $value['complete'] = false;
        $value['observed_at_micros'] = (int) round(microtime(true) * 1_000_000);
        if (isset($this->pendingReadFileState[$key])) {
            $this->pendingReadFileState[$key] = $value;
        } else {
            $this->readFileState[$key] = $value;
        }
    }

    /**
     * Remove both authorization and cached bytes for a prior Read.
     *
     * @internal
     */
    public function forgetFileRead(string $filePath): void
    {
        $virtualKey = $this->virtualRevisionKey($filePath);
        if (isset($this->readFileState[$virtualKey]) || isset($this->pendingReadFileState[$virtualKey])) {
            unset($this->readFileState[$virtualKey]);
            unset($this->pendingReadFileState[$virtualKey]);
            $this->fileStateCache->delete($virtualKey);

            return;
        }

        $canonicalKey = $this->revisionKey($filePath);
        unset($this->readFileState[$canonicalKey]);
        unset($this->pendingReadFileState[$canonicalKey]);
        $this->fileStateCache->delete($canonicalKey);
    }

    /**
     * Return a user-facing conflict reason, or null when a complete local
     * revision still matches the bytes on disk.
     */
    public function fileRevisionError(string $filePath): ?string
    {
        $revision = $this->getFileRevision($filePath);
        if ($revision === null) {
            return "Read tool first: {$filePath} must be read before writing.";
        }
        if (! $revision->complete) {
            return "Read the complete file first: {$filePath} was only partially read.";
        }
        if (! $revision->local) {
            return "File revision cannot be verified locally: {$filePath}. Read it again.";
        }

        $current = FileRevision::capture($filePath);
        if ($current === null || ! $revision->sameVersion($current)) {
            return "File changed since it was read: {$filePath}. Read it again before writing.";
        }

        return null;
    }

    /**
     * Get cached file state (content, offset, partial view flag).
     */
    public function getFileState(string $filePath): ?FileState
    {
        $key = $this->existingRevisionKey($filePath);

        return $this->fileStateCache->get($key ?? $this->revisionKey($filePath));
    }

    public function getFileStateCache(): FileStateCache
    {
        return $this->fileStateCache;
    }

    public function resetReadState(): void
    {
        $this->readFileState = [];
        $this->pendingReadFileState = null;
    }

    /**
     * Start collecting new read receipts for a tool-result batch whose results
     * have not yet been made visible to the model.
     *
     * @internal
     */
    public function beginReadReceiptBatch(): void
    {
        $this->pendingReadFileState ??= [];
    }

    /** @internal */
    public function hasReadReceiptBatch(): bool
    {
        return $this->pendingReadFileState !== null;
    }

    /**
     * Promote receipts observed in the current batch after the corresponding
     * tool_result messages have been committed to model-visible history.
     *
     * @internal
     */
    public function commitReadReceiptBatch(): void
    {
        if ($this->pendingReadFileState === null) {
            return;
        }

        $pending = $this->pendingReadFileState;
        $this->pendingReadFileState = null;
        $this->mergeReadFileStateSnapshot($pending);
    }

    /** @internal */
    public function discardReadReceiptBatch(): void
    {
        $this->pendingReadFileState = null;
    }

    /**
     * Snapshot only the receipts observed in the current, not-yet-visible
     * tool-result batch. Durable HITL checkpoints persist these receipts so
     * they can be promoted after the interrupted batch is resolved and its
     * tool_result messages are finally visible to the model.
     *
     * @return array<string, array<string, mixed>>
     * @internal
     */
    public function getPendingReadFileStateSnapshot(): array
    {
        return $this->pendingReadFileState ?? [];
    }

    /**
     * Re-attach pending receipts restored from a durable checkpoint. Callers
     * must only do this after interrupted side-effecting actions have resolved,
     * while a new batch is open and before committing model-visible tool
     * results.
     *
     * @param array<string, array<string, mixed>> $snapshot
     * @internal
     */
    public function queueReadReceiptSnapshotForCurrentBatch(array $snapshot): void
    {
        $this->pendingReadFileState ??= [];
        $this->mergeReadFileStateSnapshot($snapshot);
    }

    /**
     * Get a snapshot of the current readFileState for IPC serialization.
     * Used by parallel tool execution: the child process captures its state
     * and the parent merges it back after the child exits.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getReadFileStateSnapshot(): array
    {
        return $this->pendingReadFileState === null
            ? $this->readFileState
            : array_replace($this->readFileState, $this->pendingReadFileState);
    }

    /**
     * Merge a readFileState snapshot (from a child process) into the current state.
     * Only adds entries that are newer or missing in the parent.
     *
     * @param array<string, array<string, mixed>> $snapshot
     */
    public function mergeReadFileStateSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $path => $value) {
            if (! is_array($value) || FileRevision::fromArray($value) === null) {
                continue;
            }

            $target =& $this->readFileState;
            if ($this->pendingReadFileState !== null) {
                $target =& $this->pendingReadFileState;
            }

            $target[$path] = $this->mergeWithExistingReadRevision($path, $value);
        }
    }

    /** @param array<string, mixed> $revision */
    private function storeReadRevision(string $key, array $revision): void
    {
        $revision = $this->mergeWithExistingReadRevision($key, $revision);

        if ($this->pendingReadFileState !== null) {
            $this->pendingReadFileState[$key] = $revision;

            return;
        }

        $this->readFileState[$key] = $revision;
    }

    /** @param array<string, mixed> $revision */
    private function withLineCoverage(array $revision, ?int $offset, ?int $limit, ?int $totalLines): array
    {
        if ($totalLines === null || $totalLines < 1 || $offset === null || $limit === null || $limit < 1) {
            return $revision;
        }

        $start = max(1, $offset);
        $end = min($totalLines, $start + $limit - 1);
        if ($start > $end) {
            return $revision;
        }

        $revision['total_lines'] = $totalLines;
        $revision['line_coverage'] = [[$start, $end]];

        return $this->refreshCoverageCompleteness($revision);
    }
}
