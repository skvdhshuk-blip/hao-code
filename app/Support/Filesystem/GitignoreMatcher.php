<?php

declare(strict_types=1);

namespace HaoCode\Support\Filesystem;

/**
 * Bounded, path-aware matching for .gitignore files used by host searches.
 *
 * @internal
 */
final class GitignoreMatcher
{
    private const MAX_BYTES = 1_000_000;
    private const MAX_LINE_BYTES = 16_384;
    private const MAX_PATTERNS = 10_000;

    /** @var list<array{base:string,pattern:string,negated:bool,directory:bool,anchored:bool}> */
    private array $patterns = [];

    /** @var array<string, true> */
    private array $loadedDirectories = [];

    private int $bytesRead = 0;
    private int $patternCount = 0;

    private function __construct(
        private readonly string $searchRoot,
        private readonly ?string $repositoryRoot,
        private readonly string $toolName,
    ) {
    }

    public static function forSearchRoot(string $searchRoot, string $toolName = 'Search'): self
    {
        $root = self::normalizePath($searchRoot);
        $repositoryRoot = self::findRepositoryRoot($root);
        $matcher = new self($root, $repositoryRoot, $toolName);
        $matcher->loadInitialIgnoreFiles();

        return $matcher;
    }

    public function isIgnored(string $path, bool $isDirectory): bool
    {
        $path = self::normalizePath($path);
        $this->loadIgnoreFilesFor($path);

        $ignored = false;
        foreach ($this->patterns as $pattern) {
            if (! $this->matches($path, $isDirectory, $pattern)) {
                continue;
            }

            $ignored = ! $pattern['negated'];
        }

        return $ignored;
    }

    public function shouldDescendForNegation(string $path): bool
    {
        $path = self::normalizePath($path);
        $this->loadIgnoreFilesFor($path);

        foreach ($this->patterns as $pattern) {
            if (! $pattern['negated']) {
                continue;
            }

            $relative = $this->relativePath($path, $pattern['base']);
            if ($relative === null) {
                continue;
            }

            $negated = trim($pattern['pattern'], '/');
            if ($negated === '') {
                continue;
            }

            // A basename-only negation may match below any ignored directory.
            if (! str_contains($negated, '/')) {
                return true;
            }

            if ($relative === ''
                || $negated === $relative
                || str_starts_with($negated, $relative.'/')
                || str_starts_with($relative, $negated.'/')
            ) {
                return true;
            }
        }

        return false;
    }

    private function loadInitialIgnoreFiles(): void
    {
        if ($this->repositoryRoot === null) {
            $this->loadIgnoreDirectory($this->searchRoot);

            return;
        }

        $directories = [];
        $current = $this->searchRoot;
        while (CanonicalPathResolver::isWithin($current, $this->repositoryRoot)) {
            array_unshift($directories, $current);
            if (self::samePath($current, $this->repositoryRoot)) {
                break;
            }

            $parent = self::normalizePath(dirname($current));
            if (self::samePath($parent, $current)) {
                break;
            }
            $current = $parent;
        }

        foreach ($directories as $directory) {
            $this->loadIgnoreDirectory($directory);
        }
    }

    private function loadIgnoreFilesFor(string $path): void
    {
        if (! CanonicalPathResolver::isWithin($path, $this->searchRoot)) {
            return;
        }

        $directory = is_dir($path) ? $path : dirname($path);
        $directory = self::normalizePath($directory);
        $directories = [];
        $current = $directory;
        while (CanonicalPathResolver::isWithin($current, $this->searchRoot)) {
            $directories[] = $current;
            if (self::samePath($current, $this->searchRoot)) {
                break;
            }

            $parent = self::normalizePath(dirname($current));
            if (self::samePath($parent, $current)) {
                break;
            }
            $current = $parent;
        }

        foreach (array_reverse($directories) as $baseDirectory) {
            $this->loadIgnoreDirectory($baseDirectory);
        }
    }

    private function loadIgnoreDirectory(string $baseDirectory): void
    {
        $baseDirectory = self::normalizePath($baseDirectory);
        if (isset($this->loadedDirectories[$baseDirectory])) {
            return;
        }
        $this->loadedDirectories[$baseDirectory] = true;

        // Never follow a symlink just to discover its ignore file.
        if (is_link($baseDirectory)) {
            return;
        }

        $gitignore = rtrim($baseDirectory, '/\\').DIRECTORY_SEPARATOR.'.gitignore';
        if (! is_file($gitignore) || ! is_readable($gitignore)) {
            return;
        }

        $handle = @fopen($gitignore, 'rb');
        if (! is_resource($handle)) {
            return;
        }

        try {
            while (($rawLine = @fgets($handle, self::MAX_LINE_BYTES + 2)) !== false) {
                $this->bytesRead += strlen($rawLine);
                if ($this->bytesRead > self::MAX_BYTES) {
                    throw new \LengthException(
                        $this->toolName.' refused to load .gitignore files larger than '
                        .self::MAX_BYTES.' bytes. Narrow the search root or simplify .gitignore.',
                    );
                }
                if (strlen($rawLine) > self::MAX_LINE_BYTES && ! str_ends_with($rawLine, "\n")) {
                    throw new \LengthException(
                        $this->toolName.' refused to load a .gitignore line larger than '
                        .self::MAX_LINE_BYTES.' bytes. Simplify .gitignore before searching.',
                    );
                }

                $line = trim($rawLine);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $negated = str_starts_with($line, '!');
                if ($negated) {
                    $line = substr($line, 1);
                }

                $line = str_replace('\\', '/', trim($line));
                if ($line === '') {
                    continue;
                }

                $anchored = str_starts_with($line, '/');
                $line = ltrim($line, '/');
                $directoryPattern = str_ends_with($line, '/');
                $line = rtrim($line, '/');
                if ($line === '') {
                    continue;
                }

                $this->patternCount++;
                if ($this->patternCount > self::MAX_PATTERNS) {
                    throw new \LengthException(
                        $this->toolName.' refused to load more than '.self::MAX_PATTERNS
                        .' .gitignore patterns. Simplify .gitignore before searching.',
                    );
                }

                $this->patterns[] = [
                    'base' => $baseDirectory,
                    'pattern' => $line,
                    'negated' => $negated,
                    'directory' => $directoryPattern,
                    'anchored' => $anchored,
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array{base:string,pattern:string,negated:bool,directory:bool,anchored:bool} $pattern */
    private function matches(string $path, bool $isDirectory, array $pattern): bool
    {
        $relativePath = $this->relativePath($path, $pattern['base']);
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        $rawPattern = $pattern['pattern'];
        if ($pattern['directory'] && ! $isDirectory && ! str_starts_with($relativePath, $rawPattern.'/')) {
            return false;
        }

        $flags = defined('FNM_PATHNAME') ? FNM_PATHNAME : 0;
        if ($pattern['anchored'] || str_contains($rawPattern, '/')) {
            return fnmatch($rawPattern, $relativePath, $flags)
                || str_starts_with($relativePath, $rawPattern.'/');
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $index => $segment) {
            if (! fnmatch($rawPattern, $segment)) {
                continue;
            }
            if ($index === count($segments) - 1 || $pattern['directory']) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $path, string $base): ?string
    {
        if (! CanonicalPathResolver::isWithin($path, $base)) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (self::samePath($path, $base)) {
            return '';
        }

        $prefix = $base.'/';
        if (PHP_OS_FAMILY === 'Windows') {
            if (strncasecmp($path, $prefix, strlen($prefix)) !== 0) {
                return null;
            }
        } elseif (! str_starts_with($path, $prefix)) {
            return null;
        }

        return substr($path, strlen($prefix));
    }

    private static function findRepositoryRoot(string $path): ?string
    {
        $current = is_dir($path) ? $path : dirname($path);
        while (true) {
            $marker = rtrim($current, '/\\').DIRECTORY_SEPARATOR.'.git';
            if (is_dir($marker) || is_file($marker)) {
                return $current;
            }

            $parent = self::normalizePath(dirname($current));
            if (self::samePath($parent, $current)) {
                return null;
            }
            $current = $parent;
        }
    }

    private static function normalizePath(string $path): string
    {
        $real = realpath($path);
        if (is_string($real)) {
            return $real;
        }

        return CanonicalPathResolver::resolve($path, (string) (getcwd() ?: DIRECTORY_SEPARATOR));
    }

    private static function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
