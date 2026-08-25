<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Filesystem\GitignoreMatcher;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait GrepToolNameConcern
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
                    'minimum' => 0,
                    'description' => 'Number of lines after match',
                ],
                '-B' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Number of lines before match',
                ],
                '-C' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Context lines before and after match',
                ],
                '-i' => [
                    'type' => 'boolean',
                    'description' => 'Case insensitive search',
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'minimum' => 0,
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
                    'minimum' => 0,
                    'description' => 'Skip first N entries before applying head_limit. Default: 0.',
                ],
            ],
            'required' => ['pattern'],
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

        $boundsError = $this->searchBoundsError(
            $pattern,
            $glob,
            $afterLines,
            $beforeLines,
            $headLimit,
            $offset,
        );
        if ($boundsError !== null) {
            return ToolResult::error($boundsError);
        }

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

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        if (! is_string($input['pattern'] ?? null)) {
            return 'pattern must be a string.';
        }

        return $this->searchBoundsError(
            $input['pattern'],
            $input['glob'] ?? null,
            $input['-C'] ?? $input['-A'] ?? 0,
            $input['-C'] ?? $input['-B'] ?? 0,
            $input['head_limit'] ?? 250,
            $input['offset'] ?? 0,
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
}
