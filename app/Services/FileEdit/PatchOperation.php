<?php

namespace HaoCode\Services\FileEdit;

/**
 * Represents a single file operation within a patch envelope.
 */
readonly class PatchOperation
{
    /**
     * @param  string  $type  'add' | 'update' | 'delete'
     * @param  string  $path  Target file path (relative or absolute)
     * @param  string[][]|null  $hunks  Update hunks (lines per hunk), null for add/delete
     * @param  string|null  $newContent  Full content for add operations
     */
    public function __construct(
        public string $type,
        public string $path,
        public ?array $hunks,
        public ?string $newContent,
    ) {}
}
