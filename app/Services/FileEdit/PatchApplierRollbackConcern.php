<?php

namespace HaoCode\Services\FileEdit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;

trait PatchApplierRollbackConcern
{

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
