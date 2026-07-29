<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class FileEditTool extends BaseTool
{
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
        ], [
            'file_path' => 'required|string',
            'old_string' => 'required|string',
            'new_string' => 'required|string',
            'replace_all' => 'nullable|boolean',
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
                function (string $target) use ($context): void {
                    try {
                        \HaoCode\Support\Runtime\SdkRuntime::app(\HaoCode\Services\FileHistory\FileHistoryManager::class)
                            ->forSession($context->sessionId)
                            ->recordBefore($target);
                    } catch (\Throwable) {
                    }
                },
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
        $gitDiff = DiffGenerator::gitDiff($filePath);
        if ($gitDiff !== '') {
            $output .= "\n\nGit diff:\n" . $gitDiff;
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $normalizedPath = $this->normalizeFileReferencePath($input['file_path'], $context->workingDirectory);
            $input['file_path'] = $this->resolvePath($normalizedPath, $context->workingDirectory);
        }
        return $input;
    }

    public function maxResultSizeChars(): int
    {
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Editing ' . basename($input['file_path'] ?? 'file');
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $filePath = trim((string) ($input['file_path'] ?? ''));
        if ($filePath === '') {
            return 'file_path must not be empty.';
        }

        if ($this->isBareLineReference($filePath)) {
            return 'file_path must include an actual path, not only a line reference like ":12".';
        }

        $filePath = $this->normalizeFileReferencePath($filePath, $context->workingDirectory);
        $filePath = $this->resolvePath($filePath, $context->workingDirectory);

        // Block editing sensitive files
        $sensitivePatterns = [
            '/\.env$/',
            '/\.env\./',
            '/credentials\.json$/i',
            '/\.ssh\//',
            '/\.gnupg\//',
            '/id_rsa$/',
            '/id_ed25519$/',
            '/\.pem$/',
            '/\.key$/',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $filePath)) {
                return "Editing sensitive file detected: {$filePath}. Secret files should not be edited with the Edit tool.";
            }
        }

        // Warn about very large file edits
        if (file_exists($filePath)) {
            $size = filesize($filePath);
            if ($size > 1_000_000) { // 1MB
                return "File is very large (" . round($size / 1024 / 1024, 1) . " MB). Consider using more targeted edits.";
            }
        }

        return null;
    }

    /**
     * Detect the dominant line ending style in content.
     */
    private function detectLineEnding(string $content): string
    {
        $crlf = substr_count($content, "\r\n");
        $lf = substr_count($content, "\n") - $crlf;
        $cr = substr_count($content, "\r") - $crlf;

        if ($crlf > $lf && $crlf > $cr) {
            return "\r\n";
        }
        if ($cr > $lf) {
            return "\r";
        }

        return "\n";
    }

    /**
     * Normalize line endings in content to the target style.
     */
    private function normalizeLineEndings(string $content, string $target): string
    {
        // First normalize everything to \n, then convert to target
        $normalized = str_replace("\r\n", "\n", $content);
        $normalized = str_replace("\r", "\n", $normalized);

        if ($target === "\n") {
            return $normalized;
        }

        return str_replace("\n", $target, $normalized);
    }

    /**
     * Generate a compact snippet showing the replacement.
     */
    private function generateSnippetDiff(string $oldString, string $newString, bool $replaceAll, int $count): string
    {
        $oldPreview = $this->truncate($oldString, 200);
        $newPreview = $this->truncate($newString, 200);
        $suffix = $replaceAll && $count > 1 ? " ({$count} occurrences)" : '';

        return "Replaced{$suffix}:\n- {$oldPreview}\n+ {$newPreview}";
    }

    private function truncate(string $str, int $maxLen): string
    {
        $singleLine = str_replace(["\n", "\r"], ['\\n', '\\r'], $str);
        if (mb_strlen($singleLine) > $maxLen) {
            return mb_substr($singleLine, 0, $maxLen) . '...';
        }

        return $singleLine;
    }

    /**
     * Fail closed on clearly non-text payloads so Edit never corrupts binaries.
     */
    private function looksBinary(string $content, string $filePath): bool
    {
        if ($content === '') {
            return false;
        }

        if (str_contains($content, "\0")) {
            return true;
        }

        $mime = function_exists('mime_content_type')
            ? @mime_content_type($filePath)
            : false;
        if (is_string($mime) && $mime !== '') {
            if (str_starts_with($mime, 'text/')) {
                return false;
            }
            // Common structured text types that mime_content_type may not call text/*
            $textMimes = [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/javascript',
                'application/x-javascript',
                'application/x-httpd-php',
                'application/sql',
                'application/yaml',
                'application/x-yaml',
                'application/toml',
                'application/x-sh',
                'application/x-shellscript',
                'inode/x-empty',
            ];
            if (in_array($mime, $textMimes, true) || str_ends_with($mime, '+json') || str_ends_with($mime, '+xml')) {
                return false;
            }
            if (str_starts_with($mime, 'image/')
                || str_starts_with($mime, 'audio/')
                || str_starts_with($mime, 'video/')
                || str_starts_with($mime, 'font/')
                || $mime === 'application/octet-stream'
                || $mime === 'application/pdf'
                || $mime === 'application/zip') {
                return true;
            }
        }

        // Heuristic: high ratio of non-printable / non-whitespace control bytes
        // in the first 8 KiB indicates binary content.
        $sample = substr($content, 0, 8192);
        $len = strlen($sample);
        if ($len === 0) {
            return false;
        }
        $nonText = 0;
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($sample[$i]);
            if ($ord === 9 || $ord === 10 || $ord === 13) {
                continue;
            }
            if ($ord < 32 || $ord === 127) {
                $nonText++;
            }
        }

        return ($nonText / $len) > 0.30;
    }
}
