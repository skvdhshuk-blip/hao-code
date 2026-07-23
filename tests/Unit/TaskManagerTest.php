<?php

namespace Tests\Unit;

use HaoCode\Services\Task\Task;
use HaoCode\Services\Task\TaskManager;
use PHPUnit\Framework\TestCase;

class TaskManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Use a unique temp directory per test to isolate state
        $this->tempDir = sys_get_temp_dir() . '/haocode_tasks_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    private function makeManager(): TaskManager
    {
        return new TaskManager($this->tempDir);
    }

    // ─── create ───────────────────────────────────────────────────────────

    public function test_create_returns_task_with_correct_fields(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Fix bug', 'Fixing bug', 'Optional description');

        $this->assertStringStartsWith('task_', $task->id);
        $this->assertSame('Fix bug', $task->subject);
        $this->assertSame('Fixing bug', $task->activeForm);
        $this->assertSame('Optional description', $task->description);
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->result);
        $this->assertGreaterThan(0, $task->createdAt);
    }

    public function test_create_without_description(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Task', 'Tasking');

        $this->assertNull($task->description);
    }

    public function test_create_with_id_uses_the_supplied_identifier(): void
    {
        $manager = $this->makeManager();
        $task = $manager->createWithId('agent_demo', 'Task', 'Tasking');

        $this->assertSame('agent_demo', $task->id);
        $this->assertSame('agent_demo', $manager->get('agent_demo')?->id);
    }

    public function test_create_with_id_rejects_duplicates(): void
    {
        $manager = $this->makeManager();
        $manager->createWithId('agent_demo', 'First', 'Working');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Task 'agent_demo' already exists.");

        $manager->createWithId('agent_demo', 'Second', 'Working');
    }

    public function test_rejects_unsafe_task_ids(): void
    {
        $manager = $this->makeManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID.');

        $manager->createWithId('../escape', 'Task', 'Working');
    }

    public function test_created_task_is_retrievable_via_get(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Do X', 'Doing X');

        $retrieved = $manager->get($task->id);

        $this->assertNotNull($retrieved);
        $this->assertSame($task->id, $retrieved->id);
    }

    // ─── get ──────────────────────────────────────────────────────────────

    public function test_get_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->get('nonexistent_task'));
    }

    // ─── list ─────────────────────────────────────────────────────────────

    public function test_list_returns_all_tasks_without_filter(): void
    {
        $manager = $this->makeManager();
        $manager->create('Task A', 'Doing A');
        $manager->create('Task B', 'Doing B');

        $tasks = $manager->list();

        $this->assertCount(2, $tasks);
    }

    public function test_list_filters_by_status(): void
    {
        $manager = $this->makeManager();
        $t1 = $manager->create('Task 1', 'Doing 1');
        $manager->create('Task 2', 'Doing 2');
        $manager->update($t1->id, 'completed');

        $pending = $manager->list('pending');
        $completed = $manager->list('completed');

        $this->assertCount(1, $pending);
        $this->assertCount(1, $completed);
        $this->assertSame('completed', $completed[0]->status);
    }

    public function test_list_returns_empty_array_when_no_tasks(): void
    {
        $manager = $this->makeManager();
        $this->assertSame([], $manager->list());
    }

    // ─── update ───────────────────────────────────────────────────────────

    public function test_update_changes_status(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');

        $updated = $manager->update($task->id, 'in_progress');

        $this->assertNotNull($updated);
        $this->assertSame('in_progress', $updated->status);
    }

    public function test_update_sets_result(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');

        $updated = $manager->update($task->id, 'completed', 'All done!');

        $this->assertSame('All done!', $updated->result);
    }

    public function test_update_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->update('nonexistent', 'completed'));
    }

    public function test_update_sets_updated_at_timestamp(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');
        $beforeUpdate = time();

        $updated = $manager->update($task->id, 'in_progress');

        $this->assertGreaterThanOrEqual($beforeUpdate, $updated->updatedAt);
    }

    public function test_transition_does_not_regress_completed_task_to_in_progress(): void
    {
        $manager = $this->makeManager();
        $task = $manager->createWithId('agent_demo', 'Agent', 'Running');
        $manager->update($task->id, 'completed', 'Finished first');

        $result = $manager->transition(
            $task->id,
            ['pending'],
            'in_progress',
            'Late parent update',
        );

        $this->assertSame('completed', $result->status);
        $this->assertSame('Finished first', $result->result);
        $this->assertSame('completed', $manager->get($task->id)->status);
    }

    // ─── stop ─────────────────────────────────────────────────────────────

    public function test_stop_marks_task_as_completed_with_message(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Long task', 'Running');

        $stopped = $manager->stop($task->id);

        $this->assertNotNull($stopped);
        $this->assertSame('completed', $stopped->status);
        $this->assertSame('Stopped by user', $stopped->result);
    }

    public function test_stop_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->stop('no_such_task'));
    }

    // ─── remove ───────────────────────────────────────────────────────────

    public function test_remove_deletes_task(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Temp task', 'Temping');

        $result = $manager->remove($task->id);

        $this->assertTrue($result);
        $this->assertNull($manager->get($task->id));
    }

    public function test_remove_returns_false_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertFalse($manager->remove('no_such_task'));
    }

    // ─── Persistence ──────────────────────────────────────────────────────

    public function test_tasks_persist_to_json_file(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Persist me', 'Persisting');

        $this->assertFileExists($this->tempDir . '/tasks.json');

        $data = json_decode(file_get_contents($this->tempDir . '/tasks.json'), true);
        $this->assertArrayHasKey($task->id, $data);
        $this->assertSame('Persist me', $data[$task->id]['subject']);
    }

    public function test_tasks_survive_across_manager_instances(): void
    {
        $manager1 = $this->makeManager();
        $task = $manager1->create('Survive me', 'Surviving');

        $manager2 = $this->makeManager();

        $retrieved = $manager2->get($task->id);
        $this->assertNotNull($retrieved);
        $this->assertSame('Survive me', $retrieved->subject);
    }

    // ─── Auto-cleanup of old tasks ─────────────────────────────────────────

    public function test_tasks_older_than_24h_are_cleaned_on_load(): void
    {
        // Write a task file with an old createdAt
        $oldTime = time() - 90000; // 25 hours ago
        $data = [
            'task_old' => [
                'id' => 'task_old',
                'subject' => 'Old task',
                'activeForm' => 'Old',
                'description' => null,
                'status' => 'pending',
                'result' => null,
                'createdAt' => $oldTime,
                'updatedAt' => $oldTime,
            ],
        ];

        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/tasks.json', json_encode($data));

        $manager = $this->makeManager();

        $this->assertNull($manager->get('task_old'));
    }

    public function test_recent_tasks_are_not_cleaned(): void
    {
        $recentTime = time() - 3600; // 1 hour ago
        $data = [
            'task_recent' => [
                'id' => 'task_recent',
                'subject' => 'Recent task',
                'activeForm' => 'Recent',
                'description' => null,
                'status' => 'pending',
                'result' => null,
                'createdAt' => $recentTime,
                'updatedAt' => $recentTime,
            ],
        ];

        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/tasks.json', json_encode($data));

        $manager = $this->makeManager();

        $this->assertNotNull($manager->get('task_recent'));
    }

    public function test_concurrent_creates_do_not_lose_updates(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for the concurrency regression test.');
        }

        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = new TaskManager($this->tempDir);
                for ($index = 0; $index < 20; $index++) {
                    $manager->createWithId("worker{$worker}_{$index}", 'Concurrent task', 'Working');
                }
                exit(0);
            }

            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $this->assertCount(40, (new TaskManager($this->tempDir))->list());
    }
}
