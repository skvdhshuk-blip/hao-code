<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Sdk\Sandbox\Backends\LocalSandboxBackend;
use HaoCode\Support\Filesystem\FileContentTypeDetector;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxReadTool extends SandboxTool
{
    private const MAX_TEXT_LINE_BYTES = 1_000_000;

    public function name(): string { return 'Read'; }

    public function description(): string
    {
        return 'Reads a text file from the configured HaoCode sandbox filesystem. Images and PDFs return an explicit error. Relative paths are resolved inside the sandbox working directory.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Sandbox file path to read.'],
                'offset' => ['type' => 'integer', 'description' => 'Line number to start from, 1-based.'],
                'limit' => ['type' => 'integer', 'description' => 'Number of lines to read.'],
            ],
            'required' => ['file_path'],
        ], [
            'file_path' => 'required|string',
            'offset' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $path = $this->resolveRemotePath((string) $input['file_path'], $context);
        $offset = (int) ($input['offset'] ?? 1);
        $limit = (int) ($input['limit'] ?? 2000);

        if ($context->isAborted()) {
            return ToolResult::aborted('Sandbox Read aborted.');
        }

        if ($this->runtime->backend instanceof LocalSandboxBackend) {
            return $this->readLocalSandboxFile($this->runtime->backend, $path, $offset, $limit, $context);
        }

        try {
            $content = $this->runtime->backend->readFile($path);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        $contentType = FileContentTypeDetector::detect(
            $path,
            substr($content, 0, 1024),
        );
        if ($contentType === 'image') {
            return ToolResult::error(
                "Read does not support model-visible image content blocks for {$path}. "
                .'Pass the image through the SDK image input API instead.',
            );
        }
        if ($contentType === 'pdf') {
            return ToolResult::error(
                "PDF text cannot be extracted through sandbox Read for {$path}. "
                .'Sandbox Read does not return document content blocks or base64 fallbacks.',
            );
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $total = count($lines);
        if ($offset > $total && $total > 0) {
            return ToolResult::error("Offset {$offset} exceeds file length ({$total} lines).");
        }
        $selected = array_slice($lines, max(0, $offset - 1), $limit);
        $isPartial = $offset > 1 || $limit < $total;

        $output = "File: {$path} ({$total} lines total, sandbox)\n";
        if ($isPartial) {
            $end = $offset + count($selected) - 1;
            $output .= "Lines {$offset}-{$end}\n";
        }
        $output .= str_repeat('-', 60)."\n";
        foreach ($selected as $i => $line) {
            $output .= sprintf("%6d\t%s\n", $offset + $i, $line);
        }

        $context->recordVirtualFileRead($path, $content, $offset, $limit, $isPartial);

        return ToolResult::success($output);
    }

    private function readLocalSandboxFile(
        LocalSandboxBackend $backend,
        string $path,
        int $offset,
        int $limit,
        ToolUseContext $context,
    ): ToolResult {
        try {
            $local = $this->resolveLocalSandboxPath($backend, $path);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        if (! is_file($local)) {
            return ToolResult::error("Sandbox file does not exist: {$path}");
        }
        if (! is_readable($local)) {
            return ToolResult::error("Sandbox file is not readable: {$path}");
        }

        $handle = @fopen($local, 'rb');
        if (! is_resource($handle)) {
            return ToolResult::error("Failed to read sandbox file: {$path}");
        }

        try {
            $prefix = fread($handle, 1024);
            if ($prefix === false) {
                return ToolResult::error("Failed to read sandbox file: {$path}");
            }
            if (fseek($handle, 0) !== 0) {
                return ToolResult::error("Failed to read sandbox file: {$path}");
            }

            $contentType = FileContentTypeDetector::detect($path, $prefix);
            if ($contentType === 'image') {
                return ToolResult::error(
                    "Read does not support model-visible image content blocks for {$path}. "
                    .'Pass the image through the SDK image input API instead.',
                );
            }
            if ($contentType === 'pdf') {
                return ToolResult::error(
                    "PDF text cannot be extracted through sandbox Read for {$path}. "
                    .'Sandbox Read does not return document content blocks or base64 fallbacks.',
                );
            }

            $scan = $this->readTextLineWindow($handle, $path, $offset, $limit, $context);
            if (is_string($scan['error'] ?? null)) {
                return ToolResult::error($scan['error']);
            }
            if (($scan['aborted'] ?? false) === true) {
                return ToolResult::aborted('Sandbox Read aborted.');
            }
        } finally {
            fclose($handle);
        }

        $total = $scan['totalLines'];
        if ($offset > $total && $total > 0) {
            return ToolResult::error("Offset {$offset} exceeds file length ({$total} lines).");
        }

        $selected = $scan['selectedLines'];
        $isPartial = $offset > 1 || $limit < $total;

        $output = "File: {$path} ({$total} lines total, sandbox)\n";
        if ($isPartial) {
            $end = $offset + count($selected) - 1;
            $output .= "Lines {$offset}-{$end}\n";
        }
        $output .= str_repeat('-', 60)."\n";
        foreach ($selected as $i => $line) {
            $output .= sprintf("%6d\t%s\n", $offset + $i, $line);
        }

        $context->recordObservedVirtualFileRevision(
            $path,
            $scan['size'],
            $scan['sha256'],
            $offset,
            $limit,
            $isPartial,
            $total,
        );

        return ToolResult::success($output);
    }

    private function resolveLocalSandboxPath(LocalSandboxBackend $backend, string $path): string
    {
        $root = rtrim($backend->rootLabel(), DIRECTORY_SEPARATOR);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $resolved = $root.(count($parts) > 0 ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts) : '');
        $existing = $resolved;
        while (! file_exists($existing) && ! is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        $canonical = realpath($existing);
        if ($canonical === false) {
            throw new \RuntimeException("Failed to resolve sandbox path: {$path}");
        }
        if ($canonical !== $root && ! str_starts_with($canonical, $root.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Sandbox path escapes through a symbolic link: {$path}");
        }

        return $resolved;
    }

    /**
     * @param resource $handle
     * @return array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, aborted?: bool, error?: string}
     */
    private function readTextLineWindow($handle, string $path, int $offset, int $limit, ToolUseContext $context): array
    {
        $selected = [];
        $lineNumber = 0;
        $buffer = '';
        $hash = hash_init('sha256');
        $size = 0;

        while (! feof($handle)) {
            if ($context->isAborted()) {
                return ['aborted' => true];
            }

            $chunk = fread($handle, 64 * 1024);
            if ($chunk === false) {
                return ['error' => "Failed to read sandbox file: {$path}"];
            }
            if ($chunk === '') {
                continue;
            }

            hash_update($hash, $chunk);
            $size += strlen($chunk);
            $buffer .= $chunk;
            if (strlen($buffer) > self::MAX_TEXT_LINE_BYTES
                && preg_match('/\r\n|\n|\r/', $buffer) !== 1) {
                return [
                    'error' => 'Line exceeds '.self::MAX_TEXT_LINE_BYTES." bytes in {$path}. "
                        .'Use a more specialized byte-range workflow for extremely long lines.',
                ];
            }

            while (preg_match('/\r\n|\n|\r/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $line = substr($buffer, 0, (int) $match[0][1]);
                if (strlen($line) > self::MAX_TEXT_LINE_BYTES) {
                    return [
                        'error' => 'Line exceeds '.self::MAX_TEXT_LINE_BYTES." bytes in {$path}. "
                            .'Use a more specialized byte-range workflow for extremely long lines.',
                    ];
                }
                $lineNumber++;
                if ($lineNumber >= $offset && count($selected) < $limit) {
                    $selected[] = $line;
                }
                $buffer = substr($buffer, (int) $match[0][1] + strlen($match[0][0]));
            }
        }

        if ($buffer !== '') {
            if (strlen($buffer) > self::MAX_TEXT_LINE_BYTES) {
                return [
                    'error' => 'Line exceeds '.self::MAX_TEXT_LINE_BYTES." bytes in {$path}. "
                        .'Use a more specialized byte-range workflow for extremely long lines.',
                ];
            }
            $lineNumber++;
            if ($lineNumber >= $offset && count($selected) < $limit) {
                $selected[] = $buffer;
            }
        }

        return [
            'selectedLines' => $selected,
            'totalLines' => $lineNumber,
            'size' => $size,
            'sha256' => hash_final($hash),
        ];
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $path = trim((string) ($input['file_path'] ?? ''));
        if ($path === '') return 'file_path must not be empty.';
        if ($this->isBareLineReference($path)) return 'file_path must include an actual path, not only a line reference like ":12".';
        return null;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $input['file_path'] = $this->resolveRemotePath((string) $input['file_path'], $context);
        }
        return $input;
    }

    public function isReadOnly(array $input): bool { return true; }
    public function maxResultSizeChars(): int { return PHP_INT_MAX; }
    public function getActivityDescription(array $input): ?string { return 'Reading sandbox '.basename($input['file_path'] ?? 'file'); }
    public function isSearchOrReadCommand(array $input): array { return ['isSearch' => false, 'isRead' => true, 'isList' => false]; }
}
