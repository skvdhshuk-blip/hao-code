<?php

namespace HaoCode\Services\Task;

use HaoCode\Support\StateIdentifier;

/**
 * Manages background task lifecycle with persistent state.
 */
class TaskManager
{
    private readonly string $storagePath;

    /** @var array<string, Task> */
    private array $tasks = [];

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = rtrim($storagePath ?? sys_get_temp_dir().'/haocode_tasks', '/');
        if (! is_dir($this->storagePath) && ! mkdir($this->storagePath, 0755, true) && ! is_dir($this->storagePath)) {
            throw new \RuntimeException("Unable to create task storage: {$this->storagePath}");
        }

        // Persist the existing 24-hour cleanup under the same lock used by
        // every read-modify-write operation.
        $this->mutateTasks(static fn (array &$tasks) => null);
    }

    public function create(string $subject, string $activeForm, ?string $description = null): Task
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return $this->createWithId(
                    id: 'task_'.bin2hex(random_bytes(4)),
                    subject: $subject,
                    activeForm: $activeForm,
                    description: $description,
                );
            } catch (\InvalidArgumentException $e) {
                if (! str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate a unique task ID.');
    }

    public function createWithId(string $id, string $subject, string $activeForm, ?string $description = null): Task
    {
        $id = StateIdentifier::taskId($id);

        return $this->mutateTasks(function (array &$tasks) use ($id, $subject, $activeForm, $description): Task {
            if (isset($tasks[$id])) {
                throw new \InvalidArgumentException("Task '{$id}' already exists.");
            }

            $timestamp = time();
            $task = new Task(
                id: $id,
                subject: $subject,
                activeForm: $activeForm,
                description: $description,
                status: 'pending',
                createdAt: $timestamp,
                updatedAt: $timestamp,
            );
            $tasks[$id] = $task;

            return $task;
        });
    }

    public function get(string $id): ?Task
    {
        $id = StateIdentifier::taskId($id);
        $tasks = $this->readTasks();

        return $tasks[$id] ?? null;
    }

    /**
     * @return Task[]
     */
    public function list(?string $status = null): array
    {
        $tasks = array_values($this->readTasks());
        if ($status !== null && $status !== '') {
            $tasks = array_values(array_filter($tasks, fn (Task $task): bool => $task->status === $status));
        }

        return $tasks;
    }

    public function update(string $id, string $status, ?string $result = null): ?Task
    {
        $id = StateIdentifier::taskId($id);

        return $this->mutateTasks(function (array &$tasks) use ($id, $status, $result): ?Task {
            $task = $tasks[$id] ?? null;
            if ($task === null) {
                return null;
            }

            $tasks[$id] = $task->with(
                status: $status,
                result: $result,
                updatedAt: time(),
            );

            return $tasks[$id];
        });
    }

    public function stop(string $id): ?Task
    {
        return $this->update($id, 'completed', 'Stopped by user');
    }

    public function remove(string $id): bool
    {
        $id = StateIdentifier::taskId($id);

        return $this->mutateTasks(function (array &$tasks) use ($id): bool {
            if (! isset($tasks[$id])) {
                return false;
            }

            unset($tasks[$id]);

            return true;
        });
    }

    /**
     * @return array<string, Task>
     */
    private function readTasks(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            $tasks = $this->readTasksFile();
            $this->removeExpired($tasks);
            $this->tasks = $tasks;

            return $tasks;
        });
    }

    private function mutateTasks(callable $callback): mixed
    {
        return $this->withLock(LOCK_EX, function () use ($callback): mixed {
            $tasks = $this->readTasksFile();
            $this->removeExpired($tasks);
            $result = $callback($tasks);
            $this->persistTasks($tasks);
            $this->tasks = $tasks;

            return $result;
        });
    }

    /**
     * @return array<string, Task>
     */
    private function readTasksFile(): array
    {
        $file = $this->storagePath.'/tasks.json';
        if (! is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read task state.');
        }
        if ($raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Task state is not valid JSON.', previous: $e);
        }
        if (! is_array($data)) {
            throw new \RuntimeException('Task state must be a JSON object.');
        }

        $tasks = [];
        foreach ($data as $id => $taskData) {
            if (is_array($taskData)) {
                $tasks[(string) $id] = Task::fromArray($taskData);
            }
        }

        return $tasks;
    }

    /**
     * @param array<string, Task> $tasks
     */
    private function removeExpired(array &$tasks): void
    {
        $cutoff = time() - 86400;
        foreach ($tasks as $id => $task) {
            if ($task->createdAt < $cutoff) {
                unset($tasks[$id]);
            }
        }
    }

    /**
     * @param array<string, Task> $tasks
     */
    private function persistTasks(array $tasks): void
    {
        $data = [];
        foreach ($tasks as $id => $task) {
            $data[$id] = $task->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = tempnam($this->storagePath, '.haocode-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary task state file.');
        }

        try {
            $written = file_put_contents($temporary, $json);
            if ($written !== strlen($json)) {
                throw new \RuntimeException('Unable to write task state.');
            }
            if (! rename($temporary, $this->storagePath.'/tasks.json')) {
                throw new \RuntimeException('Unable to replace task state.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        $handle = fopen($this->storagePath.'/tasks.lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open task state lock.');
        }

        try {
            if (! flock($handle, $operation)) {
                throw new \RuntimeException('Unable to lock task state.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
