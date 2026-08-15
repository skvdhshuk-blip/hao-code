<?php

namespace HaoCode\Tools\Agent;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentInvocation;
use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait AgentToolExecuteBackgroundAgentConcern
{

    private function executeBackgroundAgent(
        string $taskId,
        string $prompt,
        AgentDefinition $agentDef,
        ?string $model,
        ToolUseContext $context,
        ?string $worktreePath = null,
    ): void {
        $subLoop = $this->agentLoopFactory->createIsolated(
            toolFilter: fn(string $toolName) => $agentDef->isToolAllowed($toolName),
            workingDirectory: $worktreePath ?? $context->workingDirectory,
            streamingClient: $context->provider,
            runContext: $context->runContext?->fork(
                workingDirectory: $worktreePath ?? $context->workingDirectory,
                readOnly: $agentDef->readOnly,
                interruptOn: $agentDef->interruptOn,
                agentId: $taskId,
                projectDirectory: $worktreePath,
                backgroundOwnerAgentId: $taskId,
            ),
            afterFork: true,
            readOnly: $agentDef->readOnly,
            parentToolRegistry: $context->toolRegistry,
            parentRunContext: $context->runContext,
            model: $model,
            appendSystemPrompt: $agentDef->systemPrompt,
            omitProjectInstructions: $agentDef->omitClaudeMd,
            agentType: $agentDef->agentType,
            limits: \HaoCode\Services\Agent\RunLimits::turns($agentDef->maxTurns ?? 50),
        );

        $this->backgroundAgents()->markRunning($taskId);
        $this->tasks()->transition(
            $taskId,
            ['pending'],
            'in_progress',
            'Background agent is processing its initial task.',
        );

        $lastResponse = $this->runBackgroundTurn($subLoop, $taskId, $prompt);
        $idleSince = time();
        $idleTimeout = max(30, (int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.background_agent_idle_timeout', 300));
        $pollMicros = max(100_000, ((int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.background_agent_poll_interval_ms', 250)) * 1000);

        while (true) {
            if ($this->backgroundAgents()->isStopRequested($taskId)) {
                $stopMessage = 'Background agent stopped by user.';
                if ($lastResponse !== null && trim($lastResponse) !== '') {
                    $stopMessage .= "\n\nLast response:\n" . $this->truncateResult($lastResponse, 4000);
                }

                $this->backgroundAgents()->markCompleted($taskId, $lastResponse ?? $stopMessage);
                $this->tasks()->update($taskId, 'completed', $stopMessage);

                return;
            }

            $message = $this->backgroundAgents()->popNextMessage($taskId);
            if ($message !== null) {
                $idleSince = time();
                $response = $this->runBackgroundTurn(
                    $subLoop,
                    $taskId,
                    $this->buildMailboxPrompt($message),
                );
                if ($response !== null) {
                    $lastResponse = $response;
                }

                continue;
            }

            if ((time() - $idleSince) >= $idleTimeout) {
                $finalMessage = 'Background agent finished after waiting for follow-up messages.';
                if ($lastResponse !== null && trim($lastResponse) !== '') {
                    $finalMessage .= "\n\nLast response:\n" . $this->truncateResult($lastResponse, 4000);
                }

                $this->backgroundAgents()->markCompleted($taskId, $lastResponse ?? $finalMessage);
                $this->tasks()->update($taskId, 'completed', $finalMessage);

                return;
            }

            usleep($pollMicros);
        }
    }

    private function runBackgroundTurn(object $subLoop, string $taskId, string $prompt): ?string
    {
        $this->backgroundAgents()->markRunning($taskId);
        try {
            $response = (new AgentInvocation($prompt))->invoke($subLoop)->text;
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            $this->backgroundAgents()->markWaitingForInput($taskId, $e->interrupt);
            $this->tasks()->update($taskId, 'in_progress', 'Waiting for human input.');
            throw $e;
        }

        if ($response === '(aborted)') {
            return null;
        }

        $preview = $this->truncateResult($response, 4000);
        $this->backgroundAgents()->recordResult($taskId, $response);
        $this->tasks()->update($taskId, 'in_progress', $preview);

        return $response;
    }

    private function buildMailboxPrompt(array $message): string
    {
        $header = 'Follow-up instruction received';
        if (!empty($message['from'])) {
            $header .= " from {$message['from']}";
        }
        if (!empty($message['summary'])) {
            $header .= " ({$message['summary']})";
        }

        return $header . ":\n" . trim((string) ($message['message'] ?? ''));
    }

    private function truncateResult(string $result, int $limit): string
    {
        if (mb_strlen($result) <= $limit) {
            return $result;
        }

        return mb_substr($result, 0, $limit) . "\n\n[Result truncated]";
    }

    /**
     * @return array{path: string, branch: string}|ToolResult
     */
    private function createWorktree(string $projectDir): array|ToolResult
    {
        $projectDir = realpath($projectDir) ?: $projectDir;
        $inGit = trim($this->git($projectDir, ['rev-parse', '--is-inside-work-tree'])['stdout']);
        if ($inGit !== 'true') {
            return ToolResult::error("Cannot create worktree: not a git repository.");
        }

        $branch = 'agent-' . bin2hex(random_bytes(4));
        $worktreeDir = $projectDir . '/.claude/worktrees/' . $branch;
        $claudeDir = $projectDir . '/.claude';
        if (is_link($claudeDir)) {
            return ToolResult::error('Cannot create worktree: .claude is a symlink.');
        }

        $worktreeBase = dirname($worktreeDir);
        if (! is_dir($worktreeBase) && ! mkdir($worktreeBase, 0755, true) && ! is_dir($worktreeBase)) {
            return ToolResult::error("Failed to create worktree directory: {$worktreeBase}");
        }

        $created = $this->git($projectDir, [
            '-c',
            'core.hooksPath='.$this->nullDevice(),
            'worktree',
            'add',
            '-b',
            $branch,
            $worktreeDir,
            'HEAD',
        ], 10.0);

        if (!is_dir($worktreeDir)) {
            return ToolResult::error("Failed to create worktree: {$created['stderr']}{$created['stdout']}");
        }

        return ['path' => $worktreeDir, 'branch' => $branch];
    }

    private function finalizeSyncWorktree(
        ToolResult $result,
        string $worktreePath,
        string $worktreeBranch,
    ): ToolResult {
        $outcome = $this->backgroundAgents()->finalizeManagedWorktree($worktreePath, $worktreeBranch);
        if (! $outcome['retained']) {
            return $result;
        }

        $notice = $outcome['notice']
            ?? "Warning: {$outcome['error']} {$worktreePath} (branch: {$worktreeBranch}).";

        return $result
            ->appendOutput("\n\n".$notice)
            ->withMetadata(($result->metadata ?? []) + [
                'worktreePath' => $worktreePath,
                'worktreeBranch' => $worktreeBranch,
                'worktreeRetained' => true,
            ]);
    }

    private function finalizeBackgroundWorktree(string $taskId): void
    {
        $this->backgroundAgents()->finalizeStoredWorktree($taskId);
    }

    private function backgroundAgents(): BackgroundAgentManager
    {
        return $this->backgroundAgentManager ?? \HaoCode\Support\Runtime\SdkRuntime::app(BackgroundAgentManager::class);
    }

    private function tasks(): TaskManager
    {
        return $this->taskManager ?? \HaoCode\Support\Runtime\SdkRuntime::app(TaskManager::class);
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

    private function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
