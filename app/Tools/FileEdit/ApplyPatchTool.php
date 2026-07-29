<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\FileEdit\PatchApplier;
use HaoCode\Services\FileEdit\PatchEnvelopeParser;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Tool: apply_patch
 *
 * Accepts a codex envelope patch and applies it with rollback on failure.
 * Supports Add / Update / Delete file operations in a single call.
 *
 * Envelope format:
 *   *** Begin Patch
 *   *** Add File: path/to/new.txt
 *   <content>
 *   *** Update File: path/to/existing.txt
 *   *** Begin Hunk
 *    context line
 *   -removed line
 *   +added line
 *    context line
 *   *** End Hunk
 *   *** Delete File: path/to/old.txt
 *   *** End Patch
 *
 * @experimental Windows rename atomicity is weak; use with caution on Windows.
 */
class ApplyPatchTool extends BaseTool
{
    public function __construct(
        private readonly PatchApplier $applier,
        private readonly PatchEnvelopeParser $parser,
        private readonly PhoenixTracer $tracer,
    ) {}

    public function name(): string
    {
        return 'apply_patch';
    }

    public function description(): string
    {
        return <<<'DESC'
Apply a codex-envelope format patch transactionally to one or more files.

Supports Add File / Update File / Delete File operations.
If an operation fails, already-applied operations are restored.
Files to be updated or deleted must have been read first (Read-before-Write).
Max 50 files per patch, total patch size ≤ 1 MiB.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'patch' => [
                    'type' => 'string',
                    'description' => 'Codex envelope patch starting with "*** Begin Patch" and ending with "*** End Patch".',
                ],
            ],
            'required' => ['patch'],
        ], [
            'patch' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $envelope = $input['patch'] ?? '';
        $startTime = microtime(true);

        try {
            $operations = $this->parser->parse($envelope);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error('Parse error: '.$e->getMessage());
        }

        $span = $this->tracer->startSpan('tool.file_edit.apply_patch', PhoenixTracer::KIND_TOOL, [
            'patch_file_count' => count($operations),
            'patch_line_count' => $this->parser->countLines($operations),
            'patch_add_count' => count(array_filter($operations, fn ($o) => $o->type === 'add')),
            'patch_update_count' => count(array_filter($operations, fn ($o) => $o->type === 'update')),
            'patch_delete_count' => count(array_filter($operations, fn ($o) => $o->type === 'delete')),
            'patch_hunk_count' => $this->parser->countHunks($operations),
        ]);

        try {
            $messages = $this->applier->apply($envelope, $context);
            $span?->setAttribute('result', 'success');
            $span?->setAttribute('duration_ms', (int) round((microtime(true) - $startTime) * 1000));
            $span?->end();

            return ToolResult::success(implode("\n", $messages));
        } catch (\RuntimeException $e) {
            $span?->setAttribute('result', 'error');
            $span?->setAttribute('duration_ms', (int) round((microtime(true) - $startTime) * 1000));
            $this->tracer->recordException($span, $e);
            $span?->end();

            return ToolResult::error($e->getMessage());
        }
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Applying patch';
    }
}
