<?php

namespace HaoCode\Services\FileEdit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;

trait PatchApplierConstructConcern
{

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
}
