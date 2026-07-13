<?php

namespace HaoCode\Services\Agent;

class BackgroundAgentManager
{
    private const RESULT_LIMIT = 100000;

    public function __construct(
        private readonly ?string $storagePath = null,
    ) {
        $this->ensureStoragePath();
    }

    public function create(
        string $id,
        string $prompt,
        string $agentType,
        ?string $description = null,
        ?int $pid = null,
    ): array {
        $state = [
            'id' => $id,
            'prompt' => $prompt,
            'description' => $description,
            'agent_type' => $agentType,
            'pid' => $pid,
            'status' => 'pending',
            'pending_messages' => 0,
            'stop_requested' => false,
            'last_message_at' => null,
            'last_result' => null,
            'error' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->writeJson($this->statePath($id), $state);
        $this->writeJson($this->mailboxPath($id), []);

        return $state;
    }

    public function delete(string $id): void
    {
        @unlink($this->statePath($id));
        @unlink($this->mailboxPath($id));
    }

    public function get(string $id): ?array
    {
        $path = $this->statePath($id);
        if (! is_file($path)) {
            return null;
        }

        return $this->readJson($path) ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $states = [];

        foreach (glob($this->storageRoot().'/*.state.json') ?: [] as $path) {
            $state = $this->readJson($path);
            if (is_array($state)) {
                $states[] = $state;
            }
        }

        usort($states, fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        return $states;
    }

    public function attachProcess(string $id, int $pid): ?array
    {
        return $this->mutateState($id, function (array $state) use ($pid) {
            $state['pid'] = $pid;
            if (($state['status'] ?? null) === 'pending') {
                $state['status'] = 'running';
            }

            return $state;
        });
    }

    public function markRunning(string $id): ?array
    {
        return $this->mutateState($id, function (array $state) {
            $state['status'] = 'running';

            return $state;
        });
    }

    public function markWaitingForInput(string $id, \HaoCode\Sdk\HumanInterrupt $interrupt): ?array
    {
        return $this->mutateState($id, function (array $state) use ($interrupt) {
            $state['status'] = 'waiting_for_input';
            $state['child_session_id'] = $interrupt->sessionId;
            $state['pending_interrupt'] = $interrupt->toArray();
            $state['error'] = null;

            return $state;
        });
    }

    public function recordResult(string $id, string $result): ?array
    {
        return $this->mutateState($id, function (array $state) use ($result) {
            $state['last_result'] = $this->truncate($result, self::RESULT_LIMIT);
            $state['status'] = 'idle';
            $state['error'] = null;

            return $state;
        });
    }

    public function markCompleted(string $id, ?string $result = null): ?array
    {
        return $this->mutateState($id, function (array $state) use ($result) {
            $state['status'] = 'completed';
            if ($result !== null) {
                $state['last_result'] = $this->truncate($result, self::RESULT_LIMIT);
            }

            return $state;
        });
    }

    public function markError(string $id, string $error): ?array
    {
        return $this->mutateState($id, function (array $state) use ($error) {
            $state['status'] = 'error';
            $state['error'] = $this->truncate($error, 4000);

            return $state;
        });
    }

    public function markDead(string $id, ?string $error = null): ?array
    {
        return $this->mutateState($id, function (array $state) use ($error) {
            $state['status'] = 'dead';
            if ($error !== null && ($state['error'] ?? null) === null) {
                $state['error'] = $this->truncate($error, 4000);
            }

            return $state;
        });
    }

    /** Refresh a process-backed state and persist a terminal dead status. */
    public function refreshStatus(string $id): ?array
    {
        $state = $this->get($id);
        if ($state === null) {
            return null;
        }

        $status = $state['status'] ?? 'unknown';
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0 || ! in_array($status, ['pending', 'running', 'idle'], true)) {
            return $state;
        }

        if (! function_exists('posix_kill')) {
            return $state;
        }

        if (@posix_kill($pid, 0)) {
            return $state;
        }

        return $this->markDead($id, 'Background agent process is no longer running.');
    }

    public function requestStop(string $id): ?array
    {
        return $this->mutateState($id, function (array $state) {
            $state['stop_requested'] = true;

            return $state;
        });
    }

    public function isStopRequested(string $id): bool
    {
        return (bool) ($this->get($id)['stop_requested'] ?? false);
    }

    public function queueMessage(string $id, string $message, ?string $summary = null, string $from = 'controller'): ?array
    {
        if ($this->get($id) === null) {
            return null;
        }

        $entry = [
            'from' => $from,
            'summary' => $summary,
            'message' => $message,
            'created_at' => time(),
        ];

        $messageCount = $this->withLockedMailbox($id, function (array &$messages) use ($entry) {
            $messages[] = $entry;

            return count($messages);
        });

        $state = $this->mutateState($id, function (array $state) use ($messageCount) {
            $state['pending_messages'] = $messageCount;
            $state['last_message_at'] = time();

            return $state;
        });

        if ($state === null) {
            return null;
        }

        return $entry + ['pending_messages' => $messageCount];
    }

    public function popNextMessage(string $id): ?array
    {
        if ($this->get($id) === null) {
            return null;
        }

        $popped = null;
        $messageCount = $this->withLockedMailbox($id, function (array &$messages) use (&$popped) {
            $popped = array_shift($messages) ?: null;

            return count($messages);
        });

        if ($popped === null) {
            return null;
        }

        $this->mutateState($id, function (array $state) use ($messageCount) {
            $state['pending_messages'] = $messageCount;

            return $state;
        });

        return $popped;
    }

    private function mutateState(string $id, callable $callback): ?array
    {
        $path = $this->statePath($id);
        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open background agent state for {$id}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock background agent state for {$id}");
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $current = json_decode($raw === false || $raw === '' ? 'null' : $raw, true);
            if (! is_array($current)) {
                return null;
            }

            $next = $callback($current);
            if (! is_array($next)) {
                $next = $current;
            }

            $next['updated_at'] = time();
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode(
                $next,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            fflush($handle);

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withLockedMailbox(string $id, callable $callback): mixed
    {
        $path = $this->mailboxPath($id);
        if (! is_file($path)) {
            $this->writeJson($path, []);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open mailbox for {$id}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock mailbox for {$id}");
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $messages = json_decode($raw === false || $raw === '' ? '[]' : $raw, true);
            if (! is_array($messages)) {
                $messages = [];
            }

            $result = $callback($messages);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function readJson(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function statePath(string $id): string
    {
        return $this->storageRoot()."/{$id}.state.json";
    }

    private function mailboxPath(string $id): string
    {
        return $this->storageRoot()."/{$id}.mailbox.json";
    }

    private function storageRoot(): string
    {
        return $this->storagePath ?? sys_get_temp_dir().'/haocode_background_agents';
    }

    private function ensureStoragePath(): void
    {
        if (! is_dir($this->storageRoot())) {
            mkdir($this->storageRoot(), 0755, true);
        }
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }
}
