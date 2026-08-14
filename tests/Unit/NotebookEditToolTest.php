<?php

namespace Tests\Unit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Tools\Notebook\NotebookEditTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class NotebookEditToolTest extends TestCase
{
    use NotebookEditToolTestSetUpConcern;
    use NotebookEditToolTestTestInsertBeyondCellCountIsRejectedConcern;

    private ToolUseContext $context;
    private NotebookEditTool $tool;
    private string $tmpDir;

    // ─── error cases ──────────────────────────────────────────────────────

    // ─── replace mode ─────────────────────────────────────────────────────

    // ─── delete mode ──────────────────────────────────────────────────────

    // ─── insert mode ──────────────────────────────────────────────────────

    // ─── source line format (nbformat compliance) ─────────────────────────

    // ─── isReadOnly ───────────────────────────────────────────────────────
}
