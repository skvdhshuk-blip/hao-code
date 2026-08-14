<?php

namespace HaoCode\Tools\FileRead;

use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\BoundedTextFileReader;
use HaoCode\Support\Filesystem\FileContentTypeDetector;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class FileReadTool extends BaseTool
{
    private const MAX_PDF_OUTPUT_BYTES = 100_000;
    private const PDF_TIMEOUT_SECONDS = 10.0;
    private const MAX_NOTEBOOK_BYTES = 8_388_608; // 8 MiB JSON input cap
    private const MAX_NOTEBOOK_OUTPUT_BYTES = 100_000;

    public function name(): string
    {
        return 'Read';
    }

    public function description(): string
    {
        return <<<DESC
Reads a file from the local filesystem. You can access any file directly by using this tool.

Usage:
- The file_path parameter may be absolute or relative. Relative paths are resolved against the current working directory.
- By default, it reads up to 2000 lines starting from the beginning of the file.
- You can optionally specify a line offset and limit.
- Results are returned with line numbers starting at 1.
- This tool reads text files and Jupyter notebooks, and extracts PDF text when
  the host has pdftotext available.
- Tool-result image/document content blocks are not supported. Image files and
  PDFs without extractable text return an explicit error instead of base64 text.
- If the file does not exist, an error will be returned.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The file path to read. Relative paths are resolved against the current working directory.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'The line number to start reading from (1-based)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'The number of lines to read',
                ],
                'pages' => [
                    'type' => 'string',
                    'description' => 'Page range for PDF files (e.g., "1-5", "3", "10-20"). Max 20 pages per request.',
                ],
            ],
            'required' => ['file_path'],
        ], [
            'file_path' => 'required|string',
            'offset' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
            'pages' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $offset = $input['offset'] ?? 1;
        $limit = $input['limit'] ?? 2000;

        if (!file_exists($filePath)) {
            return ToolResult::error("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            return ToolResult::error("File is not readable: {$filePath}");
        }

        if (is_dir($filePath)) {
            return ToolResult::error("Path is a directory, not a file: {$filePath}");
        }

        // Keep the text-only contract even when fileinfo is unavailable or a
        // binary file has been renamed to omit its usual extension.
        $prefix = @file_get_contents($filePath, false, null, 0, 1024);
        $mimeType = function_exists('mime_content_type') ? @mime_content_type($filePath) : null;
        $contentType = FileContentTypeDetector::detect(
            $filePath,
            is_string($prefix) ? $prefix : '',
            is_string($mimeType) ? $mimeType : null,
        );
        if ($contentType === 'image') {
            return ToolResult::error(
                "Read does not support model-visible image content blocks for {$filePath}. "
                .'Pass the image through the SDK image input API instead.',
            );
        }

        // Handle PDF files
        if ($contentType === 'pdf') {
            $result = $this->readPdf($filePath, $input['pages'] ?? null, $context->isAborted(...));
            if (! $result->isError) {
                $context->recordFileRead(
                    $filePath,
                    null,
                    isPartialView: (isset($input['pages']) && trim((string) $input['pages']) !== '')
                        || (($result->metadata['outputLimited'] ?? false) === true),
                );
            }

            return $result;
        }

        // Handle Jupyter notebooks
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'ipynb') {
            $size = filesize($filePath);
            if ($size !== false && $size > self::MAX_NOTEBOOK_BYTES) {
                return ToolResult::error(
                    'Notebook too large: '.round($size / 1024 / 1024, 1).' MB '
                    .'(max '.round(self::MAX_NOTEBOOK_BYTES / 1024 / 1024, 1).' MB)',
                );
            }
            // The size check above is only a snapshot.  Read at most one byte
            // past the cap so a notebook that grows (or is a FIFO/special
            // file) cannot turn this branch into an unbounded allocation.
            $handle = @fopen($filePath, 'rb');
            if (! is_resource($handle)) {
                return ToolResult::error("Failed to read file: {$filePath}");
            }
            $rawContent = @stream_get_contents($handle, self::MAX_NOTEBOOK_BYTES + 1);
            fclose($handle);
            if (! is_string($rawContent)) {
                return ToolResult::error("Failed to read file: {$filePath}");
            }
            if (strlen($rawContent) > self::MAX_NOTEBOOK_BYTES) {
                return ToolResult::error(
                    'Notebook too large: exceeds '.round(self::MAX_NOTEBOOK_BYTES / 1024 / 1024, 1).' MB '
                    .'(max '.round(self::MAX_NOTEBOOK_BYTES / 1024 / 1024, 1).' MB)',
                );
            }
            $result = $this->readNotebook($filePath, $rawContent, $context->isAborted(...));
            if (! $result->isError) {
                $context->recordFileRead(
                    $filePath,
                    $rawContent,
                    1,
                    null,
                    ($result->metadata['outputLimited'] ?? false) === true,
                );
            }

            return $result;
        }

        $scan = BoundedTextFileReader::readPath(
            $filePath,
            $filePath,
            $offset,
            $limit,
            $context->isAborted(...),
        );
        if (($scan['aborted'] ?? false) === true) {
            return ToolResult::aborted();
        }
        if (is_string($scan['error'] ?? null)) {
            return ToolResult::error($scan['error']);
        }

        $totalLines = $scan['totalLines'];

        if ($offset > $totalLines && $totalLines > 0) {
            return ToolResult::error(
                "Offset {$offset} exceeds file length ({$totalLines} lines). " .
                "Valid range: 1-{$totalLines}."
            );
        }

        $selectedLines = $scan['selectedLines'];

        if ($context->isAborted()) {
            return ToolResult::aborted();
        }

        $output = '';
        foreach ($selectedLines as $i => $line) {
            $lineNum = $offset + $i;
            $output .= sprintf("%6d\t%s\n", $lineNum, $line);
        }

        $isPartial = ($offset > 1 || $limit < $totalLines);
        $header = "File: {$filePath} ({$totalLines} lines total)\n";
        if ($isPartial) {
            $endLine = $offset + count($selectedLines) - 1;
            $header .= "Lines {$offset}-{$endLine}\n";
        }
        $header .= str_repeat('-', 60) . "\n";

        $rendered = $header . $output;
        if (strlen($rendered) > BoundedTextFileReader::MAX_OUTPUT_BYTES) {
            return ToolResult::error(
                'Read output exceeds '.BoundedTextFileReader::MAX_OUTPUT_BYTES." bytes in {$filePath}. "
                    .'Use a smaller limit or a later offset.',
            );
        }
        if ($context->isAborted()) {
            return ToolResult::aborted();
        }

        $context->recordObservedFileRevision(new FileRevision(
            canonicalPath: realpath($filePath) ?: $filePath,
            device: $scan['device'],
            inode: $scan['inode'],
            size: $scan['size'],
            mtime: $scan['mtime'],
            sha256: $scan['sha256'],
            complete: true,
            observedAtMicros: (int) round(microtime(true) * 1_000_000),
            local: true,
        ), null, $offset, $limit, $isPartial, $totalLines);

        return ToolResult::success($rendered);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
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

        $offset = $input['offset'] ?? 1;
        if (! is_int($offset) || $offset < 1 || $offset > BoundedTextFileReader::MAX_OFFSET) {
            return 'offset must be between 1 and '.BoundedTextFileReader::MAX_OFFSET.'.';
        }
        $limit = $input['limit'] ?? 2000;
        if (! is_int($limit) || $limit < 1 || $limit > BoundedTextFileReader::MAX_LIMIT) {
            return 'limit must be between 1 and '.BoundedTextFileReader::MAX_LIMIT.'.';
        }

        if (isset($input['pages']) && trim((string) $input['pages']) !== '') {
            $pages = trim((string) $input['pages']);
            if (preg_match('/^\d+(\s*-\s*\d+)?$/', $pages) !== 1) {
                return 'pages must be a page number or range like "3" or "1-5".';
            }
            if (preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/', $pages, $m) === 1) {
                $first = (int) $m[1];
                $last = isset($m[2]) ? (int) $m[2] : $first;
                if ($first < 1 || $last < 1) {
                    return 'pages must start at page 1 or later.';
                }
                if ($first > $last) {
                    return 'pages range start must be less than or equal to the end page.';
                }
            }
        }

        return null;
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
        return PHP_INT_MAX; // Never truncate/persist - avoid circular Read->file->Read loop
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Reading ' . basename($input['file_path'] ?? 'file');
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => false, 'isRead' => true, 'isList' => false];
    }

    /** @param callable(): bool|null $shouldAbort */
    private function readPdf(string $filePath, ?string $pageRange = null, ?callable $shouldAbort = null): ToolResult
    {
        $size = filesize($filePath);
        if ($size > 32 * 1024 * 1024) {
            return ToolResult::error("PDF too large: " . round($size / 1024 / 1024, 1) . " MB (max 32 MB)");
        }

        // Parse page range
        $firstPage = null;
        $lastPage = null;
        if ($pageRange !== null) {
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', trim($pageRange), $m)) {
                $firstPage = (int) $m[1];
                $lastPage = (int) $m[2];
            } elseif (preg_match('/^(\d+)$/', trim($pageRange), $m)) {
                $firstPage = (int) $m[1];
                $lastPage = (int) $m[1];
            }

            if ($firstPage !== null && $lastPage !== null) {
                if ($firstPage < 1 || $lastPage < 1) {
                    return ToolResult::error('PDF pages must start at page 1 or later.');
                }
                if ($firstPage > $lastPage) {
                    return ToolResult::error('PDF page range start must be less than or equal to the end page.');
                }
                if (($lastPage - $firstPage + 1) > 20) {
                    return ToolResult::error("Maximum 20 pages per request. Requested: " . ($lastPage - $firstPage + 1));
                }
            }
        }

        $pdftotext = $this->findExecutable('pdftotext');
        if ($pdftotext === null) {
            return ToolResult::error(
                "PDF text could not be extracted from {$filePath}: pdftotext is not installed or not on PATH. "
                .'Read does not support model-visible document content blocks or base64 fallbacks.',
            );
        }

        $args = [$pdftotext, '-layout'];
        if ($firstPage !== null) {
            $args[] = '-f';
            $args[] = (string) $firstPage;
        }
        if ($lastPage !== null) {
            $args[] = '-l';
            $args[] = (string) $lastPage;
        }
        $args[] = $filePath;
        $args[] = '-';

        $extracted = $this->runProcessWithOutputCap($args, self::PDF_TIMEOUT_SECONDS, self::MAX_PDF_OUTPUT_BYTES, $shouldAbort);
        if ($extracted['aborted']) {
            return ToolResult::error('PDF text extraction interrupted by user.', [
                'exitCode' => 130,
                'aborted' => true,
            ]);
        }
        if ($extracted['timedOut']) {
            return ToolResult::error('PDF text extraction timed out after '.self::PDF_TIMEOUT_SECONDS.' seconds.');
        }
        if ($extracted['exitCode'] !== 0 || trim($extracted['stdout']) === '') {
            return ToolResult::error(
                "PDF text could not be extracted from {$filePath}. "
                .'Read does not support model-visible document content blocks or base64 fallbacks.',
            );
        }

        $pages = 'unknown';
        $pdfinfo = $this->findExecutable('pdfinfo');
        if ($pdfinfo !== null) {
            $info = $this->runProcessWithOutputCap([$pdfinfo, $filePath], 5.0, 32_768, $shouldAbort);
            if ($info['exitCode'] === 0 && preg_match('/^Pages:\s*(\d+)/mi', $info['stdout'], $m) === 1) {
                $pages = $m[1];
            }
        }

        $text = $extracted['stdout'];
        $limited = $extracted['outputLimited'];
        if ($limited) {
            $text .= "\n\n[PDF text truncated at ".self::MAX_PDF_OUTPUT_BYTES.' bytes]';
        }
        $rangeInfo = $pageRange !== null ? ", pages {$pageRange}" : '';

        return ToolResult::success(
            "[PDF: {$filePath}, {$pages} total pages{$rangeInfo}, text extracted]\n\n".$text,
            ['outputLimited' => $limited],
        );
    }

    /** @param callable(): bool|null $shouldAbort */
    private function readNotebook(string $filePath, string $content, ?callable $shouldAbort = null): ToolResult
    {
        if ($shouldAbort !== null && $shouldAbort()) {
            return ToolResult::aborted();
        }

        $notebook = json_decode($content, true);

        if (!is_array($notebook) || !isset($notebook['cells'])) {
            return ToolResult::error("Invalid Jupyter notebook format: {$filePath}");
        }

        $output = "[Jupyter Notebook: {$filePath}]\n\n";
        $outputLimited = false;
        $cellCount = count($notebook['cells']);

        foreach ($notebook['cells'] as $i => $cell) {
            if ($shouldAbort !== null && $shouldAbort()) {
                return ToolResult::aborted();
            }
            if (strlen($output) >= self::MAX_NOTEBOOK_OUTPUT_BYTES) {
                $outputLimited = true;
                break;
            }
            $cellNum = $i + 1;
            $cellType = $cell['cell_type'] ?? 'unknown';
            $source = is_array($cell['source'] ?? null) ? implode('', $cell['source']) : ($cell['source'] ?? '');

            $cellText = "--- Cell {$cellNum}/{$cellCount} [{$cellType}] ---\n";

            if ($cellType === 'code') {
                $cellText .= "```\n{$source}\n```\n";

                // Show outputs if present
                $outputs = $cell['outputs'] ?? [];
                foreach ($outputs as $cellOutput) {
                    $outputType = $cellOutput['output_type'] ?? '';
                    if ($outputType === 'stream') {
                        $text = is_array($cellOutput['text'] ?? null) ? implode('', $cellOutput['text']) : ($cellOutput['text'] ?? '');
                        $cellText .= "Output:\n{$text}\n";
                    } elseif ($outputType === 'execute_result' || $outputType === 'display_data') {
                        $data = $cellOutput['data'] ?? [];
                        if (isset($data['text/plain'])) {
                            $text = is_array($data['text/plain']) ? implode('', $data['text/plain']) : $data['text/plain'];
                            $cellText .= "Output:\n{$text}\n";
                        }
                    } elseif ($outputType === 'error') {
                        $ename = $cellOutput['ename'] ?? 'Error';
                        $evalue = $cellOutput['evalue'] ?? '';
                        $cellText .= "Error: {$ename}: {$evalue}\n";
                    }
                }
            } else {
                $cellText .= "{$source}\n";
            }

            $cellText .= "\n";
            if (strlen($output) + strlen($cellText) > self::MAX_NOTEBOOK_OUTPUT_BYTES) {
                $room = self::MAX_NOTEBOOK_OUTPUT_BYTES - strlen($output);
                $output .= substr($cellText, 0, max(0, $room));
                $outputLimited = true;
                break;
            }
            $output .= $cellText;
        }

        if ($outputLimited) {
            $output .= "\n\n[Notebook output truncated at ".self::MAX_NOTEBOOK_OUTPUT_BYTES.' bytes]';
        }

        return ToolResult::success($output, ['outputLimited' => $outputLimited]);
    }

    private function findExecutable(string $name): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        $extensions = PHP_OS_FAMILY === 'Windows'
            ? array_filter(explode(';', getenv('PATHEXT') ?: '.EXE;.BAT;.CMD'))
            : [''];
        foreach ($paths as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name.$extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     * @param callable(): bool|null $shouldAbort
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool, aborted: bool, outputLimited: bool}
     */
    private function runProcessWithOutputCap(
        array $command,
        float $timeoutSeconds,
        int $stdoutByteCap,
        ?callable $shouldAbort = null,
    ): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [
            0 => ['file', $null, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false, 'aborted' => false, 'outputLimited' => false];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;
        $aborted = false;
        $outputLimited = false;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $target) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = @stream_get_contents($pipes[$index]);
                if (! is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($target === 'stdout') {
                    $room = $stdoutByteCap - strlen($stdout);
                    if (strlen($chunk) > $room) {
                        $stdout .= substr($chunk, 0, max(0, $room));
                        $outputLimited = true;
                        \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                        break 2;
                    }
                    $stdout .= $chunk;
                } else {
                    $stderr = substr($stderr.$chunk, -32_768);
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
                $timedOut = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            usleep(20_000);
        }

        foreach ([1, 2] as $index) {
            if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                continue;
            }
            $chunk = @stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                if ($index === 1 && ! $outputLimited) {
                    $room = $stdoutByteCap - strlen($stdout);
                    if (strlen($chunk) > $room) {
                        $stdout .= substr($chunk, 0, max(0, $room));
                        $outputLimited = true;
                    } else {
                        $stdout .= $chunk;
                    }
                } elseif ($index === 2) {
                    $stderr = substr($stderr.$chunk, -32_768);
                }
            }
            fclose($pipes[$index]);
        }

        $closed = @proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $outputLimited) {
            $exitCode = $closed;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $aborted ? 130 : ($timedOut ? -1 : ($outputLimited ? 1 : $exitCode)),
            'timedOut' => $timedOut,
            'aborted' => $aborted,
            'outputLimited' => $outputLimited,
        ];
    }
}
