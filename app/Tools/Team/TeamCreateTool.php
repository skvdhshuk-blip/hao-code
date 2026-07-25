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

class TeamCreateTool extends BaseTool
{
    public function __construct(
        private readonly AgentLoopFactory $agentLoopFactory,
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
        private readonly TaskManager $taskManager,
    ) {}

    public function name(): string
    {
        return 'TeamCreate';
    }

    public function description(): string
    {
        return <<<'DESC'
Create a team of multiple background agents that work together on a shared objective.

Each team member is a background agent with a specific role and prompt. Members can
communicate via SendMessage using their agent IDs (format: {teamName}_{role}).
Use `to: "team:{name}"` with SendMessage to broadcast to all members.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Unique team name (lowercase alphanumeric with hyphens, e.g., "backend-team")',
                ],
                'task' => [
                    'type' => 'string',
                    'description' => 'The overall objective in a concise single-line string',
                ],
                'members' => [
                    'type' => 'array',
                    'description' => 'Team members to create (max 10)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => [
                                'type' => 'string',
                                'description' => 'Role name (e.g., "architect", "reviewer", "implementer")',
                            ],
                            'agent_type' => [
                                'type' => 'string',
                                'description' => 'Agent type (e.g., "general-purpose", "Plan", "code-reviewer")',
                            ],
                            'prompt' => [
                                'type' => 'string',
                                'description' => 'Optional concise role-specific instructions. Omit for large teams to use a role-based default.',
                            ],
                            'model' => [
                                'type' => 'string',
                                'description' => 'Optional model override',
                                'enum' => ['sonnet', 'opus', 'haiku', 'inherit'],
                            ],
                        ],
                        'required' => ['role'],
                    ],
                ],
                'read_only' => [
                    'type' => 'boolean',
                    'description' => 'Enforce read-only permissions for every member, including Bash commands',
                ],
                'max_turns' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum model turns for each member (1-50)',
                ],
                'default_agent_type' => [
                    'type' => 'string',
                    'description' => 'Agent type used by members that omit agent_type',
                ],
            ],
            'required' => ['name', 'task', 'members'],
        ], [
            'name' => 'required|string|regex:/^[a-z0-9][a-z0-9_-]*$/|max:32',
            'task' => 'required|string|min:5',
            'members' => 'required|array|min:1|max:10',
            'members.*.role' => 'required|string',
            'members.*.agent_type' => 'nullable|string',
            'members.*.prompt' => 'nullable|string',
            'members.*.model' => 'nullable|string|in:sonnet,opus,haiku,inherit',
            'read_only' => 'nullable|boolean',
            'max_turns' => 'nullable|integer|min:1|max:50',
            'default_agent_type' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'];
        $task = $input['task'];
        $members = $input['members'];
        $readOnly = (bool) ($input['read_only'] ?? false);
        $maxTurns = isset($input['max_turns']) ? (int) $input['max_turns'] : null;
        $defaultAgentType = $input['default_agent_type'] ?? 'general-purpose';
        $members = array_map(function (array $member) use ($defaultAgentType): array {
            $member['agent_type'] ??= $defaultAgentType;

            return $member;
        }, $members);

        // Check team doesn't already exist. The manager repeats validation for
        // callers that bypass tool-schema validation.
        try {
            $existingTeam = $this->teamManager->get($name);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        if ($existingTeam !== null) {
            return ToolResult::error("Team '{$name}' already exists. Delete it first or choose a different name.");
        }

        // Check for duplicate roles
        $roles = array_column($members, 'role');
        $duplicates = array_diff_assoc($roles, array_unique($roles));
        if (!empty($duplicates)) {
            return ToolResult::error('Duplicate roles found: ' . implode(', ', array_unique($duplicates)));
        }

        $teamCreated = false;
        try {
            $agentIds = array_map(
                static fn (array $member): string => TeamManager::memberAgentId($name, $member['role']),
                $members,
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        if (count($agentIds) !== count(array_unique($agentIds, SORT_STRING))) {
            return ToolResult::error('Team member roles collide after normalization.');
        }

        // Check for agent ID collisions with existing background agents
        foreach ($agentIds as $agentId) {
            if ($this->backgroundAgentManager->get($agentId) !== null) {
                return ToolResult::error("Background agent '{$agentId}' already exists. Delete it or choose a different role name.");
            }
        }

        $allAgents = AgentLoader::loadAll($context->workingDirectory);
        $resolvedAgents = [];
        foreach ($members as $index => $member) {
            $agentTypeName = trim((string) ($member['agent_type'] ?? 'general-purpose'));
            $agentDef = $allAgents[$agentTypeName] ?? null;
            if ($agentDef === null) {
                return ToolResult::error("Unknown agent type: {$agentTypeName}");
            }
            try {
                $model = AgentModelResolver::resolve(
                    $member['model'] ?? null,
                    $agentDef->model,
                    $context->runContext?->settings->getProviderType() ?? 'anthropic',
                );
            } catch (\InvalidArgumentException $e) {
                return ToolResult::error($e->getMessage());
            }
            $resolvedAgents[$agentIds[$index]] = [
                'definition' => $agentDef,
                'model' => $model,
            ];
        }

        try {
            // Persist the manifest and claim every member ID before any child
            // process starts. Manager-level locks close the check/create race.
            $team = $this->teamManager->create($name, $members);
            $teamCreated = true;
            $roster = $this->buildRoster($team['members']);
            $claimed = [];
            foreach ($team['members'] as $member) {
                $agentId = $member['agent_id'];
                /** @var AgentDefinition $agentDef */
                $agentDef = $resolvedAgents[$agentId]['definition'];
                $fullPrompt = $this->buildMemberPrompt($member, $name, $task, $roster);
                $this->backgroundAgentManager->create(
                    id: $agentId,
                    prompt: $fullPrompt,
                    agentType: $agentDef->agentType,
                    description: "Team '{$name}' member: {$member['role']}",
                );
                try {
                    $this->taskManager->createWithId(
                        id: $agentId,
                        subject: "[{$name}] {$member['role']}",
                        activeForm: 'Running as team member',
                        description: $fullPrompt,
                    );
                } catch (\Throwable $e) {
                    $this->backgroundAgentManager->delete($agentId);
                    throw $e;
                }
                $claimed[] = $agentId;
            }
        } catch (\Throwable $e) {
            foreach ($claimed ?? [] as $agentId) {
                $this->backgroundAgentManager->delete($agentId);
                $this->taskManager->remove($agentId);
            }
            if ($teamCreated) {
                try {
                    $this->teamManager->delete($name);
                } catch (\Throwable) {
                    // The original claim failure is the actionable error.
                }
            }

            return ToolResult::error("Failed to create team: {$e->getMessage()}");
        }

        // Spawn each member after every ID has been claimed.
        $spawned = [];
        $failed = [];

        foreach ($team['members'] as $member) {
            $agentId = $member['agent_id'];
            /** @var AgentDefinition $agentDef */
            $agentDef = $resolvedAgents[$agentId]['definition'];
            $model = $resolvedAgents[$agentId]['model'];

            // Build composite prompt with team context
            $fullPrompt = $this->buildMemberPrompt($member, $name, $task, $roster);

            // Fork the background agent process
            $result = $this->forkMember(
                $agentId,
                $name,
                $fullPrompt,
                $agentDef,
                $model,
                $context,
                $readOnly,
                $maxTurns,
            );
            if ($result['success']) {
                $spawned[] = ['role' => $member['role'], 'agent_id' => $agentId, 'pid' => $result['pid']];
                $this->backgroundAgentManager->attachProcess($agentId, $result['pid']);
                $this->taskManager->transition(
                    $agentId,
                    ['pending'],
                    'in_progress',
                    'Background agent is running.',
                );
            } else {
                $failed[] = ['role' => $member['role'], 'agent_id' => $agentId, 'error' => $result['error']];
                $this->backgroundAgentManager->markError($agentId, $result['error']);
                $this->taskManager->update($agentId, 'completed', 'Failed to spawn: ' . $result['error']);
            }
        }

        // Build result summary
        $lines = ["Team '{$name}' created with " . count($team['members']) . " members.\n"];
        $lines[] = "Objective: {$task}\n";

        if (!empty($spawned)) {
            $lines[] = 'Spawned:';
            foreach ($spawned as $s) {
                $lines[] = "  - {$s['role']} [{$s['agent_id']}] PID {$s['pid']}";
            }
        }

        if (!empty($failed)) {
            $lines[] = "\nFailed to spawn:";
            foreach ($failed as $f) {
                $lines[] = "  - {$f['role']} [{$f['agent_id']}]: {$f['error']}";
            }
        }

        $lines[] = "\nTo message a member: SendMessage with `to: \"{agentId}\"`";
        $lines[] = "To broadcast to all: SendMessage with `to: \"team:{$name}\"`";
        $lines[] = "To inspect: TeamList with `name: \"{$name}\"`";

        return ToolResult::success(
            implode("\n", $lines),
            ['teamName' => $name, 'spawned' => count($spawned), 'failed' => count($failed)],
        );
    }

    /**
     * @param  array<int, array{role: string, agent_id: string, agent_type: string}>  $members
     */
    private function buildRoster(array $members): string
    {
        $lines = [];
        foreach ($members as $m) {
            $lines[] = "- {$m['role']} (agent_id: {$m['agent_id']}, type: {$m['agent_type']})";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function buildMemberPrompt(
        array $member,
        string $teamName,
        string $task,
        string $roster,
    ): string {
        return <<<PREAMBLE
You are the "{$member['role']}" member of team "{$teamName}".

Your teammates:
{$roster}

Team objective: {$task}

Your role-specific instructions:
{$member['prompt']}
PREAMBLE;
    }

    /**
     * @return array{success: bool, pid?: int, error?: string}
     */
    private function forkMember(
        string $agentId,
        string $teamName,
        string $prompt,
        AgentDefinition $agentDef,
        ?string $model,
        ToolUseContext $context,
        bool $readOnly,
        ?int $maxTurns,
    ): array
    {
        if (!function_exists('pcntl_fork')) {
            return ['success' => false, 'error' => 'pcntl_fork not available'];
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return ['success' => false, 'error' => 'pcntl_fork() failed'];
        }

        if ($pid === 0) {
            // Child process: run the background agent
            try {
                $this->executeBackgroundAgent(
                    $agentId,
                    $teamName,
                    $prompt,
                    $agentDef,
                    $model,
                    $context,
                    $readOnly,
                    $maxTurns,
                );
            } catch (\HaoCode\Sdk\HumanInterruptException) {
                // Durable child interrupt is surfaced by TeamAwait/TeamCollect.
            } catch (\Throwable $e) {
                $this->backgroundAgentManager->markError($agentId, $e->getMessage());
                $this->taskManager->update($agentId, 'completed', 'Agent error: ' . $e->getMessage());
            }
            exit(0);
        }

        return ['success' => true, 'pid' => $pid];
    }

    private function executeBackgroundAgent(
        string $agentId,
        string $teamName,
        string $prompt,
        AgentDefinition $agentDef,
        ?string $model,
        ToolUseContext $context,
        bool $readOnly,
        ?int $maxTurns,
    ): void
    {
        $subLoop = $this->agentLoopFactory->createIsolated(
            toolFilter: fn (string $toolName) => $agentDef->isToolAllowed($toolName),
            workingDirectory: $context->workingDirectory,
            streamingClient: $context->provider,
            runContext: $context->runContext?->fork(
                workingDirectory: $context->workingDirectory,
                readOnly: $readOnly || $agentDef->readOnly,
                interruptOn: $agentDef->interruptOn,
                agentId: $agentId,
                teamName: $teamName,
                backgroundOwnerAgentId: $agentId,
            ),
            afterFork: true,
            readOnly: $readOnly || $agentDef->readOnly,
            parentToolRegistry: $context->toolRegistry,
            model: $model,
            appendSystemPrompt: $agentDef->systemPrompt,
            omitProjectInstructions: $agentDef->omitClaudeMd,
            agentType: $agentDef->agentType,
        );
        $effectiveMaxTurns = $maxTurns ?? $agentDef->maxTurns;
        if ($effectiveMaxTurns !== null) {
            $subLoop->setMaxTurns($effectiveMaxTurns);
        }

        $this->backgroundAgentManager->markRunning($agentId);
        $this->taskManager->transition(
            $agentId,
            ['pending'],
            'in_progress',
            'Processing initial task.',
        );

        $lastResponse = $this->runTurn($subLoop, $agentId, $prompt);
        $idleSince = time();
        $idleTimeout = max(30, (int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.background_agent_idle_timeout', 300));
        $pollMicros = max(100_000, ((int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.background_agent_poll_interval_ms', 250)) * 1000);

        while (true) {
            if ($this->backgroundAgentManager->isStopRequested($agentId)) {
                $this->backgroundAgentManager->markCompleted($agentId, $lastResponse);
                $this->taskManager->update($agentId, 'completed', 'Stopped.');

                return;
            }

            $message = $this->backgroundAgentManager->popNextMessage($agentId);
            if ($message !== null) {
                $idleSince = time();
                $header = 'Follow-up from ' . ($message['from'] ?? 'controller');
                if (!empty($message['summary'])) {
                    $header .= " ({$message['summary']})";
                }
                $response = $this->runTurn($subLoop, $agentId, $header . ":\n" . trim((string) ($message['message'] ?? '')));
                if ($response !== null) {
                    $lastResponse = $response;
                }

                continue;
            }

            if ((time() - $idleSince) >= $idleTimeout) {
                $this->backgroundAgentManager->markCompleted($agentId, $lastResponse);
                $this->taskManager->update($agentId, 'completed', 'Idle timeout reached.');

                return;
            }

            usleep($pollMicros);
        }
    }

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
