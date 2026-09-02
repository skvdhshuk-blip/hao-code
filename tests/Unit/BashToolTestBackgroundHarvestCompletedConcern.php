<?php

namespace Tests\Unit;

use HaoCode\Tools\Bash\BashTool;

trait BashToolTestBackgroundHarvestCompletedConcern
{
    public function test_harvest_collects_a_small_output_once_and_reaps_the_task(): void
    {
        $result = $this->tool->call([
            'command' => 'printf harvested',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->awaitBackgroundStatusFileFor($taskId);

        $harvested = BashTool::harvestCompleted('test-session');
        $mine = $this->pickTask($harvested, $taskId);

        $this->assertNotNull($mine, 'A finished background task must be harvested');
        $this->assertNotNull($mine['result']);
        $this->assertStringContainsString('harvested', $mine['result']->output);

        // Delivered once: the task is gone, so a later fetch cannot repeat it.
        $this->assertNull($this->pickTask(BashTool::harvestCompleted('test-session'), $taskId));
        $again = BashTool::checkTask($taskId, 'test-session');
        $this->assertNotNull($again);
        $this->assertTrue($again->isError);
        $this->assertStringContainsString('Unknown background task', $again->output);
    }

    public function test_harvest_only_flags_a_large_output_and_leaves_it_fetchable(): void
    {
        $result = $this->tool->call([
            // Comfortably over the 8 KB inline limit.
            'command' => 'printf %020000d 0',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->awaitBackgroundStatusFileFor($taskId);

        $mine = $this->pickTask(BashTool::harvestCompleted('test-session'), $taskId);
        $this->assertNotNull($mine);
        $this->assertNull($mine['result'], 'A large output must be flagged, not inlined');
        $this->assertGreaterThan(8000, $mine['outputBytes']);

        // Flagged once ...
        $this->assertNull($this->pickTask(BashTool::harvestCompleted('test-session'), $taskId));

        // ... and still retrievable on demand, exactly once.
        $fetched = BashTool::checkTask($taskId, 'test-session');
        $this->assertNotNull($fetched);
        $this->assertFalse($fetched->isError, $fetched->output);
        $this->assertStringContainsString('0000', $fetched->output);
    }

    public function test_harvest_and_check_ignore_tasks_owned_by_another_session(): void
    {
        $result = $this->tool->call([
            'command' => 'printf mine',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->awaitBackgroundStatusFileFor($taskId);

        $this->assertNull($this->pickTask(BashTool::harvestCompleted('other-session'), $taskId));

        $foreign = BashTool::checkTask($taskId, 'other-session');
        $this->assertNotNull($foreign);
        $this->assertTrue($foreign->isError);
        $this->assertStringContainsString('Unknown background task', $foreign->output);

        // The owner can still collect it.
        $this->assertNotNull($this->pickTask(BashTool::harvestCompleted('test-session'), $taskId));
    }

    public function test_a_running_task_is_not_harvested(): void
    {
        $result = $this->tool->call([
            'command' => 'sleep 5',
            'run_in_background' => true,
            'timeout' => 10000,
        ], $this->context);
        $taskId = $result->metadata['taskId'] ?? null;
        $this->assertIsString($taskId);

        $this->assertNull($this->pickTask(BashTool::harvestCompleted('test-session'), $taskId));

        BashTool::checkTask($taskId, 'test-session');
    }

    public function test_start_message_points_at_bash_output(): void
    {
        $result = $this->tool->call([
            'command' => 'printf ok',
            'run_in_background' => true,
            'timeout' => 5000,
        ], $this->context);

        $this->assertStringContainsString('BashOutput', $result->output);
        $this->assertStringNotContainsString('BashTool::checkTask', $result->output);

        $taskId = $result->metadata['taskId'] ?? null;
        if (is_string($taskId)) {
            $this->awaitBackgroundStatusFileFor($taskId);
            BashTool::checkTask($taskId, 'test-session');
        }
    }

    /**
     * @param  list<array{taskId: string, command: string, result: ?\HaoCode\Tools\ToolResult, outputBytes: int}>  $harvested
     * @return array{taskId: string, command: string, result: ?\HaoCode\Tools\ToolResult, outputBytes: int}|null
     */
    private function pickTask(array $harvested, string $taskId): ?array
    {
        foreach ($harvested as $task) {
            if ($task['taskId'] === $taskId) {
                return $task;
            }
        }

        return null;
    }

    private function awaitBackgroundStatusFileFor(string $taskId): void
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
