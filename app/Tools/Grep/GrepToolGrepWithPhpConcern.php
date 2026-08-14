<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Filesystem\GitignoreMatcher;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait GrepToolGrepWithPhpConcern
{

    private function grepWithPhp(
        string $pattern, string $path, string $outputMode, ?string $glob,
        bool $caseInsensitive, int $afterLines, int $beforeLines, int $headLimit,
        int $offset = 0, ?string $type = null, bool $multiline = false, ?callable $shouldAbort = null,
    ): ToolResult {
        $deadline = microtime(true) + self::PHP_FALLBACK_TIMEOUT_SECONDS;
        $stopReason = $this->searchStopReason($shouldAbort, $deadline);
        if ($stopReason !== null) {
            return $this->searchStopResult($stopReason);
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
            try {
                $gitignoreMatcher = GitignoreMatcher::forSearchRoot($path, 'Grep');
            } catch (\LengthException $e) {
                return ToolResult::error($e->getMessage());
            }
            try {
                $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
                $filter = new \RecursiveCallbackFilterIterator(
                    $directory,
                    function (\SplFileInfo $current) use ($path, $gitignoreMatcher): bool {
                        $relativePath = str_replace(
                            '\\',
                            '/',
                            ltrim(str_replace($path, '', $current->getPathname()), '/\\'),
                        );

                        if ($this->isIgnoredPath($relativePath)) {
                            return false;
                        }

                        if ($gitignoreMatcher->isIgnored($current->getPathname(), $current->isDir())) {
                            return $current->isDir()
                                && $gitignoreMatcher->shouldDescendForNegation($current->getPathname());
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
                    $stopReason = $this->searchStopReason($shouldAbort, $deadline);
                    if ($stopReason !== null) {
                        return $this->searchStopResult($stopReason);
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
            } catch (\UnexpectedValueException|\LengthException $e) {
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
        $capturedOutputBytes = 0;

        foreach ($files as $file) {
            $stopReason = $this->searchStopReason($shouldAbort, $deadline);
            if ($stopReason !== null) {
                return $this->searchStopResult($stopReason);
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
                    $stopReason = $this->searchStopReason($shouldAbort, $deadline);
                    if ($stopReason !== null) {
                        fclose($handle);

                        return $this->searchStopResult($stopReason);
                    }

                    if (preg_match($regex, $line)) {
                        $matched = true;
                        break;
                    }
                }
                fclose($handle);
                if ($matched) {
                    $limitReached = $this->addLimitedEntry(
                        $relativePath,
                        $fileMatches,
                        $seenEntries,
                        $offset,
                        $headLimit,
                        $capturedOutputBytes,
                    );
                    if ($limitReached === null) {
                        return $this->fallbackOutputLimitError($path);
                    }
                    if ($limitReached) {
                        break;
                    }
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
                    $stopReason = $this->searchStopReason($shouldAbort, $deadline);
                    if ($stopReason !== null) {
                        fclose($handle);

                        return $this->searchStopResult($stopReason);
                    }

                    if (preg_match($regex, $line)) {
                        $count++;
                    }
                }
                fclose($handle);
                if ($count > 0) {
                    $limitReached = $this->addLimitedEntry(
                        "{$relativePath}:{$count}",
                        $fileMatches,
                        $seenEntries,
                        $offset,
                        $headLimit,
                        $capturedOutputBytes,
                    );
                    if ($limitReached === null) {
                        return $this->fallbackOutputLimitError($path);
                    }
                    if ($limitReached) {
                        break;
                    }
                }
                continue;
            }

            $beforeBuffer = [];
            $beforeBufferBytes = 0;
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
                $stopReason = $this->searchStopReason($shouldAbort, $deadline);
                if ($stopReason !== null) {
                    fclose($handle);

                    return $this->searchStopResult($stopReason);
                }

                $lineNumber++;
                $matched = preg_match($regex, $line) === 1;
                if ($matched) {
                    foreach ($beforeBuffer as [$ctxNumber, $ctxLine]) {
                        if (isset($emitted[$ctxNumber])) {
                            continue;
                        }
                        $emitted[$ctxNumber] = true;
                        $limitReached = $this->addLimitedEntry(
                            $relativePath.'-'.$ctxNumber.'-'.rtrim($ctxLine),
                            $results,
                            $seenEntries,
                            $offset,
                            $headLimit,
                            $capturedOutputBytes,
                        );
                        if ($limitReached === null) {
                            fclose($handle);

                            return $this->fallbackOutputLimitError($path);
                        }
                        if ($limitReached) {
                            fclose($handle);
                            $handle = null;
                            break 3;
                        }
                    }
                    if (! isset($emitted[$lineNumber])) {
                        $emitted[$lineNumber] = true;
                        $limitReached = $this->addLimitedEntry(
                            $relativePath.':'.$lineNumber.':'.rtrim($line),
                            $results,
                            $seenEntries,
                            $offset,
                            $headLimit,
                            $capturedOutputBytes,
                        );
                        if ($limitReached === null) {
                            fclose($handle);

                            return $this->fallbackOutputLimitError($path);
                        }
                        if ($limitReached) {
                            fclose($handle);
                            $handle = null;
                            break 2;
                        }
                    }
                    $afterRemaining = max($afterRemaining, $afterLines);
                } elseif ($afterRemaining > 0 && ! isset($emitted[$lineNumber])) {
                    $emitted[$lineNumber] = true;
                    $limitReached = $this->addLimitedEntry(
                        $relativePath.'-'.$lineNumber.'-'.rtrim($line),
                        $results,
                        $seenEntries,
                        $offset,
                        $headLimit,
                        $capturedOutputBytes,
                    );
                    if ($limitReached === null) {
                        fclose($handle);

                        return $this->fallbackOutputLimitError($path);
                    }
                    if ($limitReached) {
                        fclose($handle);
                        $handle = null;
                        break 2;
                    }
                    $afterRemaining--;
                }

                $beforeBuffer[] = [$lineNumber, $line];
                $beforeBufferBytes += strlen($line);
                if (count($beforeBuffer) > $beforeLines) {
                    [, $discardedLine] = array_shift($beforeBuffer);
                    $beforeBufferBytes -= strlen($discardedLine);
                }
                if ($beforeBufferBytes > self::PHP_FALLBACK_OUTPUT_MAX) {
                    fclose($handle);

                    return $this->fallbackOutputLimitError($path);
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

    private function searchStopReason(?callable $shouldAbort, float $deadline): ?string
    {
        if ($shouldAbort !== null && $shouldAbort()) {
            return 'aborted';
        }
        if (microtime(true) >= $deadline) {
            return 'timeout';
        }

        return null;
    }

    private function searchBoundsError(
        mixed $pattern,
        mixed $glob,
        mixed $afterLines,
        mixed $beforeLines,
        mixed $headLimit,
        mixed $offset,
    ): ?string {
        if (! is_string($pattern) || strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return 'pattern is too long (max '.self::MAX_PATTERN_BYTES.' bytes).';
        }
        if ($glob !== null && (! is_string($glob) || strlen($glob) > self::MAX_GLOB_BYTES)) {
            return 'glob is too long (max '.self::MAX_GLOB_BYTES.' bytes).';
        }
        foreach ([
            '-A' => $afterLines,
            '-B' => $beforeLines,
        ] as $name => $value) {
            if (! is_int($value) || $value < 0 || $value > self::MAX_CONTEXT_LINES) {
                return "{$name} must be between 0 and ".self::MAX_CONTEXT_LINES.'.';
            }
        }
        if (! is_int($headLimit) || $headLimit < 0 || $headLimit > self::MAX_HEAD_LIMIT) {
            return 'head_limit must be between 0 and '.self::MAX_HEAD_LIMIT.'.';
        }
        if (! is_int($offset) || $offset < 0 || $offset > self::MAX_OFFSET) {
            return 'offset must be between 0 and '.self::MAX_OFFSET.'.';
        }

        return null;
    }

    private function searchStopResult(string $reason): ToolResult
    {
        return $reason === 'aborted'
            ? ToolResult::aborted('Grep search aborted.')
            : ToolResult::error(
                'Grep search timed out after '.self::PHP_FALLBACK_TIMEOUT_SECONDS.' seconds. '
                .'Narrow the path or pattern to continue.',
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

    private function fallbackOutputLimitError(string $path): ToolResult
    {
        return ToolResult::error(
            'Grep fallback stopped after retaining more than '.self::PHP_FALLBACK_OUTPUT_MAX
            .' bytes of context or output in '.$path.'. Narrow head/context or install ripgrep.',
        );
    }
}
