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

    public function __construct(
        private readonly PatchEnvelopeParser $parser,
        private readonly HunkSequencer $sequencer,
        private readonly SecretScanner $secretScanner,
        private readonly FileHistoryManager $historyManager,
        private readonly ?SettingsManager $settings = null,
    ) {}

    /**
     * Apply a patch envelope with rollback if any staged operation fails.
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
            $rollbackError = $this->rollback();
            throw new \RuntimeException(
                'Staging failed: '.$e->getMessage().$this->rollbackSuffix($rollbackError),
                0,
                $e,
            );
        }

        try {
            return $this->commit($operations, $context);
        } catch (\Throwable $e) {
            $rollbackError = $this->rollback();
            throw new \RuntimeException(
                'Commit failed: '.$e->getMessage().$this->rollbackSuffix($rollbackError),
                0,
                $e,
            );
        }
    }

    /** @param PatchOperation[] $operations */
    private function precheck(array $operations, ToolUseContext $context): void
    {
        $base = rtrim(realpath($context->workingDirectory) ?: $context->workingDirectory, '/');
        $targets = [];

        foreach ($operations as $op) {
            $path = $this->resolve($op->path, $base);
            if (isset($targets[$path])) {
                throw new \RuntimeException(
                    "Duplicate patch target: {$op->path} resolves to the same file as {$targets[$path]}."
                );
            }
            $targets[$path] = $op->path;

            // Vuln 4: per-file allow/deny glob rules via SettingsManager
            $this->checkFilePermission($path, $op->path, $op->type);

            match ($op->type) {
                'add' => $this->precheckAdd($path, $op->path),
                'update' => $this->precheckUpdate($path, $op->path, $context),
                'delete' => $this->precheckDelete($path, $op->path, $context),
                default => null,
            };
        }
    }

    private function precheckAdd(string $path, string $relPath): void
    {
        // Vuln 2: reject symlinks on the target path's parent chain
        $this->assertNoSymlink($path, $relPath);

        if (file_exists($path)) {
            throw new \RuntimeException("Add failed: file already exists: {$relPath}");
        }
    }

    private function precheckUpdate(string $path, string $relPath, ToolUseContext $context): void
    {
        // Vuln 2: reject symlinks
        $this->assertNoSymlink($path, $relPath);

        if (! is_file($path)) {
            throw new \RuntimeException("Update failed: file not found: {$relPath}");
        }
        $revisionError = $context->fileRevisionError($path);
        if ($revisionError !== null) {
            throw new \RuntimeException("Read-before-Write violated for {$relPath}: {$revisionError}");
        }
        if (! is_readable($path) || ! is_writable($path)) {
            throw new \RuntimeException("File not readable/writable: {$relPath}");
        }
    }

    private function precheckDelete(string $path, string $relPath, ToolUseContext $context): void
    {
        // Vuln 2: reject symlinks
        $this->assertNoSymlink($path, $relPath);

        if (! is_file($path)) {
            throw new \RuntimeException("Delete failed: file not found: {$relPath}");
        }
        $revisionError = $context->fileRevisionError($path);
        if ($revisionError !== null) {
            throw new \RuntimeException("Read-before-Write violated for {$relPath}: {$revisionError}");
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
                $this->prepared[] = [
                    'operation' => $op,
                    'target' => $path,
                    'temp' => null,
                    'backup' => null,
                    'mode' => (@fileperms($path) ?: 0644) & 0777,
                    'content' => null,
                    'state' => 'prepared',
                    'lock' => null,
                    'original_identity' => null,
                    'published_identity' => null,
                    'temp_identity' => null,
                ];

                continue;
            }

            if ($op->type === 'add') {
                // Vuln 3: TOCTOU re-check before write
                $this->assertNoSymlink($path, $op->path);
                if (file_exists($path)) {
                    throw new \RuntimeException("Add failed: file already exists: {$op->path}");
                }
                $this->ensureDirectory(dirname($path));

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
                $mode = 0666 & ~umask();
                $temporary = $this->writeTempFile($content, dirname($path), $mode);
                $this->prepared[] = [
                    'operation' => $op,
                    'target' => $path,
                    'temp' => $temporary,
                    'backup' => null,
                    'mode' => $mode,
                    'content' => $content,
                    'state' => 'prepared',
                    'lock' => null,
                    'original_identity' => null,
                    'published_identity' => null,
                    'temp_identity' => $this->pathIdentity($temporary),
                ];

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
                $mode = (@fileperms($path) ?: 0644) & 0777;
                $temporary = $this->writeTempFile($patched, dirname($path), $mode);
                $this->prepared[] = [
                    'operation' => $op,
                    'target' => $path,
                    'temp' => $temporary,
                    'backup' => null,
                    'mode' => $mode,
                    'content' => $patched,
                    'state' => 'prepared',
                    'lock' => null,
                    'original_identity' => null,
                    'published_identity' => null,
                    'temp_identity' => $this->pathIdentity($temporary),
                ];
            }
        }
    }

    /**
     * @param  PatchOperation[]  $operations
     * @return string[]
     */
    private function commit(array $operations, ToolUseContext $context): array
    {
        $this->acquirePathLocks($context);
        $historyManager = $this->historyManager->forSession($context->sessionId);

        foreach ($this->prepared as $entry) {
            if ($entry['operation']->type !== 'add') {
                $historyManager->recordBefore($entry['target']);
            }
        }

        foreach ($this->prepared as $index => $entry) {
            $this->assertLockedTargetUnchanged($index, $context);
            if ($entry['operation']->type !== 'add') {
                $this->prepared[$index]['backup'] = $this->reserveBackupPath(
                    dirname($entry['target']),
                );
            }
        }

        foreach ($this->prepared as $index => $entry) {
            $operation = $entry['operation'];
            $target = $entry['target'];
            $this->beforeCommitOperation($operation, $target);
            $this->assertNoSymlink($target, $operation->path);
            $this->assertLockedTargetUnchanged($index, $context);

            if ($operation->type === 'add') {
                $this->mustMove($entry['temp'], $target);
                $this->prepared[$index]['temp'] = null;
                $this->prepared[$index]['published_identity'] = $entry['temp_identity'];
                $this->prepared[$index]['state'] = 'committed';
                if (! $this->sameIdentity(
                    $entry['temp_identity'],
                    $this->pathIdentity($target),
                )) {
                    throw new FileConflictException(
                        "Added target changed during commit: {$operation->path}",
                    );
                }

                continue;
            }

            $backup = $entry['backup'];
            if ($backup === null) {
                throw new \RuntimeException("Missing rollback backup path for {$operation->path}");
            }
            $this->mustMove($target, $backup);
            $this->prepared[$index]['state'] = 'backed_up';
            if (! $this->sameIdentity(
                $entry['original_identity'],
                $this->pathIdentity($backup),
            )) {
                throw new FileConflictException(
                    "Patch target changed during commit: {$operation->path}",
                );
            }

            if ($operation->type === 'update') {
                $this->mustMove($entry['temp'], $target);
                $this->prepared[$index]['temp'] = null;
                $this->prepared[$index]['published_identity'] = $entry['temp_identity'];
                if (! $this->sameIdentity(
                    $entry['temp_identity'],
                    $this->pathIdentity($target),
                )) {
                    throw new FileConflictException(
                        "Updated target changed during commit: {$operation->path}",
                    );
                }
            }
            $this->prepared[$index]['state'] = 'committed';
        }

        $cleanupWarnings = [];
        foreach ($this->prepared as $entry) {
            $backup = $entry['backup'];
            if ($backup !== null && is_file($backup) && ! @unlink($backup)) {
                $cleanupWarnings[] = "Warning: unable to remove rollback backup {$backup}";
            }
        }

        foreach ($this->prepared as $entry) {
            if ($entry['operation']->type !== 'delete' && $entry['content'] !== null) {
                $context->recordFileRead($entry['target'], $entry['content']);
            }
        }

        $this->releasePathLocks();
        $this->clearState();

        $messages = array_map(fn (PatchOperation $op) => match ($op->type) {
            'add' => "Added: {$op->path}",
            'update' => "Updated: {$op->path}",
            'delete' => "Deleted: {$op->path}",
            default => "Processed: {$op->path}",
        }, $operations);

        return array_merge($messages, $cleanupWarnings);
    }

    private function acquirePathLocks(ToolUseContext $context): void
    {
        $indices = array_keys($this->prepared);
        usort(
            $indices,
            fn (int $left, int $right): int => strcmp(
                $this->prepared[$left]['target'],
                $this->prepared[$right]['target'],
            ),
        );

        foreach ($indices as $index) {
            $entry = $this->prepared[$index];
            $isAdd = $entry['operation']->type === 'add';
            $this->beforeAcquirePath($entry['operation'], $entry['target']);
            $this->assertNoSymlink($entry['target'], $entry['operation']->path);
            $handle = @fopen($entry['target'], $isAdd ? 'x+b' : 'r+b');
            if ($handle === false) {
                $message = $isAdd
                    ? "Add target was created concurrently: {$entry['operation']->path}"
                    : "Unable to lock patch target: {$entry['operation']->path}";
                throw new FileConflictException($message);
            }

            $handleStat = @fstat($handle);
            $pathIdentity = $this->pathIdentity($entry['target']);
            $handleIdentity = $this->statIdentity($handleStat);
            $this->prepared[$index]['lock'] = $handle;
            $this->prepared[$index]['original_identity'] = $handleIdentity;
            if ($isAdd) {
                $this->prepared[$index]['state'] = 'reserved';
            }

            if ($handleIdentity === null
                || ! $this->sameIdentity($handleIdentity, $pathIdentity)
                || ! @flock($handle, LOCK_EX)) {
                throw new FileConflictException(
                    "Patch target changed while acquiring lock: {$entry['operation']->path}",
                );
            }

            $this->assertLockedTargetUnchanged($index, $context);
        }
    }

    private function assertLockedTargetUnchanged(int $index, ToolUseContext $context): void
    {
        $entry = $this->prepared[$index];
        $handle = $entry['lock'];
        if (! is_resource($handle)) {
            throw new \RuntimeException("Patch target lock is missing: {$entry['operation']->path}");
        }

        $handleStat = @fstat($handle);
        $handleIdentity = $this->statIdentity($handleStat);
        $pathIdentity = $this->pathIdentity($entry['target']);
        if ($handleIdentity === null
            || ! $this->sameIdentity($entry['original_identity'], $handleIdentity)
            || ! $this->sameIdentity($handleIdentity, $pathIdentity)) {
            throw new FileConflictException(
                "Patch target changed after it was read: {$entry['operation']->path}",
            );
        }

        if ($entry['operation']->type === 'add') {
            if (($handleStat['size'] ?? null) !== 0) {
                throw new FileConflictException(
                    "Add target was modified concurrently: {$entry['operation']->path}",
                );
            }

            return;
        }

        $expectedRevision = $context->getFileRevision($entry['target']);
        $currentRevision = FileRevision::captureFromHandle(
            $handle,
            $entry['target'],
        );
        if ($expectedRevision === null
            || ! $expectedRevision->complete
            || $currentRevision === null
            || ! $expectedRevision->sameVersion($currentRevision)) {
            throw new FileConflictException(
                "File changed since it was read: {$entry['operation']->path}. Read it again before patching.",
            );
        }
    }

    private function rollback(): ?string
    {
        $errors = [];

        for ($index = count($this->prepared) - 1; $index >= 0; $index--) {
            $entry = $this->prepared[$index];
            $operation = $entry['operation'];
            $target = $entry['target'];
            $backup = $entry['backup'];

            if ($operation->type === 'add') {
                $identity = $entry['state'] === 'committed'
                    ? $entry['published_identity']
                    : $entry['original_identity'];
                if ($entry['state'] !== 'prepared'
                    && ! $this->removePathWithIdentity($target, $identity)) {
                    $errors[] = "refusing to remove concurrently replaced add target {$target}";
                }

                continue;
            }

            if ($operation->type === 'update' && $entry['state'] === 'committed') {
                if (! $this->removePathWithIdentity($target, $entry['published_identity'])) {
                    $errors[] = "refusing to remove concurrently replaced update target {$target}";
                    continue;
                }
            }

            if ($operation->type !== 'add'
                && $entry['state'] !== 'prepared'
                && $backup !== null
                && file_exists($backup)) {
                if (file_exists($target)) {
                    $errors[] = "rollback target already exists: {$target}";
                } elseif (! $this->sameIdentity(
                    $entry['original_identity'],
                    $this->pathIdentity($backup),
                )) {
                    $errors[] = "rollback backup identity changed: {$backup}";
                } elseif (! $this->movePath($backup, $target)) {
                    $errors[] = "unable to restore {$target} from {$backup}";
                }
            }
        }

        foreach ($this->prepared as $entry) {
            $temporaryPath = $entry['temp'];
            if ($temporaryPath !== null
                && file_exists($temporaryPath)
                && ! @unlink($temporaryPath)) {
                $errors[] = "unable to remove temporary file {$temporaryPath}";
            }
        }

        $this->releasePathLocks();

        foreach (array_reverse($this->createdDirectories) as $directory) {
            if (is_dir($directory) && ! @rmdir($directory)) {
                $entries = @scandir($directory);
                if ($entries === false || array_diff($entries, ['.', '..']) === []) {
                    $errors[] = "unable to remove temporary directory {$directory}";
                }
            }
        }

        $this->clearState();

        return $errors === [] ? null : implode('; ', array_unique($errors));
    }

    private function writeTempFile(string $content, string $dir, int $mode): string
    {
        $tmp = tempnam($dir, '.haocode_patch_');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create tempfile in {$dir}");
        }

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to open tempfile: {$tmp}");
        }

        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Failed to write tempfile: {$tmp}");
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new \RuntimeException("Failed to flush tempfile: {$tmp}");
            }
            if (function_exists('fsync') && ! @fsync($handle)) {
                throw new \RuntimeException("Failed to sync tempfile: {$tmp}");
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);

            throw $e;
        }
        fclose($handle);

        if (! @chmod($tmp, $mode)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to preserve file mode on tempfile: {$tmp}");
        }

        return $tmp;
    }

    private function reserveBackupPath(string $directory): string
    {
        $backup = tempnam($directory, '.haocode_patch_backup_');
        if ($backup === false) {
            throw new \RuntimeException("Failed to reserve rollback backup in {$directory}");
        }
        if (! @unlink($backup)) {
            throw new \RuntimeException("Failed to prepare rollback backup: {$backup}");
        }

        return $backup;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $missing = [];
        $current = $directory;
        while (! is_dir($current)) {
            if (file_exists($current)) {
                throw new \RuntimeException("Cannot create directory over existing path: {$current}");
            }
            $missing[] = $current;
            $parent = dirname($current);
            if ($parent === $current) {
                throw new \RuntimeException("Cannot find existing parent for directory: {$directory}");
            }
            $current = $parent;
        }

        foreach (array_reverse($missing) as $path) {
            if (! @mkdir($path, 0755) && ! is_dir($path)) {
                throw new \RuntimeException("Cannot create directory: {$path}");
            }
            $this->createdDirectories[] = $path;
        }
    }

    private function mustMove(?string $from, string $to): void
    {
        if ($from === null || ! $this->movePath($from, $to)) {
            throw new \RuntimeException("rename() failed: {$from} → {$to}");
        }
    }

    protected function movePath(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    protected function beforeCommitOperation(PatchOperation $operation, string $target): void
    {
    }

    protected function beforeAcquirePath(PatchOperation $operation, string $target): void
    {
    }

    /**
     * @param  array{device: int, inode: int}|null  $identity
     */
    private function removePathWithIdentity(string $path, ?array $identity): bool
    {
        if (! file_exists($path)) {
            return true;
        }
        if (! $this->sameIdentity($identity, $this->pathIdentity($path))) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * @return array{device: int, inode: int}|null
     */
    private function pathIdentity(string $path): ?array
    {
        clearstatcache(true, $path);

        return $this->statIdentity(@stat($path));
    }

    /**
     * @param  array<string|int, mixed>|false  $stat
     * @return array{device: int, inode: int}|null
     */
    private function statIdentity(array|false $stat): ?array
    {
        if (! is_array($stat)
            || ! isset($stat['dev'], $stat['ino'])
            || ! is_int($stat['dev'])
            || ! is_int($stat['ino'])) {
            return null;
        }

        return [
            'device' => $stat['dev'],
            'inode' => $stat['ino'],
        ];
    }

    /**
     * @param  array{device: int, inode: int}|null  $left
     * @param  array{device: int, inode: int}|null  $right
     */
    private function sameIdentity(?array $left, ?array $right): bool
    {
        return $left !== null
            && $right !== null
            && $left['device'] === $right['device']
            && $left['inode'] === $right['inode'];
    }

    private function releasePathLocks(): void
    {
        foreach ($this->prepared as $index => $entry) {
            if (! is_resource($entry['lock'])) {
                continue;
            }
            @flock($entry['lock'], LOCK_UN);
            fclose($entry['lock']);
            $this->prepared[$index]['lock'] = null;
        }
    }

    private function clearState(): void
    {
        $this->prepared = [];
        $this->createdDirectories = [];
    }

    private function rollbackSuffix(?string $rollbackError): string
    {
        return $rollbackError === null ? '' : " (rollback errors: {$rollbackError})";
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
