<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

trait LocalSandboxBackendToRemotePathConcern
{

    private function toRemotePath(string $localPath): string
    {
        $relative = ltrim(str_replace($this->root, '', $localPath), DIRECTORY_SEPARATOR);
        return '/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isLink()) {
                @unlink($file->getPathname());
            } elseif ($file instanceof \SplFileInfo && $file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return \Generator<int, \SplFileInfo> */
    private function iterFiles(string $dir, int &$visitedFiles): \Generator
    {
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($dir): bool {
                $relative = $this->relativeLocalPath($current->getPathname(), $dir);
                if ($this->isIgnoredPath($relative)) {
                    return false;
                }

                return ! $current->isDir() || ! $current->isLink();
            },
        );
        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && ! $file->isLink() && $file->isFile()) {
                $visitedFiles++;
                if ($visitedFiles > self::MAX_VISITED_FILES) {
                    break;
                }

                yield $file;
            }
        }
    }

    /**
     * @param list<array{path: string, mtime: int}> $matches
     */
    private function addTopGlobMatch(array &$matches, string $localPath): void
    {
        $matches[] = [
            'path' => $this->toRemotePath($localPath),
            'mtime' => filemtime($localPath) ?: 0,
        ];
        usort($matches, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        if (count($matches) > self::MAX_GLOB_RESULTS) {
            array_pop($matches);
        }
    }

    private function relativeLocalPath(string $localPath, string $baseLocal): string
    {
        $prefix = rtrim($baseLocal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($localPath, $prefix)
            ? substr($localPath, strlen($prefix))
            : $localPath;

        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim($relative, DIRECTORY_SEPARATOR));
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

    private function isTextFile(string $localPath): bool
    {
        $sample = @file_get_contents($localPath, false, null, 0, 1024);
        if (! is_string($sample)) {
            return false;
        }

        return ! str_contains($sample, "\0");
    }

    private function skipRestOfLine($handle): void
    {
        while (($chunk = fgets($handle, 8192)) !== false) {
            if (str_ends_with($chunk, "\n")) {
                return;
            }
        }
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function drainExecPipes(array $pipes, string &$stdout, string &$stderr, int &$capturedBytes): bool
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $target) {
            if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                continue;
            }

            $chunk = @stream_get_contents($pipes[$index]);
            if (! is_string($chunk) || $chunk === '') {
                continue;
            }

            if ($target === 'stdout') {
                if ($this->appendExecOutputChunk($stdout, $chunk, $capturedBytes, $target)) {
                    return true;
                }
            } else {
                if ($this->appendExecOutputChunk($stderr, $chunk, $capturedBytes, $target)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function appendExecOutputChunk(string &$output, string $chunk, int &$capturedBytes, string $streamName): bool
    {
        $remaining = self::MAX_EXEC_OUTPUT_BYTES - $capturedBytes;
        if (strlen($chunk) <= $remaining) {
            $output .= $chunk;
            $capturedBytes += strlen($chunk);

            return false;
        }

        if ($remaining > 0) {
            $output .= substr($chunk, 0, $remaining);
            $capturedBytes += $remaining;
        }
        $output .= "\n\n[{$streamName} truncated at ".self::MAX_EXEC_OUTPUT_BYTES.' bytes; command terminated]';

        return true;
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        return str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
    }

    private function globToRegex(string $pattern): string
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\\*\\*/', '__DOUBLE_STAR_SLASH__', $regex);
        $regex = str_replace('\\*\\*', '__DOUBLE_STAR__', $regex);
        $regex = str_replace('\\*', '[^/]*', $regex);
        $regex = str_replace('\\?', '[^/]', $regex);
        $regex = str_replace('__DOUBLE_STAR_SLASH__', '(?:.*/)?', $regex);
        $regex = str_replace('__DOUBLE_STAR__', '.*', $regex);
        return '#^'.$regex.'$#';
    }

    /** @return string[] */
    private function expandBracePatterns(string $pattern): array
    {
        $expanded = $this->expandBracePatternsBounded($pattern, self::MAX_BRACE_EXPANSIONS);
        if (count($expanded) > self::MAX_BRACE_EXPANSIONS) {
            throw new \LengthException('Sandbox glob brace expansion is too broad; narrow the pattern to fewer alternatives.');
        }

        return array_values(array_unique($expanded));
    }

    /** @return list<string> */
    private function expandBracePatternsBounded(string $pattern, int $limit): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return [$pattern];
        }
        $brace = $matches[0][0];
        $offset = $matches[0][1];
        $options = explode(',', $matches[1][0]);
        $prefix = substr($pattern, 0, $offset);
        $suffix = substr($pattern, $offset + strlen($brace));
        $expanded = [];
        foreach ($options as $option) {
            foreach ($this->expandBracePatternsBounded($prefix.$option.$suffix, $limit) as $variant) {
                $expanded[] = $variant;
                if (count($expanded) > $limit) {
                    return $expanded;
                }
            }
        }
        return array_values(array_unique($expanded));
    }
}
