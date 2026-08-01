<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class GrepTool extends BaseTool
{
    private const MAX_VISITED_FILES = 20_000;
    private const MAX_LINE_BYTES = 1_000_000;
    private const RIPGREP_TIMEOUT_SECONDS = 10.0;
    private const RIPGREP_STDERR_MAX = 32_000;
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

        if ($context->isAborted()) {
            return ToolResult::aborted('Grep search aborted.');
        }

        // Try ripgrep first, fallback to PHP implementation
        if ($this->hasRipgrep()) {
            return $this->grepWithRipgrep(
                $pattern, $path, $outputMode, $glob, $type,
                $caseInsensitive, $multiline, $afterLines, $beforeLines, $headLimit, $offset,
                $context->isAborted(...),
            );
        }

        return $this->grepWithPhp(
            $pattern, $path, $outputMode, $glob,
            $caseInsensitive, $afterLines, $beforeLines, $headLimit,
            $offset, $type, $multiline, $context->isAborted(...),
        );
    }

    private function hasRipgrep(): bool
    {
        return $this->isExecutableOnPath('rg');
    }

    private function isExecutableOnPath(string $binary): bool
    {
        if ($binary === '' || str_contains($binary, "\0")) {
            return false;
        }

        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return false;
        }

        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $pathext = getenv('PATHEXT');
            $extensions = array_filter(explode(';', is_string($pathext) && $pathext !== '' ? $pathext : '.COM;.EXE;.BAT;.CMD'));
            array_unshift($extensions, '');
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary.$extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function grepWithRipgrep(
        string $pattern, string $path, string $outputMode, ?string $glob, ?string $type,
        bool $caseInsensitive, bool $multiline, int $afterLines, int $beforeLines, int $headLimit, int $offset = 0,
        ?callable $shouldAbort = null,
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

        foreach (self::IGNORED_DIRECTORIES as $ignored) {
            $cmd[] = '--glob';
            $cmd[] = '!'.$ignored;
            $cmd[] = '--glob';
            $cmd[] = '!'.$ignored.'/**';
            $cmd[] = '--glob';
            $cmd[] = '!**/'.$ignored;
            $cmd[] = '--glob';
            $cmd[] = '!**/'.$ignored.'/**';
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
            $cmd[] = '--glob';
            $cmd[] = $glob;
        }

        if ($type) {
            $cmd[] = '--type';
            $cmd[] = $type;
        }

        $cmd[] = '--';
        $cmd[] = $pattern;
        $cmd[] = $path;

        [$exitCode, $output, $stderr, $truncated] = $this->runRipgrepStreaming(
            $cmd,
            $path,
            $headLimit + $offset,
            $shouldAbort,
        );

        // rg returns 1 when no matches found
        if ($exitCode === 1) {
            return ToolResult::success("No matches found for pattern: {$pattern}");
        }

        if ($exitCode === 130) {
            return ToolResult::aborted('Grep search aborted.');
        }

        if ($exitCode > 1) {
            return ToolResult::error("ripgrep error: " . $stderr);
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

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: list<string>, 2: string, 3: bool}
     */
    private function runRipgrepStreaming(array $argv, string $path, int $lineLimit, ?callable $shouldAbort = null): array
    {
        $process = @proc_open($argv, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, is_dir($path) ? $path : dirname($path));
        if (! is_resource($process)) {
            return [2, [], 'Failed to start ripgrep.', false];
        }
        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $deadline = microtime(true) + self::RIPGREP_TIMEOUT_SECONDS;
        $stdoutBuffer = '';
        $stderr = '';
        $lines = [];
        $truncated = false;
        $exitCode = -1;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                ProcessSupervisor::terminateTree($pid, false);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [130, $lines, 'ripgrep aborted.', true];
            }

            $chunk = is_resource($pipes[1] ?? null) ? @fread($pipes[1], 65536) : false;
            if (is_string($chunk) && $chunk !== '') {
                $stdoutBuffer .= $chunk;
                while (($newline = strpos($stdoutBuffer, "\n")) !== false) {
                    $lines[] = substr($stdoutBuffer, 0, $newline);
                    $stdoutBuffer = substr($stdoutBuffer, $newline + 1);
                    if (count($lines) >= $lineLimit) {
                        $truncated = true;
                        ProcessSupervisor::terminateTree($pid, false);
                        break 2;
                    }
                }
            }

            $err = is_resource($pipes[2] ?? null) ? @fread($pipes[2], 8192) : false;
            if (is_string($err) && $err !== '') {
                $stderr .= $err;
                if (strlen($stderr) > self::RIPGREP_STDERR_MAX) {
                    $stderr = substr($stderr, -self::RIPGREP_STDERR_MAX);
                }
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = ($status['signaled'] ?? false)
                    ? 128 + (int) ($status['termsig'] ?? 0)
                    : (int) ($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) {
                ProcessSupervisor::terminateTree($pid, false);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index] ?? null)) {
                        fclose($pipes[$index]);
                    }
                }
                @proc_close($process);

                return [2, $lines, 'ripgrep timed out.', true];
            }

            usleep(10_000);
        }

        if ($stdoutBuffer !== '' && count($lines) < $lineLimit) {
            $lines[] = rtrim($stdoutBuffer, "\r\n");
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                fclose($pipes[$index]);
            }
        }
        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $truncated) {
            $exitCode = $closed;
        }

        return [$truncated ? 0 : $exitCode, $lines, trim($stderr), $truncated];
    }

    private function grepWithPhp(
        string $pattern, string $path, string $outputMode, ?string $glob,
        bool $caseInsensitive, int $afterLines, int $beforeLines, int $headLimit,
        int $offset = 0, ?string $type = null, bool $multiline = false, ?callable $shouldAbort = null,
    ): ToolResult {
        if ($shouldAbort !== null && $shouldAbort()) {
            return ToolResult::aborted('Grep search aborted.');
        }

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

        if ($headLimit === 0) {
            return ToolResult::success($this->noMatchesMessage($pattern));
        }

        if (is_file($path)) {
            $files = [$path];
        } elseif (is_dir($path)) {
            $files = [];
            $visitedEntries = 0;
            $truncatedByVisitLimit = false;
            $gitignorePatterns = $this->loadGitignorePatterns($path);
            try {
                $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
                $filter = new \RecursiveCallbackFilterIterator(
                    $directory,
                    function (\SplFileInfo $current) use ($path, $gitignorePatterns): bool {
                        $relativePath = str_replace(
                            '\\',
                            '/',
                            ltrim(str_replace($path, '', $current->getPathname()), '/\\'),
                        );

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
                        return ToolResult::aborted('Grep search aborted.');
                    }

                    $visitedEntries++;
                    if ($visitedEntries > self::MAX_VISITED_FILES) {
                        $truncatedByVisitLimit = true;
                        break;
                    }

                    if ($file->isDir()) {
                        continue;
                    }

                    $relPath = str_replace(
                        '\\',
                        '/',
                        ltrim(str_replace($path, '', $file->getPathname()), '/\\'),
                    );
                    // Match ripgrep's default and never dereference a symlink
                    // discovered underneath an otherwise permitted search
                    // root. Its target has not gone through the permission or
                    // sensitive-path checks applied to the observable input.
                    if (! $file->isLink() && $file->isFile()) {
                        if ($glob) {
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
            if ($truncatedByVisitLimit) {
                return ToolResult::error(
                    'Grep search stopped after visiting '.self::MAX_VISITED_FILES
                    .' filesystem entries. Narrow the path or pattern to continue.',
                );
            }
        } else {
            return ToolResult::error("Search path does not exist: {$path}");
        }

        $results = [];
        $fileMatches = [];
        $seenEntries = 0;

        foreach ($files as $file) {
            if ($shouldAbort !== null && $shouldAbort()) {
                return ToolResult::aborted('Grep search aborted.');
            }

            $handle = @fopen($file, 'rb');
            if (! is_resource($handle)) {
                continue;
            }

            $relativePath = $this->relativePath($file, $path);
            if ($outputMode === 'files_with_matches') {
                $matched = false;
                while (true) {
                    [$line, $oversized] = $this->readBoundedLine($handle);
                    if ($oversized) {
                        fclose($handle);

                        return $this->oversizedLineError($file);
                    }
                    if ($line === null) {
                        break;
                    }
                    if ($shouldAbort !== null && $shouldAbort()) {
                        fclose($handle);

                        return ToolResult::aborted('Grep search aborted.');
                    }

                    if (preg_match($regex, $line)) {
                        $matched = true;
                        break;
                    }
                }
                fclose($handle);
                if ($matched && $this->addLimitedEntry($relativePath, $fileMatches, $seenEntries, $offset, $headLimit)) {
                    break;
                }
                continue;
            }
            if ($outputMode === 'count') {
                $count = 0;
                while (true) {
                    [$line, $oversized] = $this->readBoundedLine($handle);
                    if ($oversized) {
                        fclose($handle);

                        return $this->oversizedLineError($file);
                    }
                    if ($line === null) {
                        break;
                    }
                    if ($shouldAbort !== null && $shouldAbort()) {
                        fclose($handle);

                        return ToolResult::aborted('Grep search aborted.');
                    }

                    if (preg_match($regex, $line)) {
                        $count++;
                    }
                }
                fclose($handle);
                if ($count > 0 && $this->addLimitedEntry("{$relativePath}:{$count}", $fileMatches, $seenEntries, $offset, $headLimit)) {
                    break;
                }
                continue;
            }

            $beforeBuffer = [];
            $emitted = [];
            $afterRemaining = 0;
            $lineNumber = 0;
            while (true) {
                [$line, $oversized] = $this->readBoundedLine($handle);
                if ($oversized) {
                    fclose($handle);

                    return $this->oversizedLineError($file);
                }
                if ($line === null) {
                    break;
                }
                if ($shouldAbort !== null && $shouldAbort()) {
                    fclose($handle);

                    return ToolResult::aborted('Grep search aborted.');
                }

                $lineNumber++;
                $matched = preg_match($regex, $line) === 1;
                if ($matched) {
                    foreach ($beforeBuffer as [$ctxNumber, $ctxLine]) {
                        if (isset($emitted[$ctxNumber])) {
                            continue;
                        }
                        $emitted[$ctxNumber] = true;
                        if ($this->addLimitedEntry($relativePath.'-'.$ctxNumber.'-'.rtrim($ctxLine), $results, $seenEntries, $offset, $headLimit)) {
                            fclose($handle);
                            $handle = null;
                            break 3;
                        }
                    }
                    if (! isset($emitted[$lineNumber])) {
                        $emitted[$lineNumber] = true;
                        if ($this->addLimitedEntry($relativePath.':'.$lineNumber.':'.rtrim($line), $results, $seenEntries, $offset, $headLimit)) {
                            fclose($handle);
                            $handle = null;
                            break 2;
                        }
                    }
                    $afterRemaining = max($afterRemaining, $afterLines);
                } elseif ($afterRemaining > 0 && ! isset($emitted[$lineNumber])) {
                    $emitted[$lineNumber] = true;
                    if ($this->addLimitedEntry($relativePath.'-'.$lineNumber.'-'.rtrim($line), $results, $seenEntries, $offset, $headLimit)) {
                        fclose($handle);
                        $handle = null;
                        break 2;
                    }
                    $afterRemaining--;
                }

                $beforeBuffer[] = [$lineNumber, $line];
                if (count($beforeBuffer) > $beforeLines) {
                    array_shift($beforeBuffer);
                }
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($outputMode === 'files_with_matches') {
            $entries = $fileMatches;
        } elseif ($outputMode === 'count') {
            $entries = $fileMatches;
        } else {
            $entries = $results;
        }

        return ToolResult::success(
            $entries === [] ? $this->noMatchesMessage($pattern) : implode("\n", $entries),
        );
    }

    /**
     * Read one logical line without allowing a pathological line to consume
     * unbounded memory in the PHP fallback.  Oversized lines are drained to
     * the next newline so callers can fail closed without leaving the handle
     * positioned in the middle of the same line.
     *
     * @param resource $handle
     * @return array{0:?string,1:bool} [line, oversized]
     */
    private function readBoundedLine($handle): array
    {
        // fgets() reads at most length - 1 bytes, so request one extra byte
        // beyond the cap to distinguish an exact-cap line from a longer one.
        $readLength = self::MAX_LINE_BYTES + 2;
        $line = @fgets($handle, $readLength);
        if ($line === false) {
            return [null, false];
        }

        if (strlen($line) <= self::MAX_LINE_BYTES || str_ends_with($line, "\n")) {
            return [$line, false];
        }

        while (($discard = @fgets($handle, $readLength)) !== false) {
            if (str_ends_with($discard, "\n")) {
                break;
            }
        }

        return [null, true];
    }

    private function oversizedLineError(string $file): ToolResult
    {
        return ToolResult::error(
            'Grep refused to scan a line larger than '.self::MAX_LINE_BYTES
            .' bytes in '.$file.'. Narrow the search or use ripgrep.',
        );
    }

    /** @param list<string> $entries */
    private function addLimitedEntry(string $entry, array &$entries, int &$seenEntries, int $offset, int $headLimit): bool
    {
        $seenEntries++;
        if ($seenEntries > $offset && count($entries) < $headLimit) {
            $entries[] = $entry;
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
