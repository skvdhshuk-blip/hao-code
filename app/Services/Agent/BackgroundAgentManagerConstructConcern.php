<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

trait BackgroundAgentManagerConstructConcern
{

    public function __construct(
        private readonly ?string $storagePath = null,
        private readonly ?TaskManager $taskManager = null,
    ) {
        $this->ensureStoragePath();
    }

    public function create(
        string $id,
        string $prompt,
        string $agentType,
        ?string $description = null,
        ?int $pid = null,
        ?string $worktreePath = null,
        ?string $worktreeBranch = null,
    ): array {
        $id = StateIdentifier::backgroundAgentId($id);

        return $this->withAgentLock(
            $id,
            LOCK_EX,
            function () use ($id, $prompt, $agentType, $description, $pid, $worktreePath, $worktreeBranch): array {
                if (is_file($this->statePath($id)) || is_file($this->mailboxPath($id))) {
                    throw new \InvalidArgumentException("Background agent '{$id}' already exists.");
                }

                $state = [
                    'id' => $id,
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
                    $this->writeJsonAtomically($this->statePath($id), $state);
                    $this->writeJsonAtomically($this->mailboxPath($id), []);
                } catch (\Throwable $e) {
                    @unlink($this->statePath($id));
                    @unlink($this->mailboxPath($id));
                    throw $e;
                }

                return $state;
            },
        );
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
        $this->reapExitedChildren();
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
        $this->reapExitedChildren();
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
        $id = StateIdentifier::backgroundAgentId($id);
        $token = bin2hex(random_bytes(16));
        $startTime = $this->processStartTime($pid);
        $state = $this->mutateState($id, function (array $state) use ($pid, $token, $startTime) {
            if (in_array($state['status'] ?? null, ['completed', 'error', 'dead', 'waiting_for_input'], true)) {
                return $state;
            }

            $state['pid'] = $pid;
            $state['process_start_time'] = $startTime;
            $state['process_token'] = $token;
            if (($state['status'] ?? null) === 'pending') {
                $state['status'] = 'running';
            }

            return $state;
        });

        // A child may persist a terminal state just before it actually exits.
        // Track it even when process metadata is no longer attached so a later
        // non-blocking reap cannot miss that small window.
        $this->ownedProcesses[$pid] = ['id' => $id, 'token' => $token];
        $this->registerSignalReaper();
        $this->reapExitedChildren();

        return $state;
    }

    public function markRunning(string $id): ?array
    {
        return $this->mutateState($id, function (array $state) {
            $state['status'] = 'running';
            $state['error'] = null;
            $this->clearInterruptState($state);

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
            $this->clearProcessState($state);

            return $state;
        });
    }

    public function recordResult(string $id, string $result): ?array
    {
        return $this->mutateState($id, function (array $state) use ($result) {
            $state['last_result'] = $this->truncate($result, self::RESULT_LIMIT);
            $state['status'] = 'idle';
            $state['error'] = null;
            $this->clearInterruptState($state);

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
            $state['error'] = null;
            $this->clearInterruptState($state);
            $this->clearProcessState($state);

            return $state;
        });
    }

    public function markError(string $id, string $error): ?array
    {
        return $this->mutateState($id, function (array $state) use ($error) {
            $state['status'] = 'error';
            $state['error'] = $this->truncate($error, 4000);
            $this->clearInterruptState($state);
            $this->clearProcessState($state);

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
            $this->clearInterruptState($state);
            $this->clearProcessState($state);

            return $state;
        });
    }

    /** Refresh a process-backed state and persist a terminal dead status. */
    public function refreshStatus(string $id): ?array
    {
        $this->reapExitedChildren();
        $state = $this->get($id);
        if ($state === null) {
            return null;
        }

        $status = $state['status'] ?? 'unknown';
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0 || ! in_array($status, ['pending', 'running', 'idle'], true)) {
            return $state;
        }

        $alive = $this->processIsAlive($state);
        if ($alive === null || $alive) {
            return $state;
        }

        return $this->markDead($id, 'Background agent process is no longer running.');
    }

    public function terminateProcess(string $id, int $signal = 15, int $waitMilliseconds = 1000): bool
    {
        $state = $this->get($id);
        if ($state === null || ! $this->canSignal($state) || ! function_exists('posix_kill')) {
            return false;
        }

        $pid = (int) $state['pid'];
        if (! @posix_kill($pid, $signal)) {
            return false;
        }
        if (! function_exists('pcntl_waitpid')) {
            return true;
        }

        $deadline = microtime(true) + (max(0, $waitMilliseconds) / 1000);
        do {
            $this->reapExitedChildren();
            if (! isset($this->ownedProcesses[$pid])) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
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

    public function finalizeWorktree(
        string $id,
        bool $retained,
        ?string $notice = null,
        ?string $error = null,
    ): ?array {
        return $this->mutateState($id, function (array $state) use ($retained, $notice, $error): array {
            $state['worktree_retained'] = $retained;
            $state['worktree_cleanup_error'] = $error;
            if (! $retained) {
                $state['worktree_path'] = null;
                $state['worktree_branch'] = null;
            }
            if ($notice !== null) {
                $current = trim((string) ($state['last_result'] ?? ''));
                if (! str_contains($current, $notice)) {
                    $state['last_result'] = $this->truncate(
                        $current === '' ? $notice : $current."\n\n".$notice,
                        self::RESULT_LIMIT,
                    );
                }
            }

            return $state;
        });
    }

    public function finalizeStoredWorktree(string $id): ?array
    {
        $state = $this->get($id);
        if ($state === null) {
            return null;
        }

        $path = $state['worktree_path'] ?? null;
        $branch = $state['worktree_branch'] ?? null;
        if (! is_string($path) || $path === '' || ! is_string($branch) || $branch === '') {
            return $state;
        }

        $outcome = $this->finalizeManagedWorktree($path, $branch);

        return $this->finalizeWorktree(
            $id,
            retained: $outcome['retained'],
            notice: $outcome['notice'],
            error: $outcome['error'],
        );
    }

    /**
     * @return array{retained: bool, notice: string|null, error: string|null}
     * @internal
     */
    public function finalizeManagedWorktree(string $path, string $branch): array
    {
        if (! $this->isManagedWorktree($path, $branch)) {
            return [
                'retained' => true,
                'notice' => null,
                'error' => 'Refused to finalize an invalid managed worktree path.',
            ];
        }

        if (is_dir($path)) {
            if ($this->worktreeHasChanges($path)) {
                return [
                    'retained' => true,
                    'notice' => "Worktree with changes retained at: {$path} (branch: {$branch})",
                    'error' => null,
                ];
            }
        } else {
            $uniqueCommits = $this->branchHasUniqueCommits(dirname($path, 3), $branch);
            if ($uniqueCommits !== false) {
                $reason = $uniqueCommits === true
                    ? 'branch contains commits not present on the parent HEAD'
                    : 'branch history could not be verified safely';

                return [
                    'retained' => true,
                    'notice' => "Worktree directory is missing; branch retained because {$reason}: {$branch}",
                    'error' => null,
                ];
            }
        }

        $parent = dirname($path, 3);
        $removed = $this->git($parent, ['worktree', 'remove', '--force', $path], 10.0);
        $removeCode = $removed['exitCode'];
        if ($removeCode !== 0 && ! is_dir($path)) {
            $this->git($parent, ['worktree', 'prune'], 10.0);
            $removeCode = 0;
        }

        $branchCode = 0;
        if ($removeCode === 0) {
            $branchExists = $this->git($parent, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch]);
            if ($branchExists['exitCode'] === 0) {
                $deleted = $this->git($parent, ['branch', '-D', $branch], 10.0);
                $branchCode = $deleted['exitCode'];
            }
        }

        if ($removeCode === 0 && $branchCode === 0) {
            return ['retained' => false, 'notice' => null, 'error' => null];
        }

        return [
            'retained' => true,
            'notice' => null,
            'error' => 'Failed to remove the temporary worktree and branch.',
        ];
    }
}
