<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait FileEditToolNameConcern
{
    public function __construct(
        private readonly ?\HaoCode\Services\FileHistory\FileHistoryManager $fileHistory = null,
    ) {}


    public function name(): string
    {
        return 'Edit';
    }

    public function description(): string
    {
        return <<<DESC
Performs exact string replacements in files.

Usage:
- You must use the `Read` tool at least once in the conversation before editing.
- The edit will FAIL if `old_string` is not unique in the file.
- Use `replace_all` for replacing and renaming strings across the file.
- Only use emojis if the user explicitly requests it.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The file path to modify. Relative paths are resolved against the current working directory.',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'The text to replace',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'The text to replace it with (must be different from old_string)',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences of old_string (default false)',
                    'default' => false,
                ],
            ],
            'required' => ['file_path', 'old_string', 'new_string'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $oldString = $input['old_string'];
        $newString = $input['new_string'];
        $replaceAll = $input['replace_all'] ?? false;

        if (!file_exists($filePath)) {
            return ToolResult::error("File does not exist: {$filePath}");
        }

        if (($revisionError = $context->fileRevisionError($filePath)) !== null) {
            return ToolResult::error(
                $revisionError.' '.
                "Next step: call Read on this exact path, then retry Edit."
            );
        }
        $expectedRevision = $context->getFileRevision($filePath);

        if (!is_writable($filePath)) {
            return ToolResult::error("File is not writable: {$filePath}");
        }

        if (is_dir($filePath)) {
            return ToolResult::error("Path is a directory, not a file: {$filePath}");
        }

        $size = @filesize($filePath);
        if ($size === false) {
            return ToolResult::error("Unable to determine file size safely: {$filePath}");
        }
        if ($size > self::MAX_IN_MEMORY_EDIT_BYTES) {
            return $this->editLargeFile(
                $filePath,
                $oldString,
                $newString,
                (bool) $replaceAll,
                $expectedRevision,
                $context,
            );
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        if ($this->looksBinary($content, $filePath)) {
            return ToolResult::error(
                "Refusing to Edit binary file: {$filePath}. "
                .'Use a dedicated binary-aware workflow instead of text replacement.',
            );
        }

        if ($oldString === $newString) {
            return ToolResult::error("old_string and new_string are identical. No changes needed.");
        }

        // Detect line ending style for preservation
        $lineEnding = $this->detectLineEnding($content);

        // Try finding old_string with curly quote normalization fallback
        $actualOldString = QuoteNormalizer::findActualString($content, $oldString);
        if ($actualOldString === null) {
            return ToolResult::error("old_string not found in file: {$filePath}");
        }

        // Preserve file's curly quote style in replacement
        $actualNewString = QuoteNormalizer::preserveQuoteStyle($oldString, $actualOldString, $newString);

        // Count occurrences (using the actual string from the file)
        $count = substr_count($content, $actualOldString);
        if ($count === 0) {
            // Fallback: count via normalization
            $count = QuoteNormalizer::countOccurrences($content, $oldString);
        }

        if (!$replaceAll && $count > 1) {
            return ToolResult::error(
                "old_string is not unique in the file (found {$count} occurrences). " .
                "Either provide a larger string with more surrounding context to make it unique, " .
                "or use `replace_all: true` to change every instance."
            );
        }

        // Apply the edit
        $originalContent = $content;
        if ($replaceAll) {
            $newContent = str_replace($actualOldString, $actualNewString, $content);
        } else {
            $pos = strpos($content, $actualOldString);
            if ($pos !== false) {
                $newContent = substr($content, 0, $pos) . $actualNewString . substr($content, $pos + strlen($actualOldString));
            } else {
                $newContent = $content;
            }
        }

        // Preserve line ending style
        if ($lineEnding !== "\n") {
            $newContent = $this->normalizeLineEndings($newContent, $lineEnding);
        }

        try {
            (new AtomicFileWriter())->write(
                $filePath,
                $newContent,
                $expectedRevision,
                $this->fileHistory !== null
                    ? fn (string $target) => $this->fileHistory
                        ->forSession($context->sessionId)
                        ->recordBefore($target)
                    : null,
            );
        } catch (FileConflictException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to write file: {$filePath}. {$e->getMessage()}");
        }
        $context->recordFileRead($filePath, $newContent, 1, null, false);

        // Generate diff output
        $changeSummary = DiffGenerator::changeSummary($originalContent, $newContent);
        $output = "Successfully edited {$filePath} ({$changeSummary})";

        // Append snippet diff for visibility
        $snippet = $this->generateSnippetDiff($oldString, $newString, $replaceAll, $count);
        if ($snippet !== '') {
            $output .= "\n" . $snippet;
        }

        // Try git diff
        $gitDiff = DiffGenerator::gitDiff($filePath, $context->isAborted(...));
        if ($gitDiff !== '') {
            $output .= "\n\nGit diff:\n" . $gitDiff;
        }

        return ToolResult::success($output);
    }

    /**
     * Edit a large text file without retaining the original and replacement
     * file in PHP memory at the same time.
     */
    private function editLargeFile(
        string $filePath,
        string $oldString,
        string $newString,
        bool $replaceAll,
        ?FileRevision $expectedRevision,
        ToolUseContext $context,
    ): ToolResult {
        if ($oldString === $newString) {
            return ToolResult::error("old_string and new_string are identical. No changes needed.");
        }
        if ($oldString === '') {
            return ToolResult::error('old_string must not be empty for a large-file edit.');
        }
        if ($context->isAborted()) {
            return ToolResult::aborted();
        }

        $prefix = @file_get_contents($filePath, false, null, 0, 8192);
        if (! is_string($prefix)) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }
        if ($this->looksBinary($prefix, $filePath)) {
            return ToolResult::error(
                "Refusing to Edit binary file: {$filePath}. "
                .'Use a dedicated binary-aware workflow instead of text replacement.',
            );
        }

        $scan = $this->countLargeOccurrences($filePath, $oldString, $replaceAll, $context->isAborted(...));
        if ($scan['aborted']) {
            return ToolResult::aborted();
        }
        if ($scan['error'] !== null) {
            return ToolResult::error($scan['error']);
        }
        $count = $scan['count'];
        if ($count === 0) {
            return ToolResult::error("old_string not found in file: {$filePath}");
        }
        if (! $replaceAll && $count > 1) {
            return ToolResult::error(
                "old_string is not unique in the file (found {$count} occurrences). "
                .'Either provide a larger string with more surrounding context to make it unique, '
                .'or use `replace_all: true` to change every instance.',
            );
        }

        $lineEnding = $this->detectLineEnding($prefix);
        $actualNewString = $lineEnding !== "\n"
            ? $this->normalizeLineEndings($newString, $lineEnding)
            : $newString;

        try {
            (new AtomicFileWriter())->writeFromProducer(
                $filePath,
                function ($sourceHandle, $tempHandle, string $temporary) use (
                    $oldString,
                    $actualNewString,
                    $context,
                ): void {
                    $this->streamReplacement(
                        $sourceHandle,
                        $tempHandle,
                        $temporary,
                        $oldString,
                        $actualNewString,
                        $context->isAborted(...),
                    );
                },
                $expectedRevision,
                $this->fileHistory !== null
                    ? fn (string $target) => $this->fileHistory
                        ->forSession($context->sessionId)
                        ->recordBefore($target)
                    : null,
            );
        } catch (FileConflictException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\Throwable $e) {
            if ($context->isAborted()) {
                return ToolResult::aborted();
            }

            return ToolResult::error("Failed to write file: {$filePath}. {$e->getMessage()}");
        }

        $revision = FileRevision::capture($filePath, true);
        if ($revision !== null) {
            $context->recordObservedFileRevision($revision);
        }

        $output = "Successfully edited {$filePath} (large file; change summary omitted above "
            .self::MAX_IN_MEMORY_EDIT_BYTES.' bytes)';
        $snippet = $this->generateSnippetDiff($oldString, $newString, $replaceAll, $count);
        if ($snippet !== '') {
            $output .= "\n".$snippet;
        }

        $gitDiff = DiffGenerator::gitDiff($filePath, $context->isAborted(...));
        if ($gitDiff !== '') {
            $output .= "\n\nGit diff:\n".$gitDiff;
        }

        return ToolResult::success($output);
    }

    /**
     * @return array{count:int, error:?string, aborted:bool}
     */
    private function countLargeOccurrences(
        string $filePath,
        string $needle,
        bool $replaceAll,
        ?callable $shouldAbort = null,
    ): array {
        $handle = @fopen($filePath, 'rb');
        if (! is_resource($handle)) {
            return ['count' => 0, 'error' => "Failed to read file: {$filePath}", 'aborted' => false];
        }

        $needleLength = strlen($needle);
        $pending = '';
        $count = 0;
        try {
            while (! feof($handle)) {
                if ($shouldAbort !== null && $shouldAbort()) {
                    return ['count' => 0, 'error' => null, 'aborted' => true];
                }
                $chunk = fread($handle, self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    return ['count' => 0, 'error' => "Failed to read file: {$filePath}", 'aborted' => false];
                }
                if ($chunk === '') {
                    continue;
                }
                if (str_contains($chunk, "\0")) {
                    return [
                        'count' => 0,
                        'error' => "Refusing to Edit binary file: {$filePath}. "
                            .'Use a dedicated binary-aware workflow instead of text replacement.',
                        'aborted' => false,
                    ];
                }

                $buffer = $pending.$chunk;
                $scanLength = max(0, strlen($buffer) - $needleLength + 1);
                $offset = 0;
                while (($position = strpos($buffer, $needle, $offset)) !== false) {
                    if ($position >= $scanLength) {
                        break;
                    }
                    $count++;
                    if (! $replaceAll && $count > 1) {
                        return ['count' => $count, 'error' => null, 'aborted' => false];
                    }
                    $offset = $position + $needleLength;
                }
                $pending = substr($buffer, $scanLength);
            }

            $offset = 0;
            while (($position = strpos($pending, $needle, $offset)) !== false) {
                $count++;
                if (! $replaceAll && $count > 1) {
                    break;
                }
                $offset = $position + $needleLength;
            }
        } finally {
            fclose($handle);
        }

        return ['count' => $count, 'error' => null, 'aborted' => false];
    }

    /** @param resource $sourceHandle @param resource $tempHandle */
    private function streamReplacement(
        $sourceHandle,
        $tempHandle,
        string $temporary,
        string $needle,
        string $replacement,
        ?callable $shouldAbort = null,
    ): void {
        $needleLength = strlen($needle);
        $pending = '';
        while (! feof($sourceHandle)) {
            if ($shouldAbort !== null && $shouldAbort()) {
                throw new \RuntimeException('Edit interrupted by user.');
            }
            $chunk = fread($sourceHandle, self::STREAM_CHUNK_BYTES);
            if ($chunk === false) {
                throw new \RuntimeException('Failed to read source file.');
            }
            if ($chunk === '') {
                continue;
            }

            $buffer = $pending.$chunk;
            $scanLength = max(0, strlen($buffer) - $needleLength + 1);
            $consumed = $this->writeReplacementPrefix(
                $tempHandle,
                $temporary,
                $buffer,
                $scanLength,
                $needle,
                $replacement,
            );
            $pending = substr($buffer, $consumed);
        }

        if ($pending !== '') {
            $this->writeReplacementPrefix(
                $tempHandle,
                $temporary,
                $pending,
                strlen($pending),
                $needle,
                $replacement,
            );
        }
    }

    /** @param resource $handle */
    private function writeReplacementPrefix(
        $handle,
        string $path,
        string $buffer,
        int $scanLength,
        string $needle,
        string $replacement,
    ): int {
        $cursor = 0;
        $needleLength = strlen($needle);
        while (($position = strpos($buffer, $needle, $cursor)) !== false && $position < $scanLength) {
            $this->writeStreamBytes($handle, substr($buffer, $cursor, $position - $cursor), $path);
            $this->writeStreamBytes($handle, $replacement, $path);
            $cursor = $position + $needleLength;
        }
        if ($cursor < $scanLength) {
            $this->writeStreamBytes($handle, substr($buffer, $cursor, $scanLength - $cursor), $path);
        }

        return max($scanLength, $cursor);
    }

    /** @param resource $handle */
    private function writeStreamBytes($handle, string $content, string $path): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException("Failed to write temporary file: {$path}");
            }
            $offset += $written;
        }
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
