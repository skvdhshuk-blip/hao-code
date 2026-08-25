<?php

namespace HaoCode\Tools\FileWrite;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\FileEdit\DiffGenerator;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class FileWriteTool extends BaseTool
{
    private const MAX_WRITE_CONTENT_BYTES = 1_000_000;
    private const MAX_DIFF_SOURCE_BYTES = 1_000_000;

    public function __construct(private readonly ?FileHistoryManager $fileHistory = null) {}

    public function name(): string
    {
        return 'Write';
    }

    public function description(): string
    {
        return <<<DESC
Writes a file to the local filesystem.

Usage:
- This tool will overwrite the existing file if there is one at the provided path.
- You may provide an absolute path or a path relative to the current working directory.
- If this is an existing file, you MUST use the Read tool first to read the file's contents.
- Only use emojis if the user explicitly requests it.
- NEVER create documentation files (*.md) or README files unless explicitly requested.
- Do not send huge multiline source files in one call. For long or quote-heavy files, write a tiny scaffold first and then use Edit in small chunks.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The file path to write. Absolute paths are preferred; relative paths are resolved against the current working directory.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required' => ['file_path', 'content'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $content = $input['content'];

        $existed = file_exists($filePath);
        $expectedRevision = null;
        if ($existed && ($revisionError = $context->fileRevisionError($filePath)) !== null) {
            return ToolResult::error(
                $revisionError.' '.
                "Next step: call Read on this exact path, then retry Write."
            );
        }
        if ($existed) {
            $expectedRevision = $context->getFileRevision($filePath);
        }

        $originalContent = null;
        $diffSourceSkipped = false;
        if ($existed) {
            // Read at most one byte past the summary cap.  A filesize check
            // alone is only a snapshot and a concurrently growing file could
            // otherwise turn this diagnostic-only read into an unbounded
            // allocation.
            $sample = @file_get_contents($filePath, false, null, 0, self::MAX_DIFF_SOURCE_BYTES + 1);
            if (! is_string($sample)) {
                $diffSourceSkipped = true;
            } elseif (strlen($sample) > self::MAX_DIFF_SOURCE_BYTES) {
                // The replacement itself is bounded, but loading an
                // unrelated multi-gigabyte old file solely to print a
                // summary would defeat that resource boundary.
                $diffSourceSkipped = true;
            } else {
                $originalContent = $sample;
            }
        }

        // Ensure parent directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        try {
            (new AtomicFileWriter())->write(
                $filePath,
                $content,
                $expectedRevision,
                $existed && $this->fileHistory !== null
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

        // A successful write gives the agent authoritative knowledge of the file's
        // current contents, so subsequent refinements in the same session should not
        // be blocked by read-before-write.
        $context->recordFileRead($filePath, $content, 1, null, false);

        $action = $existed ? 'updated' : 'created';
        $lines = substr_count($content, "\n") + ($content !== '' ? 1 : 0);
        $bytes = strlen($content);

        $output = "Successfully {$action} {$filePath} ({$lines} lines, {$bytes} bytes)";

        // Show change summary for updates
        if ($existed && $originalContent !== null) {
            $changeSummary = DiffGenerator::changeSummary($originalContent, $content);
            $output .= " [{$changeSummary}]";

            // Try git diff for update
            $gitDiff = DiffGenerator::gitDiff($filePath, $context->isAborted(...));
            if ($gitDiff !== '') {
                // Truncate large diffs
                if (mb_strlen($gitDiff) > 3000) {
                    $gitDiff = mb_substr($gitDiff, 0, 3000) . "\n... [diff truncated]";
                }
                $output .= "\n\nGit diff:\n" . $gitDiff;
            }
        } elseif ($existed && $diffSourceSkipped) {
            $output .= ' [change summary omitted: existing file exceeds '
                .self::MAX_DIFF_SOURCE_BYTES.' bytes]';
        }

        // Scan for secrets and warn
        $scanner = new SecretScanner();
        $secrets = $scanner->scan($content);
        if (!empty($secrets)) {
            $types = array_unique(array_map(fn($s) => $s['type'], $secrets));
            $output .= "\n\nWARNING: Potential secrets detected: " . implode(', ', $types)
                . ". Consider using environment variables instead of hardcoding credentials.";
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function maxResultSizeChars(): int
    {
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        $file = basename($input['file_path'] ?? 'file');
        $exists = file_exists($input['file_path'] ?? '');

        return ($exists ? 'Updating ' : 'Creating ') . $file;
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

        $content = (string) ($input['content'] ?? '');
        if (strlen($content) > self::MAX_WRITE_CONTENT_BYTES) {
            return 'content is too large for a single Write call (max '.self::MAX_WRITE_CONTENT_BYTES.' bytes). '
                .'Write a smaller scaffold or split the change into targeted edits.';
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
}
