<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\Bash\BashOutputTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class BashOutputToolTest extends TestCase
{
    private BashOutputTool $tool;

    private BashTool $bash;

    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new BashOutputTool;
        $this->bash = new BashTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'bashoutput-session',
        );
    }

    public function test_it_is_read_only_but_not_concurrency_safe(): void
    {
        // Read-only so plan mode allows it; serial because harvesting unlinks the
        // output file and mutates process-local state a forked child would lose.
        $this->assertTrue($this->tool->isReadOnly([]));
        $this->assertFalse($this->tool->isConcurrencySafe([]));
    }

    public function test_schema_requires_a_task_id(): void
    {
        $schema = $this->tool->inputSchema()->toJsonSchema();

        $this->assertContains('task_id', $schema['required']);
    }

    public function test_missing_task_id_is_reported(): void
    {
        $result = $this->tool->call([], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('task_id is required', $result->output);
    }

    public function test_unknown_task_id_is_reported(): void
    {
        $result = $this->tool->call(['task_id' => 'bg_deadbeef'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown background task', $result->output);
    }

    public function test_it_returns_the_output_of_a_finished_command(): void
    {
        $started = $this->bash->call([
            'command' => 'printf fetched-by-tool',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $started->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->awaitStatusFile($taskId);

        $result = $this->tool->call(['task_id' => $taskId], $this->context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('fetched-by-tool', $result->output);
    }

    public function test_it_cannot_read_another_sessions_task(): void
    {
        $started = $this->bash->call([
            'command' => 'printf private',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $started->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->awaitStatusFile($taskId);

        $foreign = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'someone-else',
        );
        $result = $this->tool->call(['task_id' => $taskId], $foreign);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown background task', $result->output);

        BashTool::checkTask($taskId, 'bashoutput-session');
    }

    private function awaitStatusFile(string $taskId): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            $task = BashTool::listTasks()[$taskId] ?? null;
            $this->assertIsArray($task);
            if (is_file((string) ($task['statusFile'] ?? ''))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('Background task did not write a status file before the test deadline');
    }
}
