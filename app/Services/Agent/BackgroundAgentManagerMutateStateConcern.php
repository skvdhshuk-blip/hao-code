<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

trait BackgroundAgentManagerMutateStateConcern
{

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
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            ['ps', '-o', 'lstart=', '-p', (string) $pid],
            $descriptors,
            $pipes,
            $this->storageRoot(),
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'LANG' => 'C',
                'LC_ALL' => 'C',
            ],
        );
        if (! is_resource($process)) {
            return null;
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $deadline = microtime(true) + 0.5;
        $output = '';
        $exitCode = -1;
        while (true) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && $chunk !== '') {
                    $output .= $chunk;
                    if (strlen($output) > 4096) {
                        $output = substr($output, 0, 4096);
                        break;
                    }
                }
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = ($status['signaled'] ?? false)
                    ? 128 + (int) ($status['termsig'] ?? 0)
                    : (int) ($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                if ($index === 1) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if (is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                        if (strlen($output) > 4096) {
                            $output = substr($output, 0, 4096);
                        }
                    }
                }
                fclose($pipes[$index]);
            }
        }
        $closed = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }
        if ($exitCode !== 0) {
            return null;
        }

        $value = trim($output);

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
        $status = $this->git($path, ['status', '--porcelain']);
        if ($status['exitCode'] !== 0 || trim($status['stdout']) !== '') {
            return true;
        }

        $parent = dirname($path, 3);
        $worktreeHeadResult = $this->git($path, ['rev-parse', 'HEAD']);
        $parentHeadResult = $this->git($parent, ['rev-parse', 'HEAD']);
        $worktreeHead = trim($worktreeHeadResult['stdout']);
        $parentHead = trim($parentHeadResult['stdout']);

        // Preserve on uncertainty. A clean worktree can still contain committed
        // agent work, which must not be deleted with the temporary branch.
        return $worktreeHeadResult['exitCode'] !== 0
            || $parentHeadResult['exitCode'] !== 0
            || $worktreeHead === ''
            || $parentHead === ''
            || $worktreeHead !== $parentHead;
    }

    private function branchHasUniqueCommits(string $parent, string $branch): ?bool
    {
        $exists = $this->git($parent, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if ($exists['exitCode'] !== 0) {
            return false;
        }

        $countResult = $this->git($parent, ['rev-list', '--count', 'HEAD..refs/heads/'.$branch]);
        $count = trim($countResult['stdout']);
        if ($countResult['exitCode'] !== 0 || ! ctype_digit($count)) {
            return null;
        }

        return (int) $count > 0;
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function git(string $cwd, array $args, float $timeoutSeconds = 2.0): array
    {
        $result = (new HardenedGitRunner())->runGit($cwd, $args, $timeoutSeconds);

        return [
            'stdout' => $result['stdout'],
            'stderr' => $result['timedOut'] ? 'Git command timed out.' : ($result['truncated'] ? 'Git command output exceeded limit.' : $result['stderr']),
            'exitCode' => $result['exitCode'],
        ];
    }

    /** @internal */
    public static function assertRuntimeResetSafe(): void
    {
        $ownedIds = [];
        foreach (self::$signalReapers as $key => $reference) {
            $manager = $reference->get();
            if (! $manager instanceof self) {
                unset(self::$signalReapers[$key]);
                continue;
            }

            $manager->reapExitedChildren();
            foreach ($manager->ownedProcesses as $owned) {
                $ownedIds[] = $owned['id'];
            }
        }

        if ($ownedIds !== []) {
            throw new \RuntimeException(
                'Cannot reset HaoCode runtime while background agents are still running: '
                .implode(', ', array_values(array_unique($ownedIds))).'.',
            );
        }
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
}
