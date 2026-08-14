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

trait SdkE2ETestTestAskUserValidatesAnswersBeforeClaimingCheckpointConcern
{

    public function test_ask_user_validates_answers_before_claiming_checkpoint(): void
    {
        $requests = [];
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('ask-1', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Choose environment',
                    'type' => 'multiple_choice',
                    'options' => ['staging', 'production'],
                    'required' => true,
                ]],
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString('staging', (string) MockAnthropicSse::lastToolResultText($payload));
                return MockAnthropicSse::textResponse('Environment selected: staging.');
            },
        ], $requests);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            ephemeral: false,
            enableAskUser: true,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        try {
            HaoCode::query('Ask me first', $config);
            $this->fail('Expected AskUser interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }
        $this->assertSame(['respond', 'reject'], $interrupt->actions[0]->allowedDecisions);
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);
        $this->assertDirectoryExists($sandboxRoot);

        try {
            HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
                HumanDecision::respond('ask-1', ['status' => 'answered', 'answers' => ['invalid']]),
            ], $config);
            $this->fail('Expected answer validation failure.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('allowed options', $e->getMessage());
        }
        $this->assertDirectoryExists(
            $sandboxRoot,
            'Invalid decisions must not open and clean a still-pending sandbox lease.',
        );

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::respond('ask-1', ['status' => 'answered', 'answers' => ['staging']]),
        ], $config);
        $this->assertSame('Environment selected: staging.', $result->text);
        $this->assertDirectoryDoesNotExist($sandboxRoot);
    }

    public function test_durable_hitl_resume_reattaches_the_same_sandbox_filesystem(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('sandbox-write', 'Write', [
                'file_path' => 'before-interrupt.txt',
                'content' => 'durable sandbox state',
            ]),
            MockAnthropicSse::toolUseResponse('sandbox-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
            MockAnthropicSse::toolUseResponse('sandbox-read', 'Read', [
                'file_path' => 'before-interrupt.txt',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'durable sandbox state',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );

                return MockAnthropicSse::textResponse('Sandbox state survived resume.');
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
            HaoCode::query('Write, ask, then read the same file.', $config);
            $this->fail('Expected AskUser interrupt.');
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
        $this->assertSame(
            'durable sandbox state',
            file_get_contents($sandboxRoot.'/workspace/before-interrupt.txt'),
        );

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::respond('sandbox-ask', [
                'status' => 'answered',
                'answers' => ['yes'],
            ]),
        ], $config);

        $this->assertSame('Sandbox state survived resume.', $result->text);
        $this->assertDirectoryDoesNotExist($sandboxRoot);
    }

    public function test_edit_decision_revalidates_and_executes_only_the_edited_input(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('edit-write', 'Write', [
                'file_path' => 'original.txt',
                'content' => 'original',
            ]),
            MockAnthropicSse::textResponse('Edited write completed.'),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );
        try {
            HaoCode::query('Write a file', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::edit('edit-write', ['file_path' => 'edited.txt', 'content' => 'edited']),
        ], $config);

        $this->assertFileDoesNotExist($this->projectDir.'/original.txt');
        $this->assertSame('edited', file_get_contents($this->projectDir.'/edited.txt'));
    }

    public function test_reject_decision_skips_tool_and_returns_feedback_to_model(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('reject-write', 'Write', [
                'file_path' => 'rejected.txt',
                'content' => 'no',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString('not authorized', (string) MockAnthropicSse::lastToolResultText($payload));
                return MockAnthropicSse::textResponse('Request was rejected.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );
        try {
            HaoCode::query('Write a file', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::reject('reject-write', 'not authorized'),
        ], $config);
        $this->assertFileDoesNotExist($this->projectDir.'/rejected.txt');
        $this->assertSame('Request was rejected.', $result->text);
    }

    public function test_structured_operation_resumes_as_structured_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('structured-write', 'Write', [
                'file_path' => 'structured.txt',
                'content' => 'done',
            ]),
            MockAnthropicSse::textResponse('{"status":"done"}'),
        ]);
        chdir($this->projectDir);
        $schema = [
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
            'required' => ['status'],
        ];
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        try {
            HaoCode::structured('Write then report', $schema, $config);
            $this->fail('Expected interrupt.');
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
        $this->assertDirectoryExists($sandboxRoot);

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::approve('structured-write'),
        ], $config);

        $this->assertInstanceOf(StructuredResult::class, $result);
        $this->assertSame('done', $result->status);
        $this->assertDirectoryDoesNotExist($sandboxRoot);
    }

    public function test_structured_resume_validates_schema_and_retries(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('structured-write-2', 'Write', [
                'file_path' => 'structured-retry.txt',
                'content' => 'x',
            ]),
            // After approve: invalid schema (missing required status).
            MockAnthropicSse::textResponse('{"wrong":true}'),
            // Correction turn after schema validation failure.
            MockAnthropicSse::textResponse('{"status":"fixed"}'),
        ]);
        chdir($this->projectDir);
        $schema = [
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
            'required' => ['status'],
        ];
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            structuredMaxRetries: 1,
        );
        try {
            HaoCode::structured('Write then report status', $schema, $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::approve('structured-write-2'),
        ], $config);

        $this->assertInstanceOf(StructuredResult::class, $result);
        $this->assertSame('fixed', $result->status);
    }

    public function test_stream_structured_resume_validates_schema_and_retries(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('stream-structured-write', 'Write', [
                'file_path' => 'stream-structured.txt',
                'content' => 'x',
            ]),
            MockAnthropicSse::textResponse('{"wrong":true}'),
            MockAnthropicSse::textResponse('{"status":"stream-fixed"}'),
        ]);
        chdir($this->projectDir);
        $schema = [
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
            'required' => ['status'],
        ];
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            structuredMaxRetries: 1,
        );
        try {
            HaoCode::structured('Write then stream a structured status', $schema, $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $messages = iterator_to_array(HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('stream-structured-write')],
            $config,
        ));
        $results = array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->isResult(),
        ));

        $this->assertCount(1, $results);
        $this->assertSame('{"status":"stream-fixed"}', $results[0]->text);
    }

    public function test_foreground_sub_agent_interrupt_cascades_back_to_parent_query(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('parent-agent', 'Agent', [
                'description' => 'Write child file',
                'prompt' => 'Write child.txt with the word child.',
                'subagent_type' => 'general-purpose',
            ]),
            MockAnthropicSse::toolUseResponse('child-write', 'Write', [
                'file_path' => 'child.txt',
                'content' => 'child',
            ]),
            MockAnthropicSse::textResponse('Child completed the requested work.'),
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'Child completed the requested work.',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );
                return MockAnthropicSse::textResponse('Parent received the child result.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Agent', 'Write'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );
        try {
            HaoCode::query('Delegate the write to a child agent', $config);
            $this->fail('Expected child interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = HaoCode::resumeInterrupt($interrupt->sessionId, $interrupt->id, [
            HumanDecision::approve('child-write'),
        ], $config);

        $this->assertFileExists($this->projectDir.'/child.txt');
        $this->assertSame('Parent received the child result.', $result->text);
    }

    public function test_max_budget_tracks_root_and_foreground_agent_cost_together(): void
    {
        $model = 'claude-sonnet-4-6';
        $childText = 'Child inspected the task.';
        $parentText = 'Parent received the result.';
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('budget-agent', 'Agent', [
                'description' => 'Inspect task',
                'prompt' => 'Inspect the task without using tools.',
                'subagent_type' => 'general-purpose',
            ], model: $model),
            MockAnthropicSse::textResponse($childText, model: $model),
            MockAnthropicSse::textResponse($parentText, model: $model),
        ], model: $model);
        chdir($this->projectDir);

        $result = HaoCode::query('Delegate this inspection', new HaoCodeConfig(
            allowedTools: ['Agent'],
            permissionMode: 'bypass_permissions',
            maxBudgetUsd: 1.0,
        ));

        $expectedCost = (
            (64 + 32 + 32) * 3
            + (1 + strlen($childText) + strlen($parentText)) * 15
        ) / 1_000_000;
        $this->assertSame($parentText, $result->text);
        $this->assertEqualsWithDelta($expectedCost, $result->cost, 0.000001);
    }

    public function test_agent_as_tool_cost_is_included_without_a_budget(): void
    {
        $model = 'claude-sonnet-4-6';
        $childText = 'Child completed the inspection.';
        $parentText = 'Parent received the child result.';
        $child = new Agent(
            name: 'inspector',
            allowedTools: [],
        );
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse(
                'agent-as-tool-cost',
                'Inspector',
                ['task' => 'Inspect without tools.'],
                model: $model,
            ),
            MockAnthropicSse::textResponse($childText, model: $model),
            MockAnthropicSse::textResponse($parentText, model: $model),
        ], model: $model);
        chdir($this->projectDir);

        $result = HaoCode::query('Delegate this inspection', new HaoCodeConfig(
            allowedTools: ['Inspector'],
            permissionMode: 'bypass_permissions',
            tools: [$child->asTool('Inspector', 'Inspect a task.')],
        ));

        $expectedCost = (
            (64 + 32 + 32) * 3
            + (1 + strlen($childText) + strlen($parentText)) * 15
        ) / 1_000_000;
        $this->assertSame(128, $result->usage['input_tokens']);
        $this->assertSame(1 + strlen($childText) + strlen($parentText), $result->usage['output_tokens']);
        $this->assertEqualsWithDelta($expectedCost, $result->cost, 0.000001);
    }
}
