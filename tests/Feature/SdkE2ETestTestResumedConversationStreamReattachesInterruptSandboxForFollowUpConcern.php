<?php

declare(strict_types=1);

namespace Tests\Feature;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Sdk\StructuredResultValidationException;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

trait SdkE2ETestTestResumedConversationStreamReattachesInterruptSandboxForFollowUpConcern
{

    public function test_resumed_conversation_stream_reattaches_interrupt_sandbox_for_follow_up(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('resumed-conversation-stream-write', 'Write', [
                'file_path' => 'before-stream-interrupt.txt',
                'content' => 'resumed conversation stream sandbox state',
            ]),
            MockAnthropicSse::toolUseResponse('resumed-conversation-stream-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
            MockAnthropicSse::textResponse('Resumed conversation stream completed.'),
            MockAnthropicSse::toolUseResponse('resumed-conversation-stream-read', 'Read', [
                'file_path' => 'before-stream-interrupt.txt',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'resumed conversation stream sandbox state',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );

                return MockAnthropicSse::textResponse('Resumed conversation stream follow-up completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write', 'Read', 'AskUserQuestion'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            enableAskUser: true,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );

        try {
            HaoCode::query('Write a file, then ask whether to continue.', $config);
            $this->fail('Expected a durable interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $conversation = HaoCode::resume($interrupt->sessionId, $config);
        try {
            $messages = iterator_to_array($conversation->streamResumeInterrupt(
                $interrupt->id,
                [HumanDecision::respond('resumed-conversation-stream-ask', [
                    'status' => 'answered',
                    'answers' => ['yes'],
                ])],
            ));
            $results = array_values(array_filter(
                $messages,
                static fn (Message $message): bool => $message->isResult(),
            ));
            $followUp = $conversation->send('Read the file written before the interrupt.');
            $conversation->close();

            $this->assertCount(1, $results);
            $this->assertSame('Resumed conversation stream completed.', $results[0]->text);
            $this->assertSame('Resumed conversation stream follow-up completed.', $followUp->text);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($messages);
            gc_collect_cycles();
            $conversation->close();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_resumed_conversation_stream_cleans_fresh_sandbox_after_a_second_interrupt(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('loaded-stream-first-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
            MockAnthropicSse::toolUseResponse('loaded-stream-second-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue again?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
        ]);
        chdir($this->projectDir);
        $initialConfig = new HaoCodeConfig(
            allowedTools: ['AskUserQuestion'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            enableAskUser: true,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );

        try {
            HaoCode::query('Ask whether to continue.', $initialConfig);
            $this->fail('Expected a durable interrupt.');
        } catch (HumanInterruptException $e) {
            $firstInterrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($firstInterrupt->sessionId, $firstInterrupt->id);
        $checkpointRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($checkpointRoot);

        $freshRoot = sys_get_temp_dir().'/haocode-resumed-stream-'.bin2hex(random_bytes(4));
        $resumeConfig = new HaoCodeConfig(
            allowedTools: ['AskUserQuestion'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            enableAskUser: true,
            sandbox: new SandboxConfig(
                cleanup: 'always',
                root: $freshRoot,
                options: ['owns_root' => true],
            ),
        );
        $conversation = HaoCode::resume($firstInterrupt->sessionId, $resumeConfig);

        try {
            $messages = iterator_to_array($conversation->streamResumeInterrupt(
                $firstInterrupt->id,
                [HumanDecision::respond('loaded-stream-first-ask', [
                    'status' => 'answered',
                    'answers' => ['yes'],
                ])],
            ));
            $interrupts = array_values(array_filter(
                $messages,
                static fn (Message $message): bool => $message->isInterrupt(),
            ));

            $this->assertCount(1, $interrupts);
            $this->assertSame('loaded-stream-second-ask', $interrupts[0]->interrupt?->actions[0]->id);
            $this->assertDirectoryExists($freshRoot);
            $conversation->close();

            $this->assertDirectoryDoesNotExist($freshRoot);
            $this->assertDirectoryExists($checkpointRoot);
        } finally {
            unset($messages);
            gc_collect_cycles();
            $conversation->close();
            if (is_dir($freshRoot)) {
                $this->removeDirectory($freshRoot);
            }
            if (is_dir($checkpointRoot)) {
                $this->removeDirectory($checkpointRoot);
            }
        }
    }

    public function test_background_owner_completes_only_after_parent_interrupt_chain_finishes(): void
    {
        $backgroundAgents = null;
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('parent-write', 'Write', [
                'file_path' => 'parent.txt',
                'content' => 'parent',
            ]),
            MockAnthropicSse::toolUseResponse('child-write', 'Write', [
                'file_path' => 'child.txt',
                'content' => 'child',
            ]),
            MockAnthropicSse::textResponse('Background child completed.'),
            function () use (&$backgroundAgents): MockResponse {
                $this->assertNotNull($backgroundAgents);
                $this->assertSame(
                    'waiting_for_input',
                    $backgroundAgents->get('agent_chain')['status'] ?? null,
                );

                return MockAnthropicSse::textResponse('Parent chain completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Start the parent interrupt', $config);
            $this->fail('Expected parent interrupt.');
        } catch (HumanInterruptException $e) {
            $parentInterrupt = $e->interrupt;
        }

        $backgroundAgents = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Agent\BackgroundAgentManager::class,
        );
        $backgroundAgents->create('agent_chain', 'Run child', 'general-purpose');
        $childContext = \HaoCode\Sdk\AgentRunContextFactory::make($config)->fork(
            agentId: 'agent_chain',
            backgroundOwnerAgentId: 'agent_chain',
        );
        $childLoop = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Agent\AgentLoopFactory::class,
        )->createIsolated(
            toolFilter: $config->toolFilter(),
            workingDirectory: $this->projectDir,
            streamingClient: \HaoCode\Support\Runtime\SdkRuntime::app(StreamingClient::class),
            runContext: $childContext,
            ephemeral: false,
        );

        try {
            $childLoop->run('Start the background child interrupt');
            $this->fail('Expected child interrupt.');
        } catch (HumanInterruptException $e) {
            $childInterrupt = $e->interrupt;
        }

        $backgroundAgents->markWaitingForInput('agent_chain', $childInterrupt);
        \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->recordInterruptParentLink(
            $childInterrupt->sessionId,
            $childInterrupt->id,
            $parentInterrupt->sessionId,
            $parentInterrupt->id,
            'parent-write',
        );

        $result = HaoCode::resumeInterrupt(
            $childInterrupt->sessionId,
            $childInterrupt->id,
            [HumanDecision::approve('child-write')],
            $config,
        );

        $this->assertSame('Parent chain completed.', $result->text);
        $this->assertSame('completed', $backgroundAgents->get('agent_chain')['status'] ?? null);
    }

    public function test_inline_skill_scope_survives_durable_interrupt_resume(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('scope-skill', 'Skill', [
                'skill' => 'scoped-shell',
            ]),
            function (array $payload): MockResponse {
                $this->assertSame(
                    ['Skill', 'Bash'],
                    array_values(array_intersect(
                        ['Skill', 'Bash'],
                        array_column($payload['tools'] ?? [], 'name'),
                    )),
                );
                $this->assertNotContains('Write', array_column($payload['tools'] ?? [], 'name'));

                return MockAnthropicSse::toolUseResponse('scope-bash', 'Bash', [
                    'command' => 'printf scoped',
                    'description' => 'Run scoped command',
                ]);
            },
            function (array $payload): MockResponse {
                $toolNames = array_column($payload['tools'] ?? [], 'name');
                $this->assertContains('Bash', $toolNames);
                $this->assertNotContains('Write', $toolNames);

                return MockAnthropicSse::textResponse('Scoped resume completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Skill', 'Bash', 'Write'],
            skills: [
                new SdkSkill(
                    name: 'scoped-shell',
                    description: 'Run a read-only shell check',
                    prompt: 'Run the requested shell check.',
                    allowedTools: ['Bash'],
                ),
            ],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Use the scoped shell skill', $config);
            $this->fail('Expected scoped Bash interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $this->assertSame(
            ['Bash', 'Skill'],
            $state['checkpoint']['allowed_tools'],
        );
        $this->assertSame(
            ['Bash'],
            $state['checkpoint']['run_snapshot']['active_skill_allowed_tools'],
        );

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('scope-bash')],
            $config,
        );

        $this->assertSame('Scoped resume completed.', $result->text);
    }

    public function test_resume_applies_inline_skill_model_when_skill_and_gate_share_a_turn(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                [
                    'id' => 'model-scope-skill',
                    'name' => 'Skill',
                    'input' => ['skill' => 'model-scoped-shell'],
                ],
                [
                    'id' => 'model-scope-bash',
                    'name' => 'Bash',
                    'input' => [
                        'command' => 'printf resumed',
                        'description' => 'Run the model-scoped check',
                    ],
                ],
            ]),
            function (array $payload): MockResponse {
                $this->assertSame('skill-model', $payload['model'] ?? null);

                return MockAnthropicSse::textResponse('Model-scoped resume completed.');
            },
        ], model: 'parent-model');
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Skill', 'Bash'],
            skills: [
                new SdkSkill(
                    name: 'model-scoped-shell',
                    description: 'Run a model-scoped shell check',
                    prompt: 'Run the requested shell check.',
                    allowedTools: ['Bash'],
                    model: 'skill-model',
                ),
            ],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Use the model-scoped shell skill', $config);
            $this->fail('Expected scoped Bash interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $snapshot = $state['checkpoint']['run_snapshot'];
        $this->assertSame('parent-model', $snapshot['model']);
        $this->assertSame('parent-model', $snapshot['base_model']);
        $this->assertSame('skill-model', $snapshot['active_skill_model_override']);

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('model-scope-bash')],
            $config,
        );

        $this->assertSame('Model-scoped resume completed.', $result->text);
    }

    public function test_cost_and_usage_totals_continue_across_durable_interrupt_resume(): void
    {
        $model = 'claude-sonnet-4-6';
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse(
                'budget-write',
                'Write',
                ['file_path' => 'budget.txt', 'content' => 'ok'],
                model: $model,
            ),
            MockAnthropicSse::textResponse('Budget resume completed.', model: $model),
        ], model: $model);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            maxBudgetUsd: 1.0,
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Write within the budget', $config);
            $this->fail('Expected budgeted write interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $snapshot = $state['checkpoint']['run_snapshot'];
        $this->assertGreaterThan(0.0, $snapshot['estimated_cost_usd']);
        $this->assertSame(64, $snapshot['total_input_tokens']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $snapshot['budget_ledger_id']);
        $this->assertEquals(1.0, $snapshot['budget_limit_usd']);

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('budget-write')],
            $config,
        );

        $this->assertGreaterThan($snapshot['estimated_cost_usd'], $result->cost);
        $this->assertGreaterThan($snapshot['total_input_tokens'], $result->usage['input_tokens']);
        $this->assertTrue($result->usage['cost_available']);
    }
}
