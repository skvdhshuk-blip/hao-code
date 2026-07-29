<?php

namespace HaoCode\Tools;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Cache\FileState;
use HaoCode\Services\Cache\FileStateCache;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;

class ToolUseContext
{
    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;
    /** @var (\Closure(): bool)|null */
    public readonly \Closure|null $shouldAbort;

    /** @var array<string, array<string, mixed>> canonical path => revision receipt */
    private array $readFileState = [];

    private FileStateCache $fileStateCache;

    public function __construct(
        public readonly string $workingDirectory,
        public readonly string $sessionId,
        \Closure|null $onProgress = null,
        \Closure|null $shouldAbort = null,
        ?FileStateCache $fileStateCache = null,
        public readonly ?AgentRunContext $runContext = null,
        public readonly ?LlmProvider $provider = null,
        public readonly ?ToolRegistry $toolRegistry = null,
    ) {
        $this->onProgress = $onProgress;
        $this->shouldAbort = $shouldAbort;
        $this->fileStateCache = $fileStateCache ?? new FileStateCache();
    }

    public function isAborted(): bool
    {
        return $this->shouldAbort ? (bool) ($this->shouldAbort)() : false;
    }

    /**
     * Record that a file was read and cache its content.
     */
    public function recordFileRead(string $filePath, ?string $content = null, ?int $offset = null, ?int $limit = null, bool $isPartialView = false): void
    {
        $revision = $content !== null
            ? FileRevision::fromRead($filePath, $content, ! $isPartialView)
            : FileRevision::capture($filePath, ! $isPartialView);
        if ($revision === null) {
            return;
        }
        $this->readFileState[$revision->canonicalPath] = $revision->toArray();

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
        $this->readFileState[$key] = $revision->toArray();
        $this->fileStateCache->set($key, new FileState(
            content: $content,
            timestamp: time(),
            offset: $offset,
            limit: $limit,
            isPartialView: $isPartialView,
        ));
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
        $value = $this->readFileState[$key] ?? null;
        if (! is_array($value) || FileRevision::fromArray($value) === null) {
            return;
        }

        $value['complete'] = false;
        $value['observed_at_micros'] = (int) round(microtime(true) * 1_000_000);
        $this->readFileState[$key] = $value;
    }

    /**
     * Remove both authorization and cached bytes for a prior Read.
     *
     * @internal
     */
    public function forgetFileRead(string $filePath): void
    {
        $virtualKey = $this->virtualRevisionKey($filePath);
        if (isset($this->readFileState[$virtualKey])) {
            unset($this->readFileState[$virtualKey]);
            $this->fileStateCache->delete($virtualKey);

            return;
        }

        $canonicalKey = $this->revisionKey($filePath);
        unset($this->readFileState[$canonicalKey]);
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
        return $this->readFileState;
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

            $existing = $this->readFileState[$path] ?? null;
            if (! is_array($existing)
                || ($value['observed_at_micros'] ?? 0) > ($existing['observed_at_micros'] ?? 0)) {
                $this->readFileState[$path] = $value;
            }
        }
    }

    private function revisionKey(string $filePath): string
    {
        return CanonicalPathResolver::resolve($filePath, $this->workingDirectory);
    }

    private function existingRevisionKey(string $filePath): ?string
    {
        $virtualKey = $this->virtualRevisionKey($filePath);
        if (isset($this->readFileState[$virtualKey])) {
            return $virtualKey;
        }

        $canonicalKey = $this->revisionKey($filePath);

        return isset($this->readFileState[$canonicalKey]) ? $canonicalKey : null;
    }

    /**
     * Sandbox paths are guest POSIX paths. Normalize them lexically without
     * consulting a same-named path or symlink on the host filesystem.
     */
    private function virtualRevisionKey(string $filePath): string
    {
        $path = $filePath;
        if (! str_starts_with($path, '/')) {
            $path = rtrim($this->workingDirectory, '/').'/'.$path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
