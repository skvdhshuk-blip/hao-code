<?php

namespace HaoCode\Tools\Glob;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class GlobTool extends BaseTool
{
    private const MAX_RESULTS = 100;
    private const MAX_VISITED_FILES = 20_000;
    private const MAX_PATTERN_LENGTH = 512;
    private const MAX_BRACE_EXPANSIONS = 256;
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '.claude/worktrees',
        'node_modules',
        'vendor',
    ];

    public function name(): string
    {
        return 'Glob';
    }

    public function description(): string
    {
        return <<<DESC
Fast file pattern matching tool that works with any codebase size.

- Supports glob patterns like "**/*.js" or "src/**/*.ts"
- Returns matching file paths sorted by modification time
- Use this tool when you need to find files by name patterns
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The glob pattern to match files against (e.g., "**/*.js")',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to search in. Defaults to current working directory.',
                ],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = $this->normalizePattern($input['pattern']);
        $path = $this->resolvePath(
            $input['path'] ?? $context->workingDirectory,
            $context->workingDirectory,
        );

        if (!is_dir($path)) {
            return ToolResult::error("Directory does not exist: {$path}");
        }
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return ToolResult::error('Glob pattern is too long; narrow the search pattern.');
        }

        try {
            $regexPatterns = array_map(
                fn (string $expandedPattern): string => $this->globToRegex($expandedPattern),
                $this->expandBracePatterns($pattern),
            );
        } catch (\LengthException $e) {
            return ToolResult::error($e->getMessage());
        }

        $matches = [];
        $totalCount = 0;
        $visitedEntries = 0;
        $truncatedByVisitLimit = false;
        $aborted = false;
        $gitignorePatterns = $this->loadGitignorePatterns($path);
        $this->globRecursive(
            $path,
            $regexPatterns,
            $gitignorePatterns,
            $matches,
            $totalCount,
            $visitedEntries,
            $truncatedByVisitLimit,
            $context->isAborted(...),
            $aborted,
        );
        if ($aborted) {
            return ToolResult::aborted('Glob search aborted.');
        }

        if (empty($matches)) {
            return ToolResult::success("No files matched pattern: {$pattern}");
        }

        // Sort by modification time (most recent first)
        usort($matches, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $truncated = $totalCount > self::MAX_RESULTS || $truncatedByVisitLimit;

        $output = "Found {$totalCount} file(s) matching '{$pattern}'";
        if ($truncated) {
            $output .= " (showing first ".self::MAX_RESULTS.")";
        }
        $output .= ":\n\n";

        foreach ($matches as $match) {
            $relative = $this->relativePath($match, $path);
            $output .= "  {$relative}\n";
        }

        if ($truncatedByVisitLimit) {
            $output .= "\n[Search stopped after visiting ".self::MAX_VISITED_FILES." filesystem entries. Narrow your path or pattern to continue.]";
        } elseif ($truncated) {
            $output .= "\n[" . ($totalCount - self::MAX_RESULTS) . " more files not shown. Narrow your pattern to see more.]";
        }

        return ToolResult::success($output);
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        $input['path'] = $this->resolvePath(
            is_string($input['path'] ?? null) ? $input['path'] : $context->workingDirectory,
            $context->workingDirectory,
        );

        return $input;
    }

    /**
     * @param list<string> $regexPatterns
     * @param list<array{pattern:string, negated:bool, directory:bool, anchored:bool}> $gitignorePatterns
     * @param list<string> $matches
     */
    private function globRecursive(
        string $dir,
        array $regexPatterns,
        array $gitignorePatterns,
        array &$matches,
        int &$totalCount,
        int &$visitedEntries,
        bool &$truncatedByVisitLimit,
        ?callable $shouldAbort = null,
        bool &$aborted = false,
    ): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $directory = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($dir, $gitignorePatterns): bool {
                $relativePath = $this->relativePath($current->getPathname(), $dir);

                if ($this->isIgnoredPath($relativePath)
                    || $this->isGitignoreIgnored($relativePath, $current->isDir(), $gitignorePatterns)) {
                    return false;
                }

                return ! $current->isDir() || ! $current->isLink();
            },
        );
        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                break;
            }

            $visitedEntries++;
            if ($visitedEntries > self::MAX_VISITED_FILES) {
                $truncatedByVisitLimit = true;
                break;
            }

            if ($file->isDir()) {
                continue;
            }

            $pathname = $file->getPathname();
            $relativePath = $this->relativePath($pathname, $dir);
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            foreach ($regexPatterns as $regexPattern) {
                if (preg_match($regexPattern, $relativePath)) {
                    $totalCount++;
                    $this->addTopMatch($matches, $pathname);
                    break;
                }
            }
        }
    }

    /** @param list<string> $matches */
    private function addTopMatch(array &$matches, string $path): void
    {
        $matches[] = $path;
        usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        if (count($matches) > self::MAX_RESULTS) {
            array_pop($matches);
        }
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

    /** @return list<array{pattern:string, negated:bool, directory:bool, anchored:bool}> */
    private function loadGitignorePatterns(string $root): array
    {
        $gitignore = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'.gitignore';
        if (! is_file($gitignore) || ! is_readable($gitignore)) {
            return [];
        }

        $patterns = [];
        $lines = @file($gitignore, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
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
            $directory = str_ends_with($line, '/');
            $line = rtrim($line, '/');
            if ($line === '') {
                continue;
            }

            $patterns[] = [
                'pattern' => $line,
                'negated' => $negated,
                'directory' => $directory,
                'anchored' => $anchored,
            ];
        }

        return $patterns;
    }

    /** @param list<array{pattern:string, negated:bool, directory:bool, anchored:bool}> $patterns */
    private function isGitignoreIgnored(string $relativePath, bool $isDirectory, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        $ignored = false;
        foreach ($patterns as $pattern) {
            if (! $this->matchesGitignorePattern($relativePath, $isDirectory, $pattern)) {
                continue;
            }
            $ignored = ! $pattern['negated'];
        }

        return $ignored;
    }

    /** @param array{pattern:string, negated:bool, directory:bool, anchored:bool} $pattern */
    private function matchesGitignorePattern(string $relativePath, bool $isDirectory, array $pattern): bool
    {
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

    private function relativePath(string $filePath, string $basePath): string
    {
        $windowsStyle = DIRECTORY_SEPARATOR === '\\'
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $filePath) === 1
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $basePath) === 1
            || str_starts_with($filePath, '\\\\')
            || str_starts_with($basePath, '\\\\');

        if ($windowsStyle) {
            $filePath = str_replace('\\', '/', $filePath);
            $basePath = str_replace('\\', '/', $basePath);
        }

        $basePath = rtrim($basePath, '/');
        $prefix = $basePath.'/';
        $matchesPrefix = $windowsStyle
            ? strncasecmp($filePath, $prefix, strlen($prefix)) === 0
            : str_starts_with($filePath, $prefix);

        return $matchesPrefix ? substr($filePath, strlen($prefix)) : $filePath;
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (str_starts_with($pattern, './')) {
            return substr($pattern, 2);
        }

        return $pattern;
    }

    private function globToRegex(string $pattern): string
    {
        // Use '#' as delimiter so '/' can appear unescaped inside character classes
        $regex = preg_quote($pattern, '#');

        $regex = str_replace('\*\*/', '__DOUBLE_STAR_SLASH__', $regex);
        $regex = str_replace('\*\*', '__DOUBLE_STAR__', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('\?', '[^/]', $regex);
        $regex = str_replace('__DOUBLE_STAR_SLASH__', '(?:.*/)?', $regex);
        $regex = str_replace('__DOUBLE_STAR__', '.*', $regex);

        return '#^' . $regex . '$#';
    }

    /**
     * @return array<int, string>
     */
    private function expandBracePatterns(string $pattern): array
    {
        $expanded = $this->expandBracePatternsBounded($pattern, self::MAX_BRACE_EXPANSIONS);
        if (count($expanded) > self::MAX_BRACE_EXPANSIONS) {
            throw new \LengthException(
                'Glob brace expansion is too broad; narrow the pattern to fewer alternatives.',
            );
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<string>
     */
    private function expandBracePatternsBounded(string $pattern, int $limit): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return [$pattern];
        }

        $brace = $matches[0][0];
        $braceOffset = $matches[0][1];
        $options = explode(',', $matches[1][0]);
        $prefix = substr($pattern, 0, $braceOffset);
        $suffix = substr($pattern, $braceOffset + strlen($brace));

        $expanded = [];
        foreach ($options as $option) {
            foreach ($this->expandBracePatternsBounded($prefix . $option . $suffix, $limit) as $variant) {
                $expanded[] = $variant;
                if (count($expanded) > $limit) {
                    return $expanded;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Searching for ' . ($input['pattern'] ?? 'files');
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => true, 'isRead' => false, 'isList' => true];
    }
}
