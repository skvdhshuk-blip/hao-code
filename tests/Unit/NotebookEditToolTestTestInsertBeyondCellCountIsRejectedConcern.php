<?php

namespace Tests\Unit;

use HaoCode\Services\FileHistory\FileHistoryManager;
use HaoCode\Tools\Notebook\NotebookEditTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait NotebookEditToolTestTestInsertBeyondCellCountIsRejectedConcern
{

    public function test_insert_beyond_cell_count_is_rejected(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 4,
            'new_source' => 'too_far = True',
            'edit_mode' => 'insert',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('out of range', $result->output);
    }

    public function test_edit_requires_complete_prior_read(): void
    {
        $path = $this->makeNotebook();
        $freshContext = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'unread',
        );

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'blocked',
        ], $freshContext);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Read tool first', $result->output);
    }

    public function test_partial_read_does_not_authorize_edit(): void
    {
        $path = $this->makeNotebook();
        $raw = file_get_contents($path);
        $partialContext = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'partial',
        );
        $partialContext->recordFileRead(
            $path,
            substr($raw, 0, 20),
            offset: 1,
            limit: 1,
            isPartialView: true,
        );

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'blocked',
        ], $partialContext);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('complete file', $result->output);
    }

    public function test_external_change_after_read_is_preserved(): void
    {
        $path = $this->makeNotebook();
        $external = str_replace('# Section', '# External', file_get_contents($path));
        file_put_contents($path, $external);

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'agent overwrite',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('changed since it was read', $result->output);
        $this->assertSame($external, file_get_contents($path));
    }

    public function test_type_change_preserves_object_shape_and_removes_code_fields(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => '# Markdown',
            'cell_type' => 'markdown',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path));
        $this->assertFalse($result->isError, $result->output);
        $this->assertInstanceOf(\stdClass::class, $notebook->metadata);
        $this->assertInstanceOf(\stdClass::class, $notebook->cells[0]->metadata);
        $this->assertFalse(property_exists($notebook->cells[0], 'execution_count'));
        $this->assertFalse(property_exists($notebook->cells[0], 'outputs'));
    }

    public function test_atomic_write_preserves_notebook_mode_and_refreshes_revision(): void
    {
        $path = $this->makeNotebook();
        chmod($path, 0755);
        $this->context->recordFileRead($path, file_get_contents($path));

        $first = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'first = True',
        ], $this->context);
        $second = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'second = True',
        ], $this->context);

        clearstatcache(true, $path);
        $this->assertFalse($first->isError, $first->output);
        $this->assertFalse($second->isError, $second->output);
        $this->assertSame(0755, fileperms($path) & 0777);
        $this->assertContains(
            'second = True',
            json_decode(file_get_contents($path), true)['cells'][0]['source'],
        );
    }

    public function test_history_snapshot_uses_tool_context_session(): void
    {
        $historyRoot = $this->tmpDir.'/.history';
        $history = new FileHistoryManager(null, $historyRoot);
        $tool = new NotebookEditTool(null, $history);
        $path = $this->makeNotebook();

        $result = $tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'changed = True',
        ], $this->context);

        $snapshots = $history->forSession('test')->getAllSnapshots();
        $this->assertFalse($result->isError, $result->output);
        $this->assertCount(1, $snapshots);
        $this->assertContains(
            'print("hello")',
            json_decode($snapshots[0]->content, true)['cells'][0]['source'],
        );
    }

    public function test_is_read_only_returns_false(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
    }

    public function test_name_is_notebook_edit(): void
    {
        $this->assertSame('NotebookEdit', $this->tool->name());
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
