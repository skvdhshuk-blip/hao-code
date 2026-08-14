<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Tools\ToolResult;

trait SupportOpsAgentConstructConcern
{

    public function __construct(
        private readonly string $workspaceDir,
        ?callable $writer = null,
    ) {
        $this->writer = $writer ?? static fn (string $chunk): int => print $chunk;
        $this->abortController = new AbortController;
        $this->runbookNotesTool = new RunbookNotesTool;
        $this->tools = [
            new GetEscalationTicketTool,
            new GetServiceHealthTool,
            new GetDeploymentWindowTool,
            $this->runbookNotesTool,
        ];
        $this->skills = [
            new SdkSkill(
                name: 'triage-incident',
                description: 'Run structured incident triage for a sev-level production issue',
                prompt: <<<'PROMPT'
Triage incident $ARGUMENTS.

Use GetEscalationTicket, GetServiceHealth, and RunbookNotes as needed.
Focus on blast radius, likely root cause, customer impact, and the first safe mitigation.
If you add a note, keep it short and operational.
PROMPT,
                allowedTools: [
                    'Skill',
                    'Write',
                    'Read',
                    'GetEscalationTicket',
                    'GetServiceHealth',
                    'GetDeploymentWindow',
                    'RunbookNotes',
                ],
            ),
        ];

        $this->ensureWorkspace();
        $this->registerAbortHandler();
    }

    /**
     * @return array{
     *   workspace_dir: string,
     *   plan: QueryResult,
     *   stream_events: list<string>,
     *   conversation_session_id: string|null,
     *   stakeholder_update: QueryResult,
     *   executive_summary: QueryResult,
     *   next_action: QueryResult,
     *   handoff: StructuredResult,
     *   conversation_stream_events: list<string>
     * }
     */
    public function run(): array
    {
        $this->line("=== Support Ops Agent ===\n");
        $this->line("Workspace: {$this->workspaceDir}");
        $this->line('Press Ctrl-C to trigger the AbortController if you want to cancel a long run.');

        $plan = $this->runPlanningQuery();
        $streamEvents = $this->runStreamingTriage();
        [$conversationSessionId, $conversationStreamEvents] = $this->runConversationWorkflow();
        $stakeholderUpdate = $this->resumeStakeholderUpdate($conversationSessionId);
        $executiveSummary = $this->resumeInlineExecutiveSummary($conversationSessionId);
        $nextAction = $this->continueLatestNextAction();
        $handoff = $this->extractStructuredHandoff($conversationSessionId);

        $this->section('Final Summary');
        $this->line('Executive handoff owner: '.$handoff->owner);
        $this->line('Next action: '.$handoff->next_action);
        $this->line('Handoff JSON: '.$handoff->toJson());

        return [
            'workspace_dir' => $this->workspaceDir,
            'plan' => $plan,
            'stream_events' => $streamEvents,
            'conversation_session_id' => $conversationSessionId,
            'stakeholder_update' => $stakeholderUpdate,
            'executive_summary' => $executiveSummary,
            'next_action' => $nextAction,
            'handoff' => $handoff,
            'conversation_stream_events' => $conversationStreamEvents,
        ];
    }

    private function runPlanningQuery(): QueryResult
    {
        $this->section('1. Planning Query');

        $result = HaoCode::query(
            'Investigate incident INC-2047. Use GetEscalationTicket and GetServiceHealth, then write incident-plan.md with the first three mitigation actions.',
            $this->makeConfig(
                onText: function (string $delta): void {
                    $this->line('[callback] text delta: '.strlen($delta).' chars');
                },
                onToolStart: function (string $name, array $input): void {
                    $this->line('[callback] tool start: '.$name.' '.json_encode($input, JSON_UNESCAPED_SLASHES));
                },
                onToolComplete: function (string $name, ToolResult $result): void {
                    $status = $result->isError ? 'error' : 'ok';
                    $this->line('[callback] tool done: '.$name.' ('.$status.')');
                },
                onTurnStart: function (int $turn): void {
                    $this->line('[callback] turn '.$turn.' started');
                },
            ),
        );

        $this->line('Plan result: '.$result->text);
        $this->line(sprintf(
            'Plan metrics: %d input tokens, %d output tokens, $%.5f',
            $result->inputTokens(),
            $result->outputTokens(),
            $result->cost,
        ));
        $this->line('Plan session: '.($result->sessionId ?? 'n/a'));

        return $result;
    }

    /**
     * @return list<string>
     */
    private function runStreamingTriage(): array
    {
        $this->section('2. Streaming Triage');

        $events = [];
        foreach (HaoCode::stream(
            'Use the triage-incident skill for INC-2047 and narrate the current blast radius.',
            $this->makeConfig(),
        ) as $message) {
            $events[] = $message->type;

            match ($message->type) {
                'text' => $this->write('[stream] '.$message->text),
                'tool_start' => $this->line('[stream] tool start: '.$message->toolName),
                'tool_result' => $this->line('[stream] tool result: '.$message->toolName),
                'result' => $this->line('[stream] final cost: $'.number_format((float) $message->cost, 5)),
                'error' => $this->line('[stream] error: '.$message->error),
                default => null,
            };
        }

        $this->write("\n");

        return $events;
    }

    /**
     * @return array{0: string|null, 1: list<string>}
     */
    private function runConversationWorkflow(): array
    {
        $this->section('3. Multi-turn Conversation');

        $conversation = HaoCode::conversation($this->makeConfig(
            appendSystemPrompt: 'Act like a calm incident commander. Prefer low-risk mitigations and explicit next steps.',
        ));

        $turnOne = $conversation->send(
            'Add the top hypotheses to the runbook and explain why they are the most likely causes.'
        );
        $this->line('Turn 1: '.$turnOne->text);

        $streamEvents = [];
        foreach ($conversation->stream(
            'Check deployment safety, update the runbook with the recommended action, and stream your answer.'
        ) as $message) {
            $streamEvents[] = $message->type;

            match ($message->type) {
                'text' => $this->write('[conversation stream] '.$message->text),
                'tool_start' => $this->line('[conversation stream] tool start: '.$message->toolName),
                'tool_result' => $this->line('[conversation stream] tool result: '.$message->toolName),
                'result' => $this->line('[conversation stream] done'),
                'error' => $this->line('[conversation stream] error: '.$message->error),
                default => null,
            };
        }

        $this->write("\n");
        $this->line('Conversation turns: '.$conversation->getTurnCount());
        $this->line('Conversation cost: $'.number_format($conversation->getCost(), 5));

        $sessionId = $conversation->getSessionId();
        $conversation->close();

        return [$sessionId, $streamEvents];
    }

    private function resumeStakeholderUpdate(?string $sessionId): QueryResult
    {
        $this->section('4. Resume Previous Session');

        if ($sessionId === null) {
            throw new \RuntimeException('Conversation did not produce a session ID.');
        }

        $conversation = HaoCode::resume($sessionId, $this->makeConfig());
        $result = $conversation->send('List the runbook notes and draft a stakeholder update.');
        $conversation->close();

        $this->line('Stakeholder update: '.$result->text);

        return $result;
    }

    private function continueLatestNextAction(): QueryResult
    {
        $this->section('6. Continue Latest Session');

        $conversation = HaoCode::continueLatest($this->workspaceDir, $this->makeConfig());
        $result = $conversation->send('What is the single next action?');
        $conversation->close();

        $this->line('Next action: '.$result->text);

        return $result;
    }

    private function extractStructuredHandoff(?string $sessionId): StructuredResult
    {
        $this->section('7. Structured Handoff');

        if ($sessionId === null) {
            throw new \RuntimeException('Cannot build structured handoff without a session ID.');
        }

        $handoff = HaoCode::structured(
            'Convert the current incident state into a compact JSON handoff for the next shift.',
            [
                'type' => 'object',
                'properties' => [
                    'severity' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'next_action' => ['type' => 'string'],
                    'customer_message' => ['type' => 'string'],
                    'deploy_safe' => ['type' => 'boolean'],
                ],
                'required' => ['severity', 'owner', 'next_action', 'customer_message', 'deploy_safe'],
            ],
            $this->makeConfig(sessionId: $sessionId),
        );

        $this->line('Structured severity: '.$handoff->severity);
        $this->line('Structured owner: '.$handoff->owner);

        return $handoff;
    }

    private function resumeInlineExecutiveSummary(?string $sessionId): QueryResult
    {
        $this->section('5. Inline Session Resume');

        if ($sessionId === null) {
            throw new \RuntimeException('Cannot build executive summary without a session ID.');
        }

        $result = HaoCode::query(
            'Give me a one-line executive summary.',
            $this->makeConfig(sessionId: $sessionId),
        );

        $this->line('Executive summary: '.$result->text);

        return $result;
    }

    private function makeConfig(
        ?string $sessionId = null,
        bool $continueSession = false,
        ?callable $onText = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
        ?string $appendSystemPrompt = null,
    ): HaoCodeConfig {
        return new HaoCodeConfig(
            cwd: $this->workspaceDir,
            maxTurns: 8,
            maxBudgetUsd: 2.0,
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            appendSystemPrompt: $appendSystemPrompt,
            allowedTools: [
                'Write',
                'Read',
                'Skill',
                'GetEscalationTicket',
                'GetServiceHealth',
                'GetDeploymentWindow',
                'RunbookNotes',
            ],
            disallowedTools: ['Bash'],
            onText: $onText,
            onToolStart: $onToolStart,
            onToolComplete: $onToolComplete,
            onTurnStart: $onTurnStart,
            tools: $this->tools,
            skills: $this->skills,
            abortController: $this->abortController,
            sessionId: $sessionId,
            continueSession: $continueSession,
        );
    }

    private function ensureWorkspace(): void
    {
        if (! is_dir($this->workspaceDir)) {
            mkdir($this->workspaceDir, 0755, true);
        }
    }
}
