<?php

namespace HaoCode\Services\Agent;

final class TeamResultCollector
{
    public function __construct(
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
    ) {}

    /** @return array<string, mixed>|null */
    public function collect(string $name): ?array
    {
        $team = $this->teamManager->get($name);
        if ($team === null) {
            return null;
        }

        $members = [];
        $summary = ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'pending' => 0];

        foreach ($team['members'] ?? [] as $member) {
            $agent = $this->backgroundAgentManager->refreshStatus($member['agent_id']);
            $status = $agent['status'] ?? 'unknown';
            $result = $agent['last_result'] ?? null;
            $error = $agent['error'] ?? null;
            $waitingForInput = $status === 'waiting_for_input';
            $outcome = match (true) {
                is_string($result) && trim($result) !== '' => 'succeeded',
                in_array($status, ['error', 'dead', 'completed'], true) => 'failed',
                default => 'pending',
            };

            $summary['total']++;
            $summary[$outcome]++;
            $members[] = [
                'role' => $member['role'],
                'agent_id' => $member['agent_id'],
                'agent_type' => $member['agent_type'] ?? 'general-purpose',
                'status' => $status,
                'outcome' => $outcome,
                'result' => $result,
                'error' => $error,
                'pending_interrupt' => $waitingForInput ? ($agent['pending_interrupt'] ?? null) : null,
                'child_session_id' => $waitingForInput ? ($agent['child_session_id'] ?? null) : null,
            ];
        }

        return ['team' => $name, 'summary' => $summary, 'members' => $members];
    }
}
