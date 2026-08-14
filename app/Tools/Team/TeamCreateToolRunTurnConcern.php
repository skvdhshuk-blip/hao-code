<?php

namespace HaoCode\Tools\Team;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\AgentDefinition;
use HaoCode\Tools\Agent\AgentLoader;
use HaoCode\Tools\Agent\AgentModelResolver;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

trait TeamCreateToolRunTurnConcern
{

    private function runTurn(object $subLoop, string $agentId, string $prompt): ?string
    {
        $this->backgroundAgentManager->markRunning($agentId);
        try {
            $response = $subLoop->run(userInput: $prompt, onTextDelta: null);
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            $this->backgroundAgentManager->markWaitingForInput($agentId, $e->interrupt);
            $this->taskManager->update($agentId, 'in_progress', 'Waiting for human input.');
            throw $e;
        }

        if ($response === '(aborted)') {
            return null;
        }

        $preview = mb_strlen($response) > 4000
            ? mb_substr($response, 0, 4000) . "\n\n[Truncated]"
            : $response;

        $this->backgroundAgentManager->recordResult($agentId, $response);
        $this->taskManager->update($agentId, 'in_progress', $preview);

        return $response;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function userFacingName(array $input): string
    {
        return 'Create team ' . ($input['name'] ?? 'agents');
    }
}
