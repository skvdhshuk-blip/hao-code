<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Sdk\Sandbox\Backends\LocalSandboxBackend;
use HaoCode\Support\Filesystem\BoundedTextFileReader;
use HaoCode\Support\Filesystem\FileContentTypeDetector;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxReadTool extends SandboxTool
{
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

        return $this->renderTextRead(
            BoundedTextFileReader::readString(
                $content,
                $path,
                $offset,
                $limit,
                $context->isAborted(...),
            ),
            $path,
            $offset,
            $limit,
            $context,
            $content,
        );
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

            $scan = BoundedTextFileReader::readHandle(
                $handle,
                $path,
                $offset,
                $limit,
                $context->isAborted(...),
            );
        } finally {
            fclose($handle);
        }

        return $this->renderTextRead($scan, $path, $offset, $limit, $context);
    }

    /**
     * @param array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, error?: string, aborted?: bool} $scan
     */
    private function renderTextRead(
        array $scan,
        string $path,
        int $offset,
        int $limit,
        ToolUseContext $context,
        ?string $content = null,
    ): ToolResult {
        if (($scan['aborted'] ?? false) === true) {
            return ToolResult::aborted('Sandbox Read aborted.');
        }
        if (is_string($scan['error'] ?? null)) {
            return ToolResult::error($scan['error']);
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

        if (strlen($output) > BoundedTextFileReader::MAX_OUTPUT_BYTES) {
            return ToolResult::error(
                'Read output exceeds '.BoundedTextFileReader::MAX_OUTPUT_BYTES." bytes in {$path}. "
                    .'Use a smaller limit or a later offset.',
            );
        }
        if ($context->isAborted()) {
            return ToolResult::aborted('Sandbox Read aborted.');
        }

        if ($content !== null) {
            $context->recordVirtualFileRead($path, $content, $offset, $limit, $isPartial);
        } else {
            $context->recordObservedVirtualFileRevision(
                $path,
                $scan['size'],
                $scan['sha256'],
                $offset,
                $limit,
                $isPartial,
                $total,
            );
        }

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

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $path = trim((string) ($input['file_path'] ?? ''));
        if ($path === '') return 'file_path must not be empty.';
        if ($this->isBareLineReference($path)) return 'file_path must include an actual path, not only a line reference like ":12".';

        $offset = $input['offset'] ?? 1;
        if (! is_int($offset) || $offset < 1 || $offset > BoundedTextFileReader::MAX_OFFSET) {
            return 'offset must be between 1 and '.BoundedTextFileReader::MAX_OFFSET.'.';
        }
        $limit = $input['limit'] ?? 2000;
        if (! is_int($limit) || $limit < 1 || $limit > BoundedTextFileReader::MAX_LIMIT) {
            return 'limit must be between 1 and '.BoundedTextFileReader::MAX_LIMIT.'.';
        }

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
