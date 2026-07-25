<?php

namespace HaoCode\Services\FileEdit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;

/**
 * 4-phase atomic patch applier.
 *
 * Phase 1: Parse    — envelope → PatchOperation[]
 * Phase 2: Precheck — path traversal guard, symlink guard, Read-before-Write,
 *                     PermissionChecker (allow/deny glob rules per file)
 * Phase 3: Staging  — TOCTOU re-check (is_link) before tempfile write
 * Phase 4: Commit   — atomic rename swap + deletions
 *
 * Any phase failure triggers full cleanup of staged tempfiles.
 *
 * @experimental Windows rename atomicity is weak; use with caution on Windows.
 */
class PatchApplier
{
    /** @var array<string, string> tempPath => targetPath */
    private array $staged = [];

    /** @var string[] Absolute paths queued for deletion in commit phase */
    private array $pendingDeletes = [];

    public function __construct(
        private readonly PatchEnvelopeParser $parser,
        private readonly HunkSequencer $sequencer,
        private readonly SecretScanner $secretScanner,
        private readonly FileHistoryManager $historyManager,
        private readonly ?SettingsManager $settings = null,
    ) {}

    /**
     * Apply a patch envelope atomically.
     *
     * @return string[] Success messages per operation
     *
     * @throws \RuntimeException on any failure (cleanup already done)
     */
    public function apply(string $envelope, ToolUseContext $context): array
    {
        $operations = $this->parser->parse($envelope);

        if ($operations === []) {
            throw new \RuntimeException('Patch envelope contains no operations.');
        }

        $this->precheck($operations, $context);

        try {
            $this->stage($operations, $context);
        } catch (\Throwable $e) {
            $this->rollback();
            throw new \RuntimeException('Staging failed: '.$e->getMessage(), 0, $e);
        }

        try {
            return $this->commit($operations);
        } catch (\Throwable $e) {
            $this->rollback();
            throw new \RuntimeException('Commit failed: '.$e->getMessage(), 0, $e);
        }
    }

    /** @param PatchOperation[] $operations */
    private function precheck(array $operations, ToolUseContext $context): void
    {
        $base = rtrim(realpath($context->workingDirectory) ?: $context->workingDirectory, '/');

        foreach ($operations as $op) {
            $path = $this->resolve($op->path, $base);

            // Vuln 4: per-file allow/deny glob rules via SettingsManager
            $this->checkFilePermission($path, $op->path, $op->type);

            match ($op->type) {
                'add' => $this->precheckAdd($path, $op->path, $base),
                'update' => $this->precheckUpdate($path, $op->path, $context),
                'delete' => $this->precheckDelete($path, $op->path, $context),
                default => null,
            };
        }
    }

    private function precheckAdd(string $path, string $relPath, string $base): void
    {
        // Vuln 2: reject symlinks on the target path's parent chain
        $this->assertNoSymlink($path, $relPath);

        if (file_exists($path)) {
            throw new \RuntimeException("Add failed: file already exists: {$relPath}");
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    private function precheckUpdate(string $path, string $relPath, ToolUseContext $context): void
    {
        // Vuln 2: reject symlinks
        $this->assertNoSymlink($path, $relPath);

        if (! file_exists($path)) {
            throw new \RuntimeException("Update failed: file not found: {$relPath}");
        }
        if (! $context->wasFileRead($path)) {
            throw new \RuntimeException(
                "Read-before-Write violated: {$relPath} must be read before patching."
            );
        }
        if (! is_readable($path) || ! is_writable($path)) {
            throw new \RuntimeException("File not readable/writable: {$relPath}");
        }
    }

    private function precheckDelete(string $path, string $relPath, ToolUseContext $context): void
    {
        // Vuln 2: reject symlinks
        $this->assertNoSymlink($path, $relPath);

        if (! file_exists($path)) {
            throw new \RuntimeException("Delete failed: file not found: {$relPath}");
        }
        if (! $context->wasFileRead($path)) {
            throw new \RuntimeException(
                "Read-before-Write violated: {$relPath} must be read before deleting."
            );
        }
        if (! is_writable($path)) {
            throw new \RuntimeException("File not writable for deletion: {$relPath}");
        }
    }

    /** @param PatchOperation[] $operations */
    private function stage(array $operations, ToolUseContext $context): void
    {
        $base = rtrim(realpath($context->workingDirectory) ?: $context->workingDirectory, '/');

        foreach ($operations as $op) {
            $path = $this->resolve($op->path, $base);

            if ($op->type === 'delete') {
                $this->pendingDeletes[] = $path;

                continue;
            }

            if ($op->type === 'add') {
                // Vuln 3: TOCTOU re-check before write
                $this->assertNoSymlink($path, $op->path);

                $content = $op->newContent ?? '';
                if ($this->looksBinary($content)) {
                    throw new \RuntimeException(
                        "Add failed: refusing to write binary payload via apply_patch: {$op->path}",
                    );
                }
                if ($this->secretScanner->containsSecrets($content)) {
                    $findings = $this->secretScanner->scan($content);
                    $types = implode(', ', array_column($findings, 'type'));
                    throw new \RuntimeException("Secret detected in new file {$op->path}: {$types}");
                }
                $this->staged[$this->writeTempFile($content, dirname($path))] = $path;

                continue;
            }

            if ($op->type === 'update') {
                // Vuln 3: TOCTOU re-check before write
                $this->assertNoSymlink($path, $op->path);

                $original = file_get_contents($path);
                if ($original === false) {
                    throw new \RuntimeException("Cannot read file for update: {$op->path}");
                }
                if ($this->looksBinary($original)) {
                    throw new \RuntimeException(
                        "Update failed: refusing to patch binary file: {$op->path}",
                    );
                }
                $patched = $this->sequencer->applyHunks($original, $op->hunks ?? [], $op->path);
                $this->staged[$this->writeTempFile($patched, dirname($path))] = $path;
            }
        }
    }

    /**
     * @param  PatchOperation[]  $operations
     * @return string[]
     */
    private function commit(array $operations): array
    {
        foreach ($this->staged as $tmp => $target) {
            try {
                $this->historyManager->recordBefore($target);
            } catch (\Throwable) {
            }
            if (! rename($tmp, $target)) {
                throw new \RuntimeException("rename() failed: {$tmp} → {$target}");
            }
        }

        foreach ($this->pendingDeletes as $deletePath) {
            try {
                $this->historyManager->recordBefore($deletePath);
            } catch (\Throwable) {
            }
            if (! unlink($deletePath)) {
                throw new \RuntimeException("unlink() failed: {$deletePath}");
            }
        }

        $this->staged = [];
        $this->pendingDeletes = [];

        return array_map(fn (PatchOperation $op) => match ($op->type) {
            'add' => "Added: {$op->path}",
            'update' => "Updated: {$op->path}",
            'delete' => "Deleted: {$op->path}",
            default => "Processed: {$op->path}",
        }, $operations);
    }

    private function rollback(): void
    {
        foreach ($this->staged as $tmp => $_target) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
        $this->staged = [];
        $this->pendingDeletes = [];
    }

    private function writeTempFile(string $content, string $dir): string
    {
        $tmp = tempnam($dir, '.haocode_patch_');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create tempfile in {$dir}");
        }
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write tempfile: {$tmp}");
        }

        return $tmp;
    }

    /**
     * Vuln 4: Check per-file allow/deny rules from settings.
     * Rule format: "apply_patch(glob)" — e.g. "apply_patch(/etc/*)" to deny.
     * Deny rules take precedence over allow rules.
     */
    private function checkFilePermission(string $absPath, string $relPath, string $opType): void
    {
        if ($this->settings === null) {
            return;
        }

        foreach ($this->settings->getDenyRules() as $rule) {
            if ($this->ruleMatchesPath($rule, $absPath)) {
                throw new \RuntimeException(
                    "Permission denied for {$opType} on '{$relPath}': denied by rule '{$rule}'"
                );
            }
        }

        $allowRules = $this->settings->getAllowRules();
        if ($allowRules === []) {
            return;
        }

        foreach ($allowRules as $rule) {
            if ($this->ruleMatchesPath($rule, $absPath)) {
                return;
            }
        }
    }

    /**
     * Match a rule of the form "apply_patch(glob)" against an absolute path.
     * Rules without a tool prefix or with a different tool prefix are ignored.
     */
    private function ruleMatchesPath(string $rule, string $absPath): bool
    {
        if (! preg_match('/^apply_patch\((.+)\)$/', $rule, $m)) {
            return false;
        }

        $pattern = $m[1];

        return fnmatch($pattern, $absPath) || fnmatch($pattern, basename($absPath));
    }

    /**
     * Vuln 1: Resolve path, enforce workingDirectory boundary via realpath.
     * Prevents path traversal (../../etc/passwd) and absolute path escapes.
     */
    private function resolve(string $path, string $base): string
    {
        // Build candidate path
        if (str_starts_with($path, '/')) {
            $candidate = $path;
        } else {
            $candidate = $base.'/'.$path;
        }

        // Normalize without requiring the file to exist yet (for add operations)
        // Use realpath on the parent directory + filename to resolve .. traversal
        $dir = dirname($candidate);
        $file = basename($candidate);
        $resolvedDir = realpath($dir);

        if ($resolvedDir === false) {
            // Directory doesn't exist yet (valid for add); normalise manually
            $resolvedDir = $this->normalizePath($dir);
        }

        $resolved = $resolvedDir.'/'.$file;

        // Enforce boundary: resolved path must be inside workingDirectory
        if (! str_starts_with($resolved.'/', $base.'/')) {
            throw new \RuntimeException(
                "Path traversal detected: '{$path}' resolves outside working directory."
            );
        }

        return $resolved;
    }

    /**
     * Normalize a path by resolving . and .. without requiring it to exist.
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        return '/'.implode('/', $stack);
    }

    /**
     * Vuln 2: Detect symlinks on the target path or any component of its directory.
     * Prevents rename() from overwriting symlink targets.
     */
    private function assertNoSymlink(string $path, string $relPath): void
    {
        if (is_link($path)) {
            throw new \RuntimeException(
                "Symlink detected at target path — refusing to operate on symlink: {$relPath}"
            );
        }

        // Walk directory components for symlinks
        $dir = dirname($path);
        $check = $dir;
        while ($check !== '/' && $check !== dirname($check)) {
            if (is_link($check)) {
                throw new \RuntimeException(
                    "Symlink detected in directory path '{$check}' — refusing to operate: {$relPath}"
                );
            }
            $check = dirname($check);
        }
    }

    /**
     * Patch envelopes are text-oriented; reject NUL-bearing payloads.
     */
    private function looksBinary(string $content): bool
    {
        return str_contains($content, "\0");
    }
}
