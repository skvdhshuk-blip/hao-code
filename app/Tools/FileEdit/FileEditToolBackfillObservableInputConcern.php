<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait FileEditToolBackfillObservableInputConcern
{

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

        $oldString = (string) ($input['old_string'] ?? '');
        $newString = (string) ($input['new_string'] ?? '');
        if (strlen($oldString) > self::MAX_EDIT_STRING_BYTES || strlen($newString) > self::MAX_EDIT_STRING_BYTES) {
            return 'old_string and new_string must each be at most '.self::MAX_EDIT_STRING_BYTES.' bytes. '
                .'Use a smaller targeted replacement.';
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
                || $mime === 'application/pdf'
                || $mime === 'application/zip') {
                return true;
            }
            // Some PHP/fileinfo versions classify extensionless text files as
            // application/octet-stream. Let the control-byte heuristic below
            // decide those cases instead of rejecting valid text outright.
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
