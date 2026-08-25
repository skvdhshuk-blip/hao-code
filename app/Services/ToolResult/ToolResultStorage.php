<?php

namespace HaoCode\Services\ToolResult;

use HaoCode\Support\Filesystem\CanonicalPathResolver;

/**
 * Persists large tool results to disk and generates previews.
 *
 * Matches claude-code's toolResultStorage.ts behavior:
 * - Results exceeding a per-tool threshold are written to session storage
 * - A newline-boundary-aware preview is shown to the model
 * - Per-message aggregate budget ensures total result size stays manageable
 */
class ToolResultStorage
{
    use ToolResultStorageConstructConcern;
    use ToolResultStorageIsRestorableReplacementConcern;

    /** Preview truncation size in bytes. */
    public const PREVIEW_SIZE_BYTES = 2000;

    /** Hard cap for any single model-visible tool result. */
    public const MAX_SINGLE_RESULT_CHARS = 40_000;

    /** Per-message aggregate budget for all tool results (chars). */
    public const MAX_TOOL_RESULTS_PER_MESSAGE_CHARS = 120_000;

    /** Default per-tool persistence threshold (chars). */
    public const DEFAULT_MAX_RESULT_SIZE_CHARS = 50_000;

    private string $storageDir;

    /** @var array<string, string> tool_use_id => persisted preview (for replay stability) */
    private array $replacements = [];

    /** @var array<string, bool> tool_use_id => true (fate frozen, cannot change) */
    private array $seenIds = [];
}
