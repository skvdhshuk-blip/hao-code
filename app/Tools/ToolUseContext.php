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
    use ToolUseContextConstructConcern;
    use ToolUseContextMergeWithExistingReadRevisionConcern;

    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;
    /** @var (\Closure(): bool)|null */
    public readonly \Closure|null $shouldAbort;
    /** @var (\Closure(string): void)|null */
    public readonly \Closure|null $onWorkingDirectoryChanged;

    /** @var array<string, array<string, mixed>> canonical path => revision receipt */
    private array $readFileState = [];

    /** @var array<string, array<string, mixed>>|null receipts observed in the current tool-result batch */
    private ?array $pendingReadFileState = null;

    /** Original directory to restore after leaving a session worktree. */
    private ?string $worktreeOriginalDirectory = null;

    private FileStateCache $fileStateCache;
}
