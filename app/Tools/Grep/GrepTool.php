<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class GrepTool extends BaseTool
{
    public function name(): string
    {
        return 'Grep';
    }

    public function description(): string
    {
        return <<<DESC
A powerful search tool built on ripgrep.

Usage:
- ALWAYS use Grep for search tasks. NEVER invoke `grep` or `rg` as a Bash command.
- Supports full regex syntax (e.g., "log.*Error", "function\\s+\\w+")
- Filter files with glob parameter (e.g., "*.js", "**/*.tsx") or type parameter (e.g., "php")
- Output modes: "content" shows matching lines, "files_with_matches" shows file paths, "count" shows counts
- Use `-A`, `-B`, `-C` parameters for context lines
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The regular expression pattern to search for',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'File or directory to search in. Defaults to current working directory.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Glob pattern to filter files (e.g., "*.php")',
                ],
                'output_mode' => [
                    'type' => 'string',
                    'enum' => ['content', 'files_with_matches', 'count'],
                    'description' => 'Output mode (default: files_with_matches)',
                ],
                '-A' => [
                    'type' => 'integer',
                    'description' => 'Number of lines after match',
                ],
                '-B' => [
                    'type' => 'integer',
                    'description' => 'Number of lines before match',
                ],
                '-C' => [
                    'type' => 'integer',
                    'description' => 'Context lines before and after match',
                ],
                '-i' => [
                    'type' => 'boolean',
                    'description' => 'Case insensitive search',
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'description' => 'Limit output to first N entries',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'File type to search (e.g., "php", "js", "py", "go", "rust"). Maps to rg --type.',
                ],
                'multiline' => [
                    'type' => 'boolean',
                    'description' => 'Enable multiline mode for cross-line patterns (rg -U). Default: false.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Skip first N entries before applying head_limit. Default: 0.',
                ],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
            'glob' => 'nullable|string',
            'output_mode' => 'nullable|string|in:content,files_with_matches,count',
            '-A' => 'nullable|integer|min:0',
            '-B' => 'nullable|integer|min:0',
            '-C' => 'nullable|integer|min:0',
            '-i' => 'nullable|boolean',
            'head_limit' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'multiline' => 'nullable|boolean',
            'offset' => 'nullable|integer|min:0',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = $input['pattern'];
        $path = $this->resolvePath(
            $input['path'] ?? $context->workingDirectory,
            $context->workingDirectory,
        );
        $outputMode = $input['output_mode'] ?? 'files_with_matches';
        $glob = $input['glob'] ?? null;
        $type = $input['type'] ?? null;
        $caseInsensitive = $input['-i'] ?? false;
        $multiline = $input['multiline'] ?? false;
        $contextLines = $input['-C'] ?? null;
        $afterLines = $contextLines ?? $input['-A'] ?? 0;
        $beforeLines = $contextLines ?? $input['-B'] ?? 0;
        $headLimit = $input['head_limit'] ?? 250;
        $offset = $input['offset'] ?? 0;

        // Try ripgrep first, fallback to PHP implementation
        if ($this->hasRipgrep()) {
            return $this->grepWithRipgrep(
                $pattern, $path, $outputMode, $glob, $type,
                $caseInsensitive, $multiline, $afterLines, $beforeLines, $headLimit, $offset
            );
        }

        return $this->grepWithPhp(
            $pattern, $path, $outputMode, $glob,
            $caseInsensitive, $afterLines, $beforeLines, $headLimit,
            $offset, $type, $multiline,
        );
    }

    private function hasRipgrep(): bool
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? 'where rg 2>NUL'
            : 'command -v rg 2>/dev/null';
        $result = exec($command);

        return ! empty($result);
    }

    private function grepWithRipgrep(
        string $pattern, string $path, string $outputMode, ?string $glob, ?string $type,
        bool $caseInsensitive, bool $multiline, int $afterLines, int $beforeLines, int $headLimit, int $offset = 0
    ): ToolResult {
        if ($headLimit === 0) {
            return ToolResult::success("No matches found for pattern: {$pattern}");
        }

        $cmd = [
            'rg',
            '--no-heading',
            '--color=never',
            '--with-filename',
            '--sort=path',
            '--no-context-separator',
        ];

        if ($caseInsensitive) {
            $cmd[] = '-i';
        }

        if ($multiline) {
            $cmd[] = '-U';
            $cmd[] = '--multiline-dotall';
        }

        if ($outputMode === 'count') {
            $cmd[] = '--count';
        } elseif ($outputMode === 'files_with_matches') {
            $cmd[] = '-l';
            $cmd[] = '--max-count=1';
        } else {
            $cmd[] = '--line-number';
            if ($afterLines > 0) $cmd[] = '--after-context=' . $afterLines;
            if ($beforeLines > 0) $cmd[] = '--before-context=' . $beforeLines;
            // Bound per-file work without confusing it for the global output
            // limit applied below. No file can contribute more than the first
            // offset + head_limit matches needed for that global prefix.
            $maxCount = $offset > PHP_INT_MAX - $headLimit
                ? PHP_INT_MAX
                : $headLimit + $offset;
            $cmd[] = '--max-count='.$maxCount;
        }

        if ($glob) {
            $cmd[] = '--glob=' . escapeshellarg($glob);
        }

        if ($type) {
            $cmd[] = '--type=' . escapeshellarg($type);
        }

        $cmd[] = '--';
        $cmd[] = escapeshellarg($pattern);
        $cmd[] = escapeshellarg($path);

        $command = implode(' ', $cmd);
        exec($command . ' 2>&1', $output, $exitCode);

        // rg returns 1 when no matches found
        if ($exitCode === 1) {
            return ToolResult::success("No matches found for pattern: {$pattern}");
        }

        if ($exitCode > 1) {
            return ToolResult::error("ripgrep error: " . implode("\n", $output));
        }

        // head_limit and offset are global output-entry limits. ripgrep's
        // --max-count is per file, so applying it in the command produces
        // different results from the PHP fallback on multi-file searches.
        $output = array_map(
            fn (string $line): string => $this->normalizeOutputPath($line, $path),
            $output,
        );
        $output = array_slice($output, $offset, $headLimit);

        $result = implode("\n", $output);
        if (empty($result)) {
            return ToolResult::success($this->noMatchesMessage($pattern));
        }

        return ToolResult::success($result);
    }

    private function grepWithPhp(
        string $pattern, string $path, string $outputMode, ?string $glob,
        bool $caseInsensitive, int $afterLines, int $beforeLines, int $headLimit,
        int $offset = 0, ?string $type = null, bool $multiline = false,
    ): ToolResult {
        if ($type !== null && $type !== '') {
            return ToolResult::error(
                'The type filter requires ripgrep; install rg or use the glob parameter.',
            );
        }
        if ($multiline) {
            return ToolResult::error(
                'Multiline search requires ripgrep; install rg or disable multiline.',
            );
        }

        $flags = $caseInsensitive ? 'i' : '';
        // Escape the `/` delimiter so patterns like `app/Services` don't break the regex.
        $safePattern = str_replace('/', '\/', $pattern);
        $regex = '/' . $safePattern . '/' . $flags;

        // Validate regex without emitting a PHP warning (PHPUnit 11 captures them)
        set_error_handler(fn() => true);
        $testResult = preg_match($regex, '');
        restore_error_handler();
        if ($testResult === false) {
            return ToolResult::error("Invalid regex pattern: {$pattern}");
        }

        if (is_file($path)) {
            $files = [$path];
        } elseif (is_dir($path)) {
            $files = [];
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    // Match ripgrep's default and never dereference a symlink
                    // discovered underneath an otherwise permitted search
                    // root. Its target has not gone through the permission or
                    // sensitive-path checks applied to the observable input.
                    if (! $file->isLink() && $file->isFile()) {
                        if ($glob) {
                            $relPath = str_replace(
                                '\\',
                                '/',
                                ltrim(str_replace($path, '', $file->getPathname()), '/\\'),
                            );
                            if (!fnmatch($glob, $relPath) && !fnmatch($glob, $file->getFilename())) {
                                continue;
                            }
                        }
                        $files[] = $file->getPathname();
                    }
                }
                sort($files, SORT_STRING);
            } catch (\UnexpectedValueException $e) {
                return ToolResult::error("Unable to search path {$path}: {$e->getMessage()}");
            }
        } else {
            return ToolResult::error("Search path does not exist: {$path}");
        }

        $results = [];
        $fileMatches = [];

        foreach ($files as $file) {
            $lines = @file($file);
            if ($lines === false) continue;

            $totalLines = count($lines);
            $matchingLines = [];
            foreach ($lines as $num => $line) {
                if (preg_match($regex, $line)) {
                    $matchingLines[$num] = true;
                }
            }
            if ($matchingLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file, $path);
            if ($outputMode === 'files_with_matches') {
                $fileMatches[$relativePath] = true;
                continue;
            }
            if ($outputMode === 'count') {
                $fileMatches[$relativePath] = count($matchingLines);
                continue;
            }

            $visibleLines = [];
            foreach (array_keys($matchingLines) as $num) {
                $start = max(0, $num - $beforeLines);
                $end = min($totalLines - 1, $num + $afterLines);
                for ($lineIndex = $start; $lineIndex <= $end; $lineIndex++) {
                    $visibleLines[$lineIndex] = true;
                }
            }
            ksort($visibleLines);
            foreach (array_keys($visibleLines) as $lineIndex) {
                $separator = isset($matchingLines[$lineIndex]) ? ':' : '-';
                $results[] = $relativePath.$separator.($lineIndex + 1).$separator
                    .rtrim($lines[$lineIndex]);
            }
        }

        if ($outputMode === 'files_with_matches') {
            $entries = array_keys($fileMatches);
        } elseif ($outputMode === 'count') {
            $entries = array_map(
                fn($f, $c) => "{$f}:{$c}", array_keys($fileMatches), array_values($fileMatches)
            );
        } else {
            $entries = $results;
        }

        $entries = array_slice($entries, $offset, $headLimit);

        return ToolResult::success(
            $entries === [] ? $this->noMatchesMessage($pattern) : implode("\n", $entries),
        );
    }

    private function relativePath(string $file, string $searchPath): string
    {
        if (is_file($searchPath)) {
            return basename($file);
        }

        $prefix = rtrim($searchPath, '/\\').DIRECTORY_SEPARATOR;

        $relative = str_starts_with($file, $prefix)
            ? substr($file, strlen($prefix))
            : $file;

        return str_replace('\\', '/', $relative);
    }

    private function normalizeOutputPath(string $line, string $searchPath): string
    {
        if (is_file($searchPath)) {
            return str_starts_with($line, $searchPath)
                ? basename($searchPath).substr($line, strlen($searchPath))
                : $line;
        }

        $prefixes = array_unique([
            rtrim($searchPath, '/\\').'/',
            rtrim($searchPath, '/\\').'\\',
        ]);
        foreach ($prefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return substr($line, strlen($prefix));
            }
        }

        return $line;
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
