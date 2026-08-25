<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;

trait BackgroundAgentManagerMutateStateConcern
{
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

        $owned = $this->processReaper->owned($pid);

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
            $this->stateStore->root(),
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
        BackgroundAgentProcessReaper::assertResetSafe();
    }

    /** @internal */
    public static function resetSignalReaper(): void
    {
        BackgroundAgentProcessReaper::resetSignalHandler();
    }

    private function reapExitedChildren(): void
    {
        $this->processReaper->drain();
        if ($this->processingExitedChildren) {
            return;
        }

        $this->processingExitedChildren = true;
        try {
            while (($owned = $this->processReaper->shiftExited()) !== null) {
                $state = $this->stateStore->mutate($owned['id'], function (array $state): array {
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

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }
}
