<?php

namespace HaoCode\Tools\Team;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TeamDeleteTool extends BaseTool
{
    public function __construct(
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
        private readonly TaskManager $taskManager,
        private readonly SessionManager $sessionManager,
    ) {}

    public function name(): string
    {
        return 'TeamDelete';
    }

    public function description(): string
    {
        return <<<'DESC'
Delete a team, stopping all its running members and cleaning up their state.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$',
                    'description' => 'Name of the team to delete',
                ],
            ],
            'required' => ['name'],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'];
        try {
            $team = $this->teamManager->get($name);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        if ($team === null) {
            return ToolResult::error("Team not found: {$name}");
        }

        $members = $team['members'] ?? [];
        $stopped = [];
        $alreadyStopped = [];
        $states = [];
        $waitingInterrupts = [];

        foreach ($members as $member) {
            $agentId = $member['agent_id'];
            $agent = $this->backgroundAgentManager->refreshStatus($agentId);
            $states[$agentId] = $agent;
            if (($agent['status'] ?? null) !== 'waiting_for_input') {
                continue;
            }

            $sessionId = $agent['child_session_id'] ?? null;
            $interruptId = $agent['pending_interrupt']['id'] ?? null;
            if (! is_string($sessionId) || $sessionId === ''
                || ! is_string($interruptId) || $interruptId === '') {
                return ToolResult::error(
                    "Cannot delete team '{$name}': member {$agentId} has an invalid pending interrupt state.",
                );
            }
            $waitingInterrupts[] = [
                'session_id' => $sessionId,
                'interrupt_id' => $interruptId,
            ];
        }

        $couldNotStop = [];
        foreach ($members as $member) {
            $agentId = $member['agent_id'];
            $agent = $states[$agentId] ?? null;
            $status = $agent['status'] ?? 'unknown';
            if (! in_array($status, ['running', 'idle', 'pending'], true)) {
                continue;
            }

            $this->backgroundAgentManager->requestStop($agentId);
            $terminated = $this->backgroundAgentManager->terminateProcess($agentId);
            $latest = $this->waitForTerminalState($agentId, $terminated ? 0 : 750);
            if ($latest !== null && in_array(
                $latest['status'] ?? null,
                ['running', 'idle', 'pending'],
                true,
            )) {
                $couldNotStop[] = $agentId;

                continue;
            }
            $stopped[] = $member['role'];
        }
        if ($couldNotStop !== []) {
            return ToolResult::error(
                "Cannot delete team '{$name}': stop was requested but shutdown could not be confirmed for "
                .implode(', ', $couldNotStop).'. Retry after the agents reach a terminal state.',
            );
        }

        foreach ($waitingInterrupts as $pending) {
            try {
                $this->sessions()->cancelInterrupt(
                    $pending['session_id'],
                    $pending['interrupt_id'],
                    "Team '{$name}' deleted.",
                );
            } catch (\Throwable $e) {
                return ToolResult::error(
                    "Cannot delete team '{$name}': failed to cancel member interrupt: {$e->getMessage()}",
                );
            }
        }

        foreach ($members as $member) {
            $agentId = $member['agent_id'];
            $agent = $this->backgroundAgentManager->get($agentId);

            if ($agent === null) {
                $alreadyStopped[] = $member['role'];
            } else {
                if (! in_array($member['role'], $stopped, true)) {
                    $alreadyStopped[] = $member['role'];
                }

                // Clean up background agent state and mailbox files
                $this->backgroundAgentManager->delete($agentId);
            }

            // Clean up task entry
            $this->taskManager->update($agentId, 'completed', 'Team deleted.');
            $this->taskManager->remove($agentId);
        }

        // Delete team manifest
        $this->teamManager->delete($name);

        $lines = ["Team '{$name}' deleted."];
        if (!empty($stopped)) {
            $lines[] = 'Stopped: ' . implode(', ', $stopped);
        }
        if (!empty($alreadyStopped)) {
            $lines[] = 'Already stopped: ' . implode(', ', $alreadyStopped);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function waitForTerminalState(string $agentId, int $waitMilliseconds): ?array
    {
        $deadline = microtime(true) + (max(0, $waitMilliseconds) / 1000);
        do {
            $state = $this->backgroundAgentManager->refreshStatus($agentId);
            if ($state === null || ! in_array(
                $state['status'] ?? null,
                ['running', 'idle', 'pending'],
                true,
            )) {
                return $state;
            }
            if (microtime(true) >= $deadline) {
                return $state;
            }
            usleep(20_000);
        } while (true);
    }

    private function sessions(): SessionManager
    {
        return $this->sessionManager;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function userFacingName(array $input): string
    {
        return 'Delete team ' . ($input['name'] ?? '');
    }
}
