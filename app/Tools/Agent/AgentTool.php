<?php

namespace HaoCode\Tools\Agent;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class AgentTool extends BaseTool
{
    public function __construct(
        private readonly AgentLoopFactory $agentLoopFactory,
        private readonly ?BackgroundAgentManager $backgroundAgentManager = null,
        private readonly ?TaskManager $taskManager = null,
    ) {}

    public function name(): string
    {
        return 'Agent';
    }

    public function description(): string
    {
        $agentDescriptions = BuiltInAgents::descriptionBlock();

        return <<<DESC
Launch a specialized sub-agent to handle a specific task autonomously.

Available agent types:
{$agentDescriptions}

The sub-agent runs in isolation with its own context and returns a final result.
Use agents to parallelize work, keep the main context clean, or delegate focused tasks.

Usage notes:
- Always include a short description (3-5 words) summarizing what the agent will do
- Launch multiple agents concurrently whenever possible, to maximize performance
- When the agent is done, it will return a single message back to you
- You can optionally run agents in the background using the run_in_background parameter
- To continue a previously spawned agent, use SendMessage with the agent's ID or name
- Provide clear, detailed prompts so the agent can work autonomously
- Set isolation: "worktree" to run the agent in an isolated git worktree copy
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        // Build enum from all known agent types
        $agentTypes = array_keys(AgentLoader::loadAll(getcwd()));

        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The task for the agent to perform',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'A short (3-5 word) description of the task',
                ],
                'subagent_type' => [
                    'type' => 'string',
                    'description' => 'The type of specialized agent to use for this task',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional model override for this agent (sonnet, opus, haiku, inherit)',
                    'enum' => ['sonnet', 'opus', 'haiku', 'inherit'],
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to run this agent in the background',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional name for addressing via SendMessage',
                ],
                'isolation' => [
                    'type' => 'string',
                    'description' => 'Isolation mode. "worktree" creates a temporary git worktree.',
                    'enum' => ['worktree'],
                ],
            ],
            'required' => ['description', 'prompt'],
        ], [
            'prompt' => 'required|string|min:5',
            'description' => 'required|string',
            'subagent_type' => 'nullable|string',
            'model' => 'nullable|string|in:sonnet,opus,haiku,inherit',
            'run_in_background' => 'nullable|boolean',
            'name' => 'nullable|string|regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
            'isolation' => 'nullable|string|in:worktree',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $prompt = $input['prompt'];
        $hasExplicitAgentType = array_key_exists('subagent_type', $input)
            && $input['subagent_type'] !== null;
        $agentTypeName = $hasExplicitAgentType
            ? trim((string) $input['subagent_type'])
            : 'general-purpose';

        // Resolve agent definition
        $allAgents = AgentLoader::loadAll($context->workingDirectory);
        $agentDef = $allAgents[$agentTypeName] ?? null;
        if ($agentDef === null) {
            return ToolResult::error("Unknown agent type: {$agentTypeName}");
        }
        try {
            $model = AgentModelResolver::resolve(
                $input['model'] ?? null,
                $agentDef->model,
                $context->runContext?->settings->getProviderType() ?? 'anthropic',
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        // Check if agent should always run in background
        $background = $input['run_in_background'] ?? $agentDef->background;

        // Handle worktree isolation
        $worktreePath = null;
        $worktreeBranch = null;
        if (($input['isolation'] ?? null) === 'worktree') {
            $worktreeResult = $this->createWorktree($context->workingDirectory);
            if ($worktreeResult instanceof ToolResult) {
                return $worktreeResult; // Error creating worktree
            }
            $worktreePath = $worktreeResult['path'];
            $worktreeBranch = $worktreeResult['branch'];
        }

        if ($background) {
            return $this->runInBackground(
                prompt: $prompt,
                agentDef: $agentDef,
                model: $model,
                description: $input['description'] ?? null,
                name: $input['name'] ?? null,
                context: $context,
                worktreePath: $worktreePath,
                worktreeBranch: $worktreeBranch,
            );
        }

        $result = $this->runSync(
            $prompt,
            $agentDef,
            $model,
            $context,
            $worktreePath,
            $worktreeBranch,
        );

        return $worktreePath === null || $worktreeBranch === null
            ? $result
            : $this->finalizeSyncWorktree($result, $worktreePath, $worktreeBranch);
    }

    private function runSync(
        string $prompt,
        AgentDefinition $agentDef,
        ?string $model,
        ToolUseContext $context,
        ?string $worktreePath = null,
        ?string $worktreeBranch = null,
    ): ToolResult {
        try {
            $subLoop = $this->agentLoopFactory->createIsolated(
                toolFilter: fn(string $toolName) => $agentDef->isToolAllowed($toolName),
                workingDirectory: $worktreePath ?? $context->workingDirectory,
                streamingClient: $context->provider,
                runContext: $context->runContext?->fork(
                    workingDirectory: $worktreePath ?? $context->workingDirectory,
                    readOnly: $agentDef->readOnly,
                    interruptOn: $agentDef->interruptOn,
                    worktreePath: $worktreePath,
                    worktreeBranch: $worktreeBranch,
                    managedWorktree: $worktreePath !== null && $worktreeBranch !== null,
                    projectDirectory: $worktreePath,
                    inheritAgentId: false,
                ),
                readOnly: $agentDef->readOnly,
                parentToolRegistry: $context->toolRegistry,
                model: $model,
                appendSystemPrompt: $agentDef->systemPrompt,
                omitProjectInstructions: $agentDef->omitClaudeMd,
                agentType: $agentDef->agentType,
            );
            if ($agentDef->maxTurns !== null) {
                $subLoop->setMaxTurns($agentDef->maxTurns);
            }

            $result = $subLoop->run(
                userInput: $prompt,
                onTextDelta: null,
            );

            return ToolResult::success($result, [
                'inputTokens' => $subLoop->getLocalInputTokens(),
                'outputTokens' => $subLoop->getLocalOutputTokens(),
                'cost' => $subLoop->getLocalEstimatedCost(),
            ]);
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ToolResult::error("Sub-agent error: " . $e->getMessage());
        }
    }

    private function runInBackground(
        string $prompt,
        AgentDefinition $agentDef,
        ?string $model,
        ?string $description,
        ?string $name,
        ToolUseContext $context,
        ?string $worktreePath = null,
        ?string $worktreeBranch = null,
    ): ToolResult {
        if (!function_exists('pcntl_fork')) {
            $result = $this->runSync(
                $prompt,
                $agentDef,
                $model,
                $context,
                $worktreePath,
                $worktreeBranch,
            );

            return $worktreePath === null || $worktreeBranch === null
                ? $result
                : $this->finalizeSyncWorktree($result, $worktreePath, $worktreeBranch);
        }

        $subject = $description ?: ucfirst($agentDef->agentType) . ' background agent';
        $claim = $this->claimBackgroundAgent(
            name: $name,
            prompt: $prompt,
            agentDef: $agentDef,
            description: $description,
            subject: $subject,
            worktreePath: $worktreePath,
            worktreeBranch: $worktreeBranch,
        );
        if ($claim instanceof ToolResult) {
            return $worktreePath === null || $worktreeBranch === null
                ? $claim
                : $this->finalizeSyncWorktree($claim, $worktreePath, $worktreeBranch);
        }
        $taskId = $claim;

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->tasks()->remove($taskId);
            $this->backgroundAgents()->delete($taskId);

            $result = $this->runSync(
                $prompt,
                $agentDef,
                $model,
                $context,
                $worktreePath,
                $worktreeBranch,
            );

            return $worktreePath === null || $worktreeBranch === null
                ? $result
                : $this->finalizeSyncWorktree($result, $worktreePath, $worktreeBranch);
        }

        if ($pid === 0) {
            $waitingForInput = false;
            try {
                $this->executeBackgroundAgent($taskId, $prompt, $agentDef, $model, $context, $worktreePath);
            } catch (\HaoCode\Sdk\HumanInterruptException) {
                // The child checkpoint is durable; the controller will surface it.
                $waitingForInput = true;
            } catch (\Throwable $e) {
                $this->backgroundAgents()->markError($taskId, $e->getMessage());
                $this->tasks()->update($taskId, 'completed', 'Background agent error: ' . $e->getMessage());
            } finally {
                if (! $waitingForInput && $worktreePath !== null && $worktreeBranch !== null) {
                    $this->finalizeBackgroundWorktree($taskId);
                }
            }
            exit(0);
        }

        $this->backgroundAgents()->attachProcess($taskId, $pid);
        $this->tasks()->transition(
            $taskId,
            ['pending'],
            'in_progress',
            'Background agent is running.',
        );

        return ToolResult::success(
            "Background agent started: {$taskId} (PID: {$pid})\n" .
            "Type: {$agentDef->agentType}\n" .
            "Prompt: {$prompt}\n" .
            "Use SendMessage with `to: {$taskId}` to continue it.\n" .
            "Use TaskGet or /tasks to inspect it.",
            [
                'taskId' => $taskId,
                'agentId' => $taskId,
                'pid' => $pid,
            ],
        );
    }

    private function claimBackgroundAgent(
        ?string $name,
        string $prompt,
        AgentDefinition $agentDef,
        ?string $description,
        string $subject,
        ?string $worktreePath,
        ?string $worktreeBranch,
    ): string|ToolResult {
        $attempts = $name === null ? 10 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $taskId = $name ?? ('agent_'.bin2hex(random_bytes(4)));
            try {
                // Claim the file-backed agent ID first. Task creation is also
                // lock-protected; roll the claim back if its ID is occupied.
                $this->backgroundAgents()->create(
                    id: $taskId,
                    prompt: $prompt,
                    agentType: $agentDef->agentType,
                    description: $description,
                    worktreePath: $worktreePath,
                    worktreeBranch: $worktreeBranch,
                );
                try {
                    $this->tasks()->createWithId(
                        id: $taskId,
                        subject: $subject,
                        activeForm: 'Running background agent',
                        description: $prompt,
                    );
                } catch (\Throwable $e) {
                    $this->backgroundAgents()->delete($taskId);
                    throw $e;
                }

                return $taskId;
            } catch (\InvalidArgumentException $e) {
                if ($name !== null) {
                    return ToolResult::error($e->getMessage());
                }
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to create background agent: {$e->getMessage()}");
            }
        }

        return ToolResult::error('Unable to allocate a unique background agent ID.');
    }

    public function isReadOnly(array $input): bool
    {
        if (($input['isolation'] ?? null) === 'worktree') {
            return false;
        }

        $agentType = $input['subagent_type'] ?? 'general-purpose';
        $projectDirectory = is_string($input['__haocode_project_directory'] ?? null)
            ? $input['__haocode_project_directory']
            : (getcwd() ?: '/');
        $agentDef = AgentLoader::loadAll($projectDirectory)[$agentType] ?? null;

        return $agentDef?->readOnly ?? false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return $this->isReadOnly($input) && ($input['run_in_background'] ?? false) === true;
    }

    public function userFacingName(array $input): string
    {
        $name = $input['description'] ?? null;
        if (empty($name)) {
            $name = $input['subagent_type'] ?? 'Agent';
        }

        return $name . ' agent';
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        $input['__haocode_project_directory'] = $context->workingDirectory;

        return $input;
    }

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
            model: $model,
            appendSystemPrompt: $agentDef->systemPrompt,
            omitProjectInstructions: $agentDef->omitClaudeMd,
            agentType: $agentDef->agentType,
        );
        if ($agentDef->maxTurns !== null) {
            $subLoop->setMaxTurns($agentDef->maxTurns);
        }

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
            $response = $subLoop->run(userInput: $prompt, onTextDelta: null);
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

    // ─── Worktree support ───────────────────────────────────────────

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

        return new ToolResult(
            $result->output."\n\n".$notice,
            $result->isError,
            ($result->metadata ?? []) + [
                'worktreePath' => $worktreePath,
                'worktreeBranch' => $worktreeBranch,
                'worktreeRetained' => true,
            ],
        );
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
