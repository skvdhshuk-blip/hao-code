<?php

namespace HaoCode\Services\FileEdit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;

/**
 * 4-phase rollback-safe patch applier.
 *
 * Phase 1: Parse    — envelope → PatchOperation[]
 * Phase 2: Precheck — path traversal guard, symlink guard, Read-before-Write,
 *                     PermissionChecker (allow/deny glob rules per file)
 * Phase 3: Staging  — TOCTOU re-check (is_link) before tempfile write
 * Phase 4: Commit   — rename swap with a reversible per-file journal
 *
 * Any phase failure restores already-committed paths and removes staged files.
 *
 * @experimental Windows rename atomicity is weak; use with caution on Windows.
 */
class PatchApplier
{
    use PatchApplierConstructConcern;
    use PatchApplierRollbackConcern;

    /**
     * @var list<array{
     *   operation: PatchOperation,
     *   target: string,
     *   temp: string|null,
     *   backup: string|null,
     *   mode: int|null,
     *   content: string|null,
     *   state: 'prepared'|'reserved'|'backed_up'|'committed',
     *   lock: mixed,
     *   original_identity: array{device: int, inode: int}|null,
     *   published_identity: array{device: int, inode: int}|null,
     *   temp_identity: array{device: int, inode: int}|null
     * }>
     */
    private array $prepared = [];

    /** @var list<string> */
    private array $createdDirectories = [];
}
