<?php

namespace HaoCode\Services\ToolResult;

use HaoCode\Support\Filesystem\CanonicalPathResolver;

trait ToolResultStorageConstructConcern
{

    public function __construct(string $sessionRoot, ?string $sessionId = null)
    {
        $sessionId ??= 'default';
        if ($sessionId === '' || strlen($sessionId) > 128
            || preg_match('/[^A-Za-z0-9_-]/', $sessionId) === 1
        ) {
            throw new \InvalidArgumentException(
                'Invalid session id: must be 1-128 characters of [A-Za-z0-9_-].',
            );
        }

        if (! is_string($sessionRoot) || trim($sessionRoot) === '') {
            throw new \RuntimeException('Session storage path must be a non-empty string.');
        }
        if (CanonicalPathResolver::isFilesystemRoot($sessionRoot)) {
            throw new \RuntimeException(
                "Refusing to use a filesystem root as session storage: {$sessionRoot}",
            );
        }

        $this->storageDir = rtrim($sessionRoot, '/\\')
            .DIRECTORY_SEPARATOR.$sessionId
            .DIRECTORY_SEPARATOR.'tool-results';
    }

    /**
     * Check if a tool result should be persisted to disk.
     */
    public function shouldPersist(string $output, int $threshold): bool
    {
        return mb_strlen($output) > $threshold;
    }

    /**
     * Persist a tool result to disk and return the preview message.
     *
     * @return array{filepath: string, size: int, preview: string, message: string}|null
     */
    public function persist(string $toolUseId, string $output): ?array
    {
        if (! $this->ensureStorageDirectory()) {
            return null;
        }

        // The physical filename must never contain the raw tool_use_id: that
        // id flows from model/gateway output and can include traversal tokens
        // (e.g. `../../../../escaped`). Keep a bounded readable prefix, hash
        // the complete id, and verify the resolved path remains inside this
        // session. The in-memory business key still uses the original id.
        $filepath = $this->safeStoragePath($toolUseId);

        if (! $this->writeAtomically($filepath, $output)) {
            return null;
        }

        $size = mb_strlen($output);
        $preview = $this->generatePreview($output, self::PREVIEW_SIZE_BYTES);
        $sizeLabel = $this->formatSize($size);
        $message = $this->buildPersistedMessage($filepath, $sizeLabel, $preview);

        // Track for replay stability
        $this->seenIds[$toolUseId] = true;
        $this->replacements[$toolUseId] = $message;

        return [
            'filepath' => $filepath,
            'size' => $size,
            'preview' => $preview,
            'message' => $message,
        ];
    }

    /**
     * Build a filesystem path for a tool result, hardened against traversal.
     *
     * Keep a short readable prefix while hashing the complete untrusted id so
     * traversal tokens and sanitization collisions can never influence the
     * destination.
     */
    private function safeStoragePath(string $toolUseId): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', $toolUseId);
        $prefix = is_string($prefix) ? trim($prefix, '_-') : '';
        $prefix = substr($prefix !== '' ? $prefix : 'tool-result', 0, 24);

        $filepath = $this->storageDir.DIRECTORY_SEPARATOR
            .$prefix.'-'.hash('sha256', $toolUseId).'.txt';

        $realDir = realpath($this->storageDir);
        if ($realDir === false) {
            throw new \RuntimeException('Tool-result storage directory is not canonical.');
        }
        $normalizedDir = rtrim($realDir, '/\\');
        $realFile = realpath($filepath);
        if (
            $realFile !== false
            && $realFile !== $normalizedDir
            && ! str_starts_with($realFile, $normalizedDir . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException(
                'Refusing to persist tool result outside storage directory.',
            );
        }

        return $filepath;
    }

    private function ensureStorageDirectory(): bool
    {
        $sessionDirectory = dirname($this->storageDir);
        $sessionRoot = dirname($sessionDirectory);
        if (CanonicalPathResolver::isFilesystemRoot($sessionRoot)) {
            return false;
        }

        if (! $this->ensureConfiguredRoot($sessionRoot)
            || ! $this->ensurePrivateDirectory($sessionDirectory)
            || ! $this->ensurePrivateDirectory($this->storageDir)
        ) {
            return false;
        }

        $realRoot = realpath($sessionRoot);
        $realSession = realpath($sessionDirectory);
        $realDirectory = realpath($this->storageDir);
        if ($realRoot === false || $realSession === false || $realDirectory === false
            || CanonicalPathResolver::isFilesystemRoot($realRoot)
            || is_link($sessionRoot)
            || is_link($sessionDirectory)
            || is_link($this->storageDir)
        ) {
            return false;
        }

        $rootPrefix = rtrim($realRoot, '/\\').DIRECTORY_SEPARATOR;
        $sessionPrefix = rtrim($realSession, '/\\').DIRECTORY_SEPARATOR;
        if (! str_starts_with($realSession, $rootPrefix)
            || ! str_starts_with($realDirectory, $sessionPrefix)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Keep caller-owned roots unchanged while rejecting roots that allow
     * untrusted users to replace session entries. Sticky shared roots such as
     * /tmp remain valid because their ownership rules prevent that attack.
     */
    private function ensureConfiguredRoot(string $path): bool
    {
        if (CanonicalPathResolver::isFilesystemRoot($path) || is_link($path)) {
            return false;
        }

        $created = false;
        if (! is_dir($path)) {
            if (@lstat($path) !== false) {
                return false;
            }
            $created = @mkdir($path, 0700, true);
            if (! $created && ! is_dir($path)) {
                return false;
            }
        }

        if (is_link($path) || ! $this->isDirectory($path)) {
            return false;
        }
        if ($created) {
            return @chmod($path, 0700);
        }

        return ! $this->isUnsafeSharedRoot($path);
    }

    private function ensurePrivateDirectory(string $path): bool
    {
        if (CanonicalPathResolver::isFilesystemRoot($path) || is_link($path)) {
            return false;
        }
        if (! is_dir($path)) {
            if (@lstat($path) !== false
                || (! @mkdir($path, 0700, true) && ! is_dir($path))
            ) {
                return false;
            }
        }

        return ! is_link($path)
            && $this->isDirectory($path)
            && @chmod($path, 0700);
    }

    private function isDirectory(string $path): bool
    {
        $stat = @lstat($path);

        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0040000;
    }

    private function isUnsafeSharedRoot(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $permissions = @fileperms($path);

        return $permissions === false
            || (($permissions & 0022) !== 0 && ($permissions & 01000) === 0);
    }

    private function writeAtomically(string $filepath, string $output): bool
    {
        $temporary = tempnam($this->storageDir, '.tool-result-');
        if ($temporary === false) {
            return false;
        }
        if (! @chmod($temporary, 0600)) {
            @unlink($temporary);

            return false;
        }

        $stream = null;
        try {
            $stream = @fopen($temporary, 'wb');
            if ($stream === false) {
                return false;
            }

            $offset = 0;
            $length = strlen($output);
            while ($offset < $length) {
                $written = @fwrite($stream, substr($output, $offset));
                if ($written === false || $written === 0) {
                    return false;
                }
                $offset += $written;
            }
            if (! @fflush($stream)) {
                return false;
            }
            if (function_exists('fsync') && ! @fsync($stream)) {
                return false;
            }
            fclose($stream);
            $stream = null;

            if (! @chmod($temporary, 0600)) {
                return false;
            }
            if (! @rename($temporary, $filepath)) {
                return false;
            }
            @chmod($filepath, 0600);

            return true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Enforce per-message aggregate budget across tool results.
     *
     * Returns the results array with large results replaced by persisted previews.
     *
     * @param array<int, array{tool_use_id: string, content: string, is_error: bool}> $results
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    public function enforceMessageBudget(array $results): array
    {
        $totalChars = 0;
        foreach ($results as $r) {
            $totalChars += mb_strlen($r['content'] ?? '');
        }

        if ($totalChars <= self::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS) {
            // Mark all as seen
            foreach ($results as $r) {
                $this->seenIds[$r['tool_use_id']] = true;
            }
            return $results;
        }

        // Sort candidates by size (largest first) for greedy replacement
        $candidates = [];
        foreach ($results as $idx => $r) {
            $id = $r['tool_use_id'];
            $size = mb_strlen($r['content'] ?? '');

            // Previously replaced → re-apply cached replacement
            if (isset($this->replacements[$id])) {
                $results[$idx]['content'] = $this->replacements[$id];
                continue;
            }

            // Previously seen but not replaced → frozen (cannot change)
            if (isset($this->seenIds[$id])) {
                continue;
            }

            // Errors are never replaced
            if ($r['is_error'] ?? false) {
                $this->seenIds[$id] = true;
                continue;
            }

            $candidates[] = ['idx' => $idx, 'id' => $id, 'size' => $size];
        }

        // Sort largest first
        usort($candidates, fn($a, $b) => $b['size'] <=> $a['size']);

        // Recalculate total after re-applying cached replacements
        $currentTotal = 0;
        foreach ($results as $r) {
            $currentTotal += mb_strlen($r['content'] ?? '');
        }

        // Replace largest fresh results until under budget
        foreach ($candidates as $c) {
            if ($currentTotal <= self::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS) {
                $this->seenIds[$c['id']] = true;
                continue;
            }

            $persisted = $this->persist($c['id'], $results[$c['idx']]['content']);
            if ($persisted !== null) {
                $oldSize = mb_strlen($results[$c['idx']]['content']);
                $results[$c['idx']]['content'] = $persisted['message'];
                $currentTotal -= $oldSize;
                $currentTotal += mb_strlen($persisted['message']);
            }

            $this->seenIds[$c['id']] = true;
        }

        return $results;
    }

    /**
     * Generate a preview truncated at a newline boundary.
     */
    public function generatePreview(string $content, int $maxBytes): string
    {
        return self::previewContent($content, $maxBytes);
    }

    public static function previewContent(string $content, int $maxBytes): string
    {
        if (mb_strlen($content) <= $maxBytes) {
            return $content;
        }

        $truncated = mb_substr($content, 0, $maxBytes);
        $lastNewline = strrpos($truncated, "\n");

        // Use newline boundary if it's past 50% of the limit
        if ($lastNewline !== false && $lastNewline > $maxBytes * 0.5) {
            return mb_substr($content, 0, $lastNewline);
        }

        return $truncated;
    }

    /**
     * Build the persisted-output message shown to the model.
     */
    private function buildPersistedMessage(string $filepath, string $sizeLabel, string $preview): string
    {
        return "<persisted-output>\n"
            . "Output too large ({$sizeLabel}). Full output saved to: {$filepath}\n\n"
            . "Preview (first " . self::PREVIEW_SIZE_BYTES . "B):\n"
            . $preview
            . "\n...\n"
            . "</persisted-output>";
    }

    private function formatSize(int $chars): string
    {
        if ($chars >= 1_000_000) {
            return round($chars / 1_000_000, 1) . 'M chars';
        }
        if ($chars >= 1_000) {
            return round($chars / 1_000, 1) . 'K chars';
        }

        return $chars . ' chars';
    }

    /**
     * Get replacement state for session resume.
     *
     * @return array{seenIds: string[], replacements: array<string, string>}
     */
    public function getState(): array
    {
        return [
            'seenIds' => array_keys($this->seenIds),
            'replacements' => $this->replacements,
        ];
    }

    /**
     * Restore state from a previous session (for resume).
     *
     * @param array{seenIds?: string[], replacements?: array<string, string>} $state
     */
    public function restoreState(array $state): void
    {
        $invalidReplacementIds = [];
        $replacements = is_array($state['replacements'] ?? null)
            ? $state['replacements']
            : [];

        foreach ($replacements as $id => $message) {
            if (! is_string($id) || ! is_string($message)
                || ! $this->isRestorableReplacement($id, $message)
            ) {
                if (is_string($id)) {
                    $invalidReplacementIds[$id] = true;
                }
                continue;
            }
            $this->replacements[$id] = $message;
        }

        $seenIds = is_array($state['seenIds'] ?? null) ? $state['seenIds'] : [];
        foreach ($seenIds as $id) {
            if (! is_string($id) || $id === '' || isset($invalidReplacementIds[$id])) {
                continue;
            }
            $this->seenIds[$id] = true;
        }
        foreach (array_keys($this->replacements) as $id) {
            $this->seenIds[$id] = true;
        }
    }
}
