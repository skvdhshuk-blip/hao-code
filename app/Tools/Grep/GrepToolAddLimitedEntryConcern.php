<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Filesystem\GitignoreMatcher;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait GrepToolAddLimitedEntryConcern
{

    /** @param list<string> $entries */
    private function addLimitedEntry(
        string $entry,
        array &$entries,
        int &$seenEntries,
        int $offset,
        int $headLimit,
        int &$capturedOutputBytes,
    ): ?bool
    {
        $seenEntries++;
        if ($seenEntries > $offset && count($entries) < $headLimit) {
            $entryBytes = strlen($entry) + 1;
            if ($capturedOutputBytes > self::PHP_FALLBACK_OUTPUT_MAX - $entryBytes) {
                return null;
            }
            $entries[] = $entry;
            $capturedOutputBytes += $entryBytes;
        }

        return $seenEntries >= $offset + $headLimit;
    }

    private function isIgnoredPath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        foreach (self::IGNORED_DIRECTORIES as $ignored) {
            if ($relativePath === $ignored || str_starts_with($relativePath, $ignored.'/')) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $file, string $searchPath): string
    {
        if (is_file($searchPath)) {
            return $this->pathBasename($file);
        }

        $relative = $this->stripPathPrefix($file, $searchPath) ?? $file;

        return str_replace('\\', '/', $relative);
    }

    private function normalizeOutputPath(string $line, string $searchPath): string
    {
        if (is_file($searchPath)) {
            $suffix = $this->stripFilePathPrefix($line, $searchPath);

            return $suffix === null ? $line : $this->pathBasename($searchPath).$suffix;
        }

        return $this->stripPathPrefix($line, $searchPath) ?? $line;
    }

    private function stripPathPrefix(string $path, string $basePath): ?string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedBase = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $length = strlen($normalizedBase);
        if ($length === 0) {
            return null;
        }

        $matches = $this->isWindowsPath($path)
            || $this->isWindowsPath($basePath)
            || PHP_OS_FAMILY === 'Windows'
                ? strncasecmp(substr($normalizedPath, 0, $length), $normalizedBase, $length) === 0
                : str_starts_with($normalizedPath, $normalizedBase);

        return $matches ? substr($normalizedPath, $length) : null;
    }

    private function stripFilePathPrefix(string $path, string $filePath): ?string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedFile = str_replace('\\', '/', $filePath);
        $length = strlen($normalizedFile);
        $caseInsensitive = $this->isWindowsPath($path)
            || $this->isWindowsPath($filePath)
            || PHP_OS_FAMILY === 'Windows';
        $matches = $caseInsensitive
            ? strncasecmp(substr($normalizedPath, 0, $length), $normalizedFile, $length) === 0
            : str_starts_with($normalizedPath, $normalizedFile);
        if (! $matches) {
            return null;
        }

        $suffix = substr($normalizedPath, $length);
        if ($suffix !== '' && ! str_starts_with($suffix, ':')) {
            return null;
        }

        return $suffix;
    }

    private function isWindowsPath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/\/)/', $path) === 1;
    }

    private function pathBasename(string $path): string
    {
        return basename(str_replace('\\', '/', $path));
    }

    private function noMatchesMessage(string $pattern): string
    {
        return "No matches found for pattern: {$pattern}";
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        $input['path'] = $this->resolvePath(
            is_string($input['path'] ?? null) ? $input['path'] : $context->workingDirectory,
            $context->workingDirectory,
        );

        return $input;
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function maxResultSizeChars(): int
    {
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        $pattern = $input['pattern'] ?? 'pattern';

        return 'Searching for ' . (mb_strlen($pattern) > 30 ? mb_substr($pattern, 0, 30) . '…' : $pattern);
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => true, 'isRead' => false, 'isList' => false];
    }
}
