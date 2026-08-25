<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Support\StateIdentifier;

trait BackgroundAgentCapacityConcern
{
    public function create(
        string $id,
        string $prompt,
        string $agentType,
        ?string $description = null,
        ?int $pid = null,
        ?string $worktreePath = null,
        ?string $worktreeBranch = null,
        ?string $ownerRunId = null,
    ): array {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAdmissionLock(function () use (
            $id,
            $prompt,
            $agentType,
            $description,
            $pid,
            $worktreePath,
            $worktreeBranch,
            $ownerRunId,
        ): array {
            $active = $this->activeAgentCount($ownerRunId);
            if ($active >= $this->limits->maxActivePerRun) {
                throw new BackgroundAgentBusyException(
                    'active_agents',
                    $active + 1,
                    $this->limits->maxActivePerRun,
                );
            }

            return $this->stateStore->withLock($id, LOCK_EX, function () use (
                $id,
                $prompt,
                $agentType,
                $description,
                $pid,
                $worktreePath,
                $worktreeBranch,
                $ownerRunId,
            ): array {
                if (is_file($this->stateStore->statePath($id))
                    || is_file($this->stateStore->mailboxPath($id))) {
                    throw new \InvalidArgumentException("Background agent '{$id}' already exists.");
                }

                $state = [
                    'id' => $id,
                    'owner_run_id' => $ownerRunId,
                    'prompt' => $prompt,
                    'description' => $description,
                    'agent_type' => $agentType,
                    'pid' => $pid,
                    'process_start_time' => $pid !== null ? $this->processStartTime($pid) : null,
                    'process_token' => null,
                    'status' => 'pending',
                    'pending_messages' => 0,
                    'stop_requested' => false,
                    'last_message_at' => null,
                    'last_result' => null,
                    'error' => null,
                    'worktree_path' => $worktreePath,
                    'worktree_branch' => $worktreeBranch,
                    'worktree_retained' => $worktreePath !== null,
                    'worktree_cleanup_error' => null,
                    'created_at' => time(),
                    'updated_at' => time(),
                ];

                try {
                    $this->stateStore->write($this->stateStore->statePath($id), $state);
                    $this->stateStore->write($this->stateStore->mailboxPath($id), []);
                } catch (\Throwable $exception) {
                    @unlink($this->stateStore->statePath($id));
                    @unlink($this->stateStore->mailboxPath($id));
                    throw $exception;
                }

                return $state;
            });
        });
    }

    public function queueMessage(
        string $id,
        string $message,
        ?string $summary = null,
        string $from = 'controller',
    ): ?array {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->stateStore->withLock($id, LOCK_EX, function () use ($id, $message, $summary, $from): ?array {
            $state = $this->stateStore->read($this->stateStore->statePath($id));
            if ($state === null) {
                return null;
            }
            $status = $state['status'] ?? 'unknown';
            if ($status === 'waiting_for_input') {
                throw new \InvalidArgumentException(
                    "Background agent {$id} is waiting for human input; resume the interrupt first.",
                );
            }
            if (in_array($status, ['completed', 'error', 'dead'], true)) {
                throw new \InvalidArgumentException("Background agent {$id} is no longer running.");
            }

            $entry = [
                'from' => $from,
                'summary' => $summary,
                'message' => $message,
                'created_at' => time(),
            ];
            $entryBytes = $this->serializedBytes($entry);
            if ($entryBytes > $this->limits->messageMaxBytes) {
                throw new BackgroundAgentBusyException(
                    'message_bytes',
                    $entryBytes,
                    $this->limits->messageMaxBytes,
                );
            }

            $messages = $this->stateStore->read($this->stateStore->mailboxPath($id)) ?? [];
            $messageCount = count($messages) + 1;
            if ($messageCount > $this->limits->mailboxMaxMessages) {
                throw new BackgroundAgentBusyException(
                    'mailbox_messages',
                    $messageCount,
                    $this->limits->mailboxMaxMessages,
                );
            }
            $messages[] = $entry;
            $mailboxBytes = $this->serializedBytes($messages);
            if ($mailboxBytes > $this->limits->mailboxMaxBytes) {
                throw new BackgroundAgentBusyException(
                    'mailbox_bytes',
                    $mailboxBytes,
                    $this->limits->mailboxMaxBytes,
                );
            }

            $state['pending_messages'] = $messageCount;
            $state['last_message_at'] = time();
            $state['updated_at'] = time();
            $this->stateStore->write($this->stateStore->mailboxPath($id), $messages);
            $this->stateStore->write($this->stateStore->statePath($id), $state);

            return $entry + ['pending_messages' => $messageCount];
        });
    }

    public function popNextMessage(string $id): ?array
    {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->stateStore->withLock($id, LOCK_EX, function () use ($id): ?array {
            $state = $this->stateStore->read($this->stateStore->statePath($id));
            if ($state === null) {
                return null;
            }
            $messages = $this->stateStore->read($this->stateStore->mailboxPath($id)) ?? [];
            $popped = array_shift($messages) ?: null;
            if ($popped === null) {
                return null;
            }

            $state['pending_messages'] = count($messages);
            $state['updated_at'] = time();
            $this->stateStore->write($this->stateStore->mailboxPath($id), $messages);
            $this->stateStore->write($this->stateStore->statePath($id), $state);

            return $popped;
        });
    }

    private function withAdmissionLock(callable $callback): mixed
    {
        $handle = fopen($this->stateStore->root().'/.admission.lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open background agent admission lock.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock background agent admission.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function activeAgentCount(?string $ownerRunId): int
    {
        $active = 0;
        foreach (glob($this->stateStore->root().'/*.state.json') ?: [] as $path) {
            $state = $this->stateStore->read($path);
            if (! is_array($state)
                || ! in_array($state['status'] ?? null, ['pending', 'running', 'idle', 'waiting_for_input'], true)) {
                continue;
            }
            $owner = is_string($state['owner_run_id'] ?? null) ? $state['owner_run_id'] : null;
            if ($owner === $ownerRunId) {
                $active++;
            }
        }

        return $active;
    }

    private function serializedBytes(array $payload): int
    {
        return strlen(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
