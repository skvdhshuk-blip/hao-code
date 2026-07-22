<?php

namespace HaoCode\Services\FileEdit;

/**
 * Represents a single file operation within a patch envelope.
 */
class PatchOperation
{
    /**
     * @param  string  $type  'add' | 'update' | 'delete'
     * @param  string  $path  Target file path (relative or absolute)
     * @param  string[][]|null  $hunks  Update hunks (lines per hunk), null for add/delete
     * @param  string|null  $newContent  Full content for add operations
     */
    public function __construct(
        public readonly string $type,
        public readonly string $path,
        public readonly ?array $hunks,
        public readonly ?string $newContent,
    ) {}
}
