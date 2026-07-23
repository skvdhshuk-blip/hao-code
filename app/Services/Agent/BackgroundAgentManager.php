<?php

namespace HaoCode\Services\Agent;

use HaoCode\Support\StateIdentifier;

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
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock($id, LOCK_EX, function () use ($id, $prompt, $agentType, $description, $pid): array {
            if (is_file($this->statePath($id)) || is_file($this->mailboxPath($id))) {
                throw new \InvalidArgumentException("Background agent '{$id}' already exists.");
            }

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

            try {
                $this->writeJsonAtomically($this->statePath($id), $state);
                $this->writeJsonAtomically($this->mailboxPath($id), []);
            } catch (\Throwable $e) {
                @unlink($this->statePath($id));
                @unlink($this->mailboxPath($id));
                throw $e;
            }

            return $state;
        });
    }

    public function delete(string $id): void
    {
        $id = StateIdentifier::backgroundAgentId($id);
        $this->withAgentLock($id, LOCK_EX, function () use ($id): void {
            @unlink($this->statePath($id));
            @unlink($this->mailboxPath($id));
        });
    }

    public function get(string $id): ?array
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock($id, LOCK_SH, function () use ($id): ?array {
            return $this->readJson($this->statePath($id));
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $states = [];

        foreach (glob($this->storageRoot().'/*.state.json') ?: [] as $path) {
            $file = basename($path);
            $id = substr($file, 0, -strlen('.state.json'));
            try {
                $state = $this->get($id);
            } catch (\InvalidArgumentException) {
                continue;
            }
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
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock($id, LOCK_EX, function () use ($id, $message, $summary, $from): ?array {
            $state = $this->readJson($this->statePath($id));
            if ($state === null) {
                return null;
            }

            $entry = [
                'from' => $from,
                'summary' => $summary,
                'message' => $message,
                'created_at' => time(),
            ];
            $messages = $this->readJson($this->mailboxPath($id)) ?? [];
            $messages[] = $entry;
            $messageCount = count($messages);

            $state['pending_messages'] = $messageCount;
            $state['last_message_at'] = time();
            $state['updated_at'] = time();

            $this->writeJsonAtomically($this->mailboxPath($id), $messages);
            $this->writeJsonAtomically($this->statePath($id), $state);

            return $entry + ['pending_messages' => $messageCount];
        });
    }

    public function popNextMessage(string $id): ?array
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock($id, LOCK_EX, function () use ($id): ?array {
            $state = $this->readJson($this->statePath($id));
            if ($state === null) {
                return null;
            }

            $messages = $this->readJson($this->mailboxPath($id)) ?? [];
            $popped = array_shift($messages) ?: null;
            if ($popped === null) {
                return null;
            }

            $state['pending_messages'] = count($messages);
            $state['updated_at'] = time();
            $this->writeJsonAtomically($this->mailboxPath($id), $messages);
            $this->writeJsonAtomically($this->statePath($id), $state);

            return $popped;
        });
    }

    private function mutateState(string $id, callable $callback): ?array
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock($id, LOCK_EX, function () use ($id, $callback): ?array {
            $current = $this->readJson($this->statePath($id));
            if ($current === null) {
                return null;
            }

            $next = $callback($current);
            if (! is_array($next)) {
                $next = $current;
            }

            $next['updated_at'] = time();
            $this->writeJsonAtomically($this->statePath($id), $next);

            return $next;
        });
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJsonAtomically(string $path, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $temporary = tempnam($this->storageRoot(), '.haocode-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary state file.');
        }

        try {
            $written = file_put_contents($temporary, $json);
            if ($written !== strlen($json)) {
                throw new \RuntimeException("Unable to write state file: {$path}");
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException("Unable to replace state file: {$path}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function withAgentLock(string $id, int $operation, callable $callback): mixed
    {
        $handle = fopen($this->lockPath($id), 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open background agent lock for {$id}");
        }

        try {
            if (! flock($handle, $operation)) {
                throw new \RuntimeException("Unable to lock background agent state for {$id}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function statePath(string $id): string
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->storageRoot()."/{$id}.state.json";
    }

    private function mailboxPath(string $id): string
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->storageRoot()."/{$id}.mailbox.json";
    }

    private function lockPath(string $id): string
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->storageRoot()."/{$id}.lock";
    }

    private function storageRoot(): string
    {
        return $this->storagePath ?? sys_get_temp_dir().'/haocode_background_agents';
    }

    private function ensureStoragePath(): void
    {
        if (! is_dir($this->storageRoot()) && ! mkdir($this->storageRoot(), 0755, true) && ! is_dir($this->storageRoot())) {
            throw new \RuntimeException("Unable to create background agent storage: {$this->storageRoot()}");
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
