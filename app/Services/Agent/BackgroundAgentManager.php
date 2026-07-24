<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

class BackgroundAgentManager
{
    private const RESULT_LIMIT = 100000;

    /** @var array<int, \WeakReference> */
    private static array $signalReapers = [];

    private static bool $signalReaperInstalled = false;

    private static mixed $previousSigchldHandler = null;

    private static ?bool $previousAsyncSignals = null;

    /** @var array<int, array{id: string, token: string}> */
    private array $ownedProcesses = [];

    /** @var array<int, array{id: string, token: string}> */
    private array $exitedProcesses = [];

    private bool $reapingProcessHandles = false;

    private bool $reapAgain = false;

    private bool $processingExitedChildren = false;

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
        if (! $this->isManagedWorktree($path, $branch)) {
            return $this->finalizeWorktree(
                $id,
                retained: true,
                error: 'Refused to finalize an invalid managed worktree path.',
            );
        }

        if (is_dir($path)) {
            if ($this->worktreeHasChanges($path)) {
                return $this->finalizeWorktree(
                    $id,
                    retained: true,
                    notice: "Worktree retained at: {$path} (branch: {$branch})",
                );
            }
        } else {
            $uniqueCommits = $this->branchHasUniqueCommits(dirname($path, 3), $branch);
            if ($uniqueCommits !== false) {
                $reason = $uniqueCommits === true
                    ? 'branch contains commits not present on the parent HEAD'
                    : 'branch history could not be verified safely';

                return $this->finalizeWorktree(
                    $id,
                    retained: true,
                    notice: "Worktree directory is missing; branch retained because {$reason}: {$branch}",
                );
            }
        }

        $parent = dirname($path, 3);
        $removeOutput = [];
        $removeCode = 0;
        exec(
            'cd '.escapeshellarg($parent)
            .' && git worktree remove '.escapeshellarg($path).' --force 2>&1',
            $removeOutput,
            $removeCode,
        );
        if ($removeCode !== 0 && ! is_dir($path)) {
            exec('cd '.escapeshellarg($parent).' && git worktree prune 2>/dev/null');
            $removeCode = 0;
        }

        $branchOutput = [];
        $branchCode = 0;
        if ($removeCode === 0) {
            $branchExistsCode = 0;
            exec(
                'cd '.escapeshellarg($parent)
                .' && git show-ref --verify --quiet '.escapeshellarg('refs/heads/'.$branch),
                $branchOutput,
                $branchExistsCode,
            );
            if ($branchExistsCode === 0) {
                exec(
                    'cd '.escapeshellarg($parent)
                    .' && git branch -D '.escapeshellarg($branch).' 2>&1',
                    $branchOutput,
                    $branchCode,
                );
            }
        }

        if ($removeCode === 0 && $branchCode === 0) {
            return $this->finalizeWorktree($id, retained: false);
        }

        return $this->finalizeWorktree(
            $id,
            retained: true,
            error: 'Failed to remove the temporary worktree and branch.',
        );
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

    private function clearInterruptState(array &$state): void
    {
        unset($state['pending_interrupt'], $state['child_session_id']);
    }

    private function clearProcessState(array &$state): void
    {
        $state['pid'] = null;
        $state['process_start_time'] = null;
        $state['process_token'] = null;
    }

    private function processIsAlive(array $state): ?bool
    {
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return null;
        }
        if (! @posix_kill($pid, 0)) {
            return false;
        }

        $expectedStart = $state['process_start_time'] ?? null;
        if (! is_string($expectedStart) || $expectedStart === '') {
            return true;
        }

        $actualStart = $this->processStartTime($pid);
        if ($actualStart === null) {
            return null;
        }

        return hash_equals($expectedStart, $actualStart);
    }

    private function canSignal(array $state): bool
    {
        $pid = (int) ($state['pid'] ?? 0);
        $token = $state['process_token'] ?? null;
        if ($pid <= 0 || ! is_string($token) || $token === '') {
            return false;
        }

        $owned = $this->ownedProcesses[$pid] ?? null;

        return is_array($owned)
            && hash_equals($owned['token'], $token)
            && $this->processIsAlive($state) === true;
    }

    private function processStartTime(int $pid): ?string
    {
        if ($pid <= 0 || ! function_exists('shell_exec')) {
            return null;
        }

        $value = shell_exec('ps -o lstart= -p '.(int) $pid.' 2>/dev/null');
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function isManagedWorktree(string $path, string $branch): bool
    {
        return preg_match('/^agent-[a-f0-9]{8}$/', $branch) === 1
            && basename($path) === $branch
            && basename(dirname($path)) === 'worktrees'
            && basename(dirname($path, 2)) === '.claude';
    }

    private function worktreeHasChanges(string $path): bool
    {
        $status = trim((string) shell_exec(
            'cd '.escapeshellarg($path).' && git status --porcelain 2>/dev/null',
        ));
        if ($status !== '') {
            return true;
        }

        $parent = dirname($path, 3);
        $worktreeHead = trim((string) shell_exec(
            'cd '.escapeshellarg($path).' && git rev-parse HEAD 2>/dev/null',
        ));
        $parentHead = trim((string) shell_exec(
            'cd '.escapeshellarg($parent).' && git rev-parse HEAD 2>/dev/null',
        ));

        // Preserve on uncertainty. A clean worktree can still contain committed
        // agent work, which must not be deleted with the temporary branch.
        return $worktreeHead === '' || $parentHead === '' || $worktreeHead !== $parentHead;
    }

    private function branchHasUniqueCommits(string $parent, string $branch): ?bool
    {
        $output = [];
        $code = 0;
        exec(
            'cd '.escapeshellarg($parent)
            .' && git show-ref --verify --quiet '.escapeshellarg('refs/heads/'.$branch),
            $output,
            $code,
        );
        if ($code !== 0) {
            return false;
        }

        $output = [];
        exec(
            'cd '.escapeshellarg($parent)
            .' && git rev-list --count HEAD..'.escapeshellarg('refs/heads/'.$branch).' 2>&1',
            $output,
            $code,
        );
        $count = trim(implode("\n", $output));
        if ($code !== 0 || ! ctype_digit($count)) {
            return null;
        }

        return (int) $count > 0;
    }

    /** @internal */
    public static function resetSignalReaper(): void
    {
        if (self::$signalReaperInstalled
            && function_exists('pcntl_signal')
            && defined('SIGCHLD')) {
            $handler = self::$previousSigchldHandler;
            if (! is_callable($handler) && ! is_int($handler)) {
                $handler = defined('SIG_DFL') ? constant('SIG_DFL') : 0;
            }
            pcntl_signal(constant('SIGCHLD'), $handler);
            if (self::$previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals(self::$previousAsyncSignals);
            }
        }

        self::$signalReapers = [];
        self::$signalReaperInstalled = false;
        self::$previousSigchldHandler = null;
        self::$previousAsyncSignals = null;
    }

    private function reapExitedChildren(): void
    {
        $this->reapExitedProcessHandles();
        if ($this->processingExitedChildren) {
            return;
        }

        $this->processingExitedChildren = true;
        try {
            while ($this->exitedProcesses !== []) {
                $owned = array_shift($this->exitedProcesses);
                $state = $this->mutateState($owned['id'], function (array $state): array {
                    if (in_array($state['status'] ?? null, ['pending', 'running', 'idle'], true)) {
                        $state['status'] = 'dead';
                        $state['error'] ??= 'Background agent process exited before recording a terminal state.';
                        $this->clearProcessState($state);
                    }

                    return $state;
                });
                if (($state['status'] ?? null) === 'dead') {
                    $this->taskManager?->update(
                        $owned['id'],
                        'completed',
                        (string) ($state['error'] ?? 'Background agent process exited.'),
                    );
                }
                if (in_array($state['status'] ?? null, ['completed', 'error', 'dead'], true)) {
                    $this->finalizeStoredWorktree($owned['id']);
                }
            }
        } finally {
            $this->processingExitedChildren = false;
        }
    }

    private function reapExitedProcessHandles(): void
    {
        if ($this->ownedProcesses === [] || ! function_exists('pcntl_waitpid')) {
            return;
        }
        if ($this->reapingProcessHandles) {
            $this->reapAgain = true;

            return;
        }

        $this->reapingProcessHandles = true;
        try {
            do {
                $this->reapAgain = false;
                foreach ($this->ownedProcesses as $pid => $owned) {
                    $waited = @pcntl_waitpid($pid, $status, WNOHANG);
                    if ($waited === -1) {
                        $interrupted = defined('PCNTL_EINTR')
                            && function_exists('pcntl_get_last_error')
                            && pcntl_get_last_error() === constant('PCNTL_EINTR');
                        if (! $interrupted) {
                            unset($this->ownedProcesses[$pid]);
                        }

                        continue;
                    }
                    if ($waited !== $pid) {
                        continue;
                    }

                    unset($this->ownedProcesses[$pid]);
                    $this->exitedProcesses[] = $owned;
                }
            } while ($this->reapAgain);
        } finally {
            $this->reapingProcessHandles = false;
        }
    }

    private function registerSignalReaper(): void
    {
        if (! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')
            || ! defined('SIGCHLD')) {
            return;
        }

        self::$signalReapers[spl_object_id($this)] = \WeakReference::create($this);
        if (self::$signalReaperInstalled) {
            return;
        }

        $previous = pcntl_signal_get_handler(constant('SIGCHLD'));
        if (defined('SIG_IGN') && $previous === constant('SIG_IGN')) {
            // The host already asks the OS to auto-reap all children.
            return;
        }

        self::$previousSigchldHandler = $previous;
        self::$previousAsyncSignals = pcntl_async_signals();
        $installed = pcntl_signal(
            constant('SIGCHLD'),
            static function (int $signal, array $info = []): void {
                foreach (self::$signalReapers as $id => $reference) {
                    $manager = $reference->get();
                    if (! $manager instanceof self) {
                        unset(self::$signalReapers[$id]);

                        continue;
                    }
                    // Only waitpid and enqueue here. File locks and state
                    // transitions remain in the normal manager call stack.
                    $manager->reapExitedProcessHandles();
                }

                $previous = self::$previousSigchldHandler;
                if (is_callable($previous)) {
                    $previous($signal, $info);
                }
            },
        );
        if ($installed) {
            // Background-agent ownership requires prompt SIGCHLD delivery.
            // Existing callable SIGCHLD handlers are preserved above.
            pcntl_async_signals(true);
            self::$signalReaperInstalled = true;
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
