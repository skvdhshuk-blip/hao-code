<?php

namespace HaoCode\Tools\Notebook;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class NotebookEditTool extends BaseTool
{
    private readonly AtomicFileWriter $atomicWriter;

    public function __construct(
        ?AtomicFileWriter $atomicWriter = null,
        private readonly ?FileHistoryManager $historyManager = null,
    ) {
        $this->atomicWriter = $atomicWriter ?? new AtomicFileWriter;
    }

    public function name(): string
    {
        return 'NotebookEdit';
    }

    public function description(): string
    {
        return <<<'DESC'
Completely replaces the contents of a specific cell in a Jupyter notebook (.ipynb) with new source.

Usage:
- notebook_path: Absolute path to the .ipynb file
- cell_number: 0-indexed cell number
- new_source: The new cell source content (not used for delete)
- cell_type: "code" or "markdown" (defaults to current cell type)
- edit_mode: "replace" (default), "insert" to add a new cell, or "delete" to remove

In insert mode, cell_number is the insertion index from 0 through the current
cell count. Index 0 prepends and index equal to the cell count appends.
The notebook must be read completely before it is edited.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'notebook_path' => [
                    'type' => 'string',
                    'description' => 'Absolute path to the Jupyter notebook file',
                ],
                'cell_number' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Cell index; in insert mode this is a position from 0 through cell count',
                ],
                'new_source' => [
                    'type' => 'string',
                    'description' => 'The new source content for replace or insert',
                ],
                'cell_type' => [
                    'type' => 'string',
                    'enum' => ['code', 'markdown'],
                    'description' => 'Cell type (defaults to current)',
                ],
                'edit_mode' => [
                    'type' => 'string',
                    'enum' => ['replace', 'insert', 'delete'],
                    'description' => 'Edit mode: replace, insert, or delete',
                ],
            ],
            'required' => ['notebook_path', 'cell_number'],
            'allOf' => [
                [
                    'if' => [
                        'required' => ['edit_mode'],
                        'properties' => [
                            'edit_mode' => ['enum' => ['delete']],
                        ],
                    ],
                    'then' => new \stdClass,
                    'else' => [
                        'required' => ['new_source'],
                    ],
                ],
            ],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $validationError = $this->validateDirectInput($input);
        if ($validationError !== null) {
            return ToolResult::error($validationError);
        }

        $path = $input['notebook_path'];
        if (! str_ends_with($path, '.ipynb')) {
            return ToolResult::error("File must be a .ipynb notebook: {$path}");
        }
        if (! is_file($path)) {
            return ToolResult::error("Notebook not found: {$path}");
        }

        $revisionError = $context->fileRevisionError($path);
        if ($revisionError !== null) {
            return ToolResult::error($revisionError);
        }
        $revision = $context->getFileRevision($path);
        if ($revision === null) {
            return ToolResult::error("Read tool first: {$path} must be read before writing.");
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ToolResult::error("Failed to read notebook file: {$path}");
        }

        try {
            $notebook = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ToolResult::error("Failed to parse notebook JSON: {$path}: {$e->getMessage()}");
        }
        if (! $notebook instanceof \stdClass
            || ! property_exists($notebook, 'cells')
            || ! is_array($notebook->cells)) {
            return ToolResult::error("Notebook file does not contain a valid cells array: {$path}");
        }

        $shapeError = $this->normalizeNotebookShape($notebook);
        if ($shapeError !== null) {
            return ToolResult::error("Invalid notebook structure: {$shapeError}");
        }

        $editMode = $input['edit_mode'] ?? 'replace';
        $cellNumber = $input['cell_number'] ?? 0;
        $cellType = $input['cell_type'] ?? null;
        $newSource = $input['new_source'] ?? '';
        $cells = &$notebook->cells;

        if ($editMode === 'delete') {
            if (! array_key_exists($cellNumber, $cells)) {
                return $this->missingCellResult($cellNumber, count($cells));
            }
            array_splice($cells, $cellNumber, 1);
        } elseif ($editMode === 'insert') {
            if ($cellNumber > count($cells)) {
                return ToolResult::error(
                    "Insertion index {$cellNumber} is out of range (notebook has ".count($cells).' cells)',
                );
            }

            $newCell = $this->newCell(
                $cellType ?? 'code',
                $newSource,
                $this->requiresCellIds($notebook),
            );
            array_splice($cells, $cellNumber, 0, [$newCell]);
        } else {
            if (! array_key_exists($cellNumber, $cells)) {
                return $this->missingCellResult($cellNumber, count($cells));
            }
            if (! $cells[$cellNumber] instanceof \stdClass) {
                return ToolResult::error("Cell {$cellNumber} is not a valid notebook cell object.");
            }

            $type = $cellType ?? $cells[$cellNumber]->cell_type ?? 'code';
            $cells[$cellNumber]->cell_type = $type;
            $cells[$cellNumber]->source = $this->sourceToLines($newSource);
            $this->normalizeCellForType($cells[$cellNumber], $type);
        }

        try {
            $json = json_encode(
                $notebook,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT
                    | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            return ToolResult::error('Failed to encode notebook JSON: '.$e->getMessage());
        }

        $historyManager = $this->historyManager?->forSession($context->sessionId);
        try {
            $this->atomicWriter->write(
                $path,
                $json,
                $revision,
                $historyManager === null
                    ? null
                    : static function (string $target) use ($historyManager): void {
                        $historyManager->recordBefore($target);
                    },
            );
        } catch (FileConflictException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\RuntimeException $e) {
            return ToolResult::error("Failed to write notebook file: {$path}: {$e->getMessage()}");
        }

        $context->recordFileRead($path, $json);
        $message = match ($editMode) {
            'delete' => "Deleted cell {$cellNumber}",
            'insert' => "Inserted new cell at index {$cellNumber}",
            default => "Replaced cell {$cellNumber}",
        };

        return ToolResult::success(
            "{$message} in ".basename($path).' ('.count($cells).' cells total)',
        );
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }

    private function validateDirectInput(array $input): ?string
    {
        if (! is_string($input['notebook_path'] ?? null)
            || $input['notebook_path'] === '') {
            return 'The notebook_path field is required.';
        }

        $editMode = $input['edit_mode'] ?? 'replace';
        if (! is_string($editMode)
            || ! in_array($editMode, ['replace', 'insert', 'delete'], true)) {
            return 'The edit_mode field must be one of: replace, insert, delete.';
        }

        if (! array_key_exists('cell_number', $input)) {
            return 'The cell_number field is required.';
        }
        if (! is_int($input['cell_number']) || $input['cell_number'] < 0) {
            return 'The cell_number field must be a non-negative integer.';
        }
        if ($editMode !== 'delete' && ! is_string($input['new_source'] ?? null)) {
            return 'The new_source field is required for replace and insert modes.';
        }
        if (array_key_exists('cell_type', $input)
            && ! in_array($input['cell_type'], ['code', 'markdown'], true)) {
            return 'The cell_type field must be one of: code, markdown.';
        }

        return null;
    }

    private function normalizeNotebookShape(\stdClass $notebook): ?string
    {
        if (! is_int($notebook->nbformat ?? null)
            || ! is_int($notebook->nbformat_minor ?? null)) {
            return 'nbformat and nbformat_minor must be integers.';
        }
        if (! property_exists($notebook, 'metadata')
            || $notebook->metadata === []) {
            $notebook->metadata = new \stdClass;
        } elseif (! $notebook->metadata instanceof \stdClass) {
            return 'metadata must be an object.';
        }

        $requiresIds = $this->requiresCellIds($notebook);
        foreach ($notebook->cells as $index => $cell) {
            if (! $cell instanceof \stdClass) {
                return "cell {$index} must be an object.";
            }
            if (! is_string($cell->cell_type ?? null)
                || ! in_array($cell->cell_type, ['code', 'markdown', 'raw'], true)) {
                return "cell {$index} has an invalid cell_type.";
            }
            if (! property_exists($cell, 'metadata') || $cell->metadata === []) {
                $cell->metadata = new \stdClass;
            } elseif (! $cell->metadata instanceof \stdClass) {
                return "cell {$index} metadata must be an object.";
            }
            if (! is_string($cell->source ?? null) && ! is_array($cell->source ?? null)) {
                return "cell {$index} source must be a string or array.";
            }
            if ($requiresIds
                && (! is_string($cell->id ?? null)
                    || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cell->id) !== 1)) {
                $cell->id = $this->newCellId();
            }

            $this->normalizeCellForType($cell, $cell->cell_type);
        }

        return null;
    }

    private function normalizeCellForType(\stdClass $cell, string $type): void
    {
        if ($type === 'code') {
            unset($cell->attachments);
            if (! property_exists($cell, 'execution_count')) {
                $cell->execution_count = null;
            }
            if (! property_exists($cell, 'outputs') || ! is_array($cell->outputs)) {
                $cell->outputs = [];
            }

            return;
        }

        unset($cell->execution_count, $cell->outputs);
        if (property_exists($cell, 'attachments') && $cell->attachments === []) {
            $cell->attachments = new \stdClass;
        }
    }

    private function newCell(string $type, string $source, bool $requiresId): \stdClass
    {
        $cell = new \stdClass;
        $cell->cell_type = $type;
        $cell->metadata = new \stdClass;
        $cell->source = $this->sourceToLines($source);
        if ($requiresId) {
            $cell->id = $this->newCellId();
        }
        $this->normalizeCellForType($cell, $type);

        return $cell;
    }

    private function requiresCellIds(\stdClass $notebook): bool
    {
        return is_int($notebook->nbformat ?? null)
            && is_int($notebook->nbformat_minor ?? null)
            && ($notebook->nbformat > 4
                || ($notebook->nbformat === 4 && $notebook->nbformat_minor >= 5));
    }

    private function newCellId(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function missingCellResult(int $cellNumber, int $cellCount): ToolResult
    {
        return ToolResult::error(
            "Cell {$cellNumber} does not exist (notebook has {$cellCount} cells)",
        );
    }

    /**
     * Convert a source string into the nbformat line-array format.
     *
     * @return list<string>
     */
    private function sourceToLines(string $source): array
    {
        $lines = explode("\n", $source);
        $trailingNewline = $lines[array_key_last($lines)] === '';
        if ($trailingNewline) {
            array_pop($lines);
        }

        if ($lines === []) {
            return [''];
        }

        $last = array_key_last($lines);
        foreach ($lines as $index => &$line) {
            if ($index !== $last || $trailingNewline) {
                $line .= "\n";
            }
        }
        unset($line);

        return $lines;
    }
}
