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

trait SdkE2ETestTestStructuredRootArrayPromptMatchesTheSchemaConcern
{

    public function test_structured_root_array_prompt_matches_the_schema(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $prompt = MockAnthropicSse::lastUserText($payload) ?? '';
                $this->assertStringContainsString('ONLY a valid JSON array', $prompt);
                $this->assertStringNotContainsString('ONLY a valid JSON object', $prompt);

                return MockAnthropicSse::textResponse('["alpha","beta"]');
            },
        ]);

        $result = HaoCode::structured('Return two labels.', [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]);

        $this->assertSame(['alpha', 'beta'], $result->toArray());
    }

    public function test_stream_and_conversation_forward_thinking_and_turn_events(): void
    {
        $thinking = [];
        $this->bootWithMock([
            MockAnthropicSse::thinkingResponse('reasoning', 'answer'),
            MockAnthropicSse::thinkingResponse('more reasoning', 'second answer'),
        ]);

        $streamTypes = [];
        foreach (HaoCode::stream('Think', new HaoCodeConfig(
            allowedTools: [],
            ephemeral: true,
            onThinking: function (string $delta) use (&$thinking): void {
                $thinking[] = $delta;
            },
        )) as $message) {
            $streamTypes[] = $message->type;
        }

        $conversation = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: [],
            onThinking: function (string $delta) use (&$thinking): void {
                $thinking[] = $delta;
            },
        ));
        $conversation->send('Think again');
        $conversation->close();

        $this->assertContains('turn', $streamTypes);
        $this->assertSame(['reasoning', 'more reasoning'], $thinking);
    }

    public function test_stream_can_resume_a_persisted_session(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('First answer.'),
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));

                return MockAnthropicSse::textResponse('Resumed answer.');
            },
        ]);

        $first = HaoCode::query('First question', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: [],
            ephemeral: false,
        ));
        $this->assertNotNull($first->sessionId);

        $messages = iterator_to_array(HaoCode::stream('Second question', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: [],
            sessionId: $first->sessionId,
        )));

        $results = array_values(array_filter($messages, fn (Message $message): bool => $message->isResult()));
        $this->assertCount(1, $results);
        $this->assertSame('Resumed answer.', $results[0]->text);
    }

    public function test_resumed_facade_stream_closes_sandbox_before_terminal_result_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('First answer.'),
            MockAnthropicSse::textResponse('Resumed answer.'),
        ]);
        $first = HaoCode::query('First question', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: [],
            ephemeral: false,
        ));
        $this->assertIsString($first->sessionId);
        $sandboxRoot = sys_get_temp_dir().'/haocode-sandbox-facade-stream-'.bin2hex(random_bytes(4));
        $stream = HaoCode::stream('Second question', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: [],
            sessionId: $first->sessionId,
            sandbox: new SandboxConfig(
                cleanup: 'always',
                root: $sandboxRoot,
                options: ['owns_root' => true],
            ),
        ));

        try {
            $stream->rewind();
            $this->assertDirectoryExists($sandboxRoot);
            $result = null;
            while ($stream->valid()) {
                if ($stream->current()->isResult()) {
                    $result = $stream->current();
                    break;
                }
                $stream->next();
            }

            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame('Resumed answer.', $result->text);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($stream);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }
    public function test_query_interrupt_can_be_approved_and_resumed_across_a_new_conversation(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_hitl_write', 'Write', [
                'file_path' => 'approved.txt',
                'content' => 'approved',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString('approved.txt', (string) MockAnthropicSse::lastToolResultText($payload));
                return MockAnthropicSse::textResponse('Human-approved write completed.');
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
            HaoCode::query('Write the approved file', $config);
            $this->fail('Expected a durable human interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $this->assertFileDoesNotExist($this->projectDir.'/approved.txt');
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('toolu_hitl_write')],
            $config,
        );

        $this->assertFileExists($this->projectDir.'/approved.txt');
        $this->assertSame('approved', file_get_contents($this->projectDir.'/approved.txt'));
        $this->assertStringContainsString('completed', $result->text);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already resolved');
        HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('toolu_hitl_write')],
            $config,
        );
    }

    public function test_conversation_resume_interrupt_uses_the_same_snapshot_path_and_remains_usable(): void
    {
        $model = 'claude-sonnet-4-6';
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('conversation-write', 'Write', [
                'file_path' => 'conversation-approved.txt',
                'content' => 'approved',
            ], model: $model),
            MockAnthropicSse::textResponse('Conversation resume completed.', model: $model),
            function (array $payload): MockResponse {
                $this->assertGreaterThanOrEqual(4, MockAnthropicSse::messageCount($payload));

                return MockAnthropicSse::textResponse(
                    'Follow-up completed.',
                    model: 'claude-sonnet-4-6',
                );
            },
        ], model: $model);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            maxBudgetUsd: 1.0,
        );
        $conversation = HaoCode::conversation($config);

        try {
            $conversation->send('Write the approved conversation file');
            $this->fail('Expected a durable conversation interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = $conversation->resumeInterrupt(
            $interrupt->id,
            [HumanDecision::approve('conversation-write')],
        );
        $followUp = $conversation->send('Confirm the previous work');
        $conversation->close();

        $this->assertSame('Conversation resume completed.', $result->text);
        $this->assertSame(96, $result->usage['input_tokens']);
        $this->assertSame(32, $result->usage['last_turn_input_tokens']);
        $this->assertSame('Follow-up completed.', $followUp->text);
        $this->assertSame(128, $followUp->usage['input_tokens']);
        $this->assertSame(32, $followUp->usage['last_turn_input_tokens']);
        $this->assertGreaterThan($result->cost, $followUp->cost);
        $this->assertFileExists($this->projectDir.'/conversation-approved.txt');
    }

    public function test_conversation_stream_resume_restores_cumulative_usage_for_follow_up(): void
    {
        $model = 'claude-sonnet-4-6';
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('conversation-stream-write', 'Write', [
                'file_path' => 'conversation-stream-approved.txt',
                'content' => 'approved',
            ], model: $model),
            MockAnthropicSse::textResponse('Conversation stream resume completed.', model: $model),
            MockAnthropicSse::toolUseResponse('conversation-stream-read', 'Read', [
                'file_path' => 'conversation-stream-approved.txt',
            ], model: $model),
            function (array $payload): MockResponse {
                $this->assertGreaterThanOrEqual(4, MockAnthropicSse::messageCount($payload));
                $this->assertStringContainsString(
                    'approved',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );

                return MockAnthropicSse::textResponse(
                    'Conversation stream follow-up completed.',
                    model: 'claude-sonnet-4-6',
                );
            },
        ], model: $model);
        chdir($this->projectDir);
        $conversation = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['Write', 'Read'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            maxBudgetUsd: 1.0,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        ));

        $initial = iterator_to_array($conversation->stream('Write the approved conversation stream file'));
        $interrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $resumed = $conversation->streamResumeInterrupt(
            $interrupt->id,
            [HumanDecision::approve('conversation-stream-write')],
        );
        $resumed->rewind();
        $result = null;
        while ($resumed->valid()) {
            $message = $resumed->current();
            if ($message->isResult()) {
                $result = $message;
                break;
            }
            $resumed->next();
        }
        $this->assertInstanceOf(Message::class, $result);
        // Do not advance the generator after its terminal message: users often
        // stop consumption here while retaining the generator. The Conversation
        // must already be usable for the next operation.
        $followUp = $conversation->send('Confirm the streamed previous work');
        unset($resumed);
        gc_collect_cycles();
        $conversation->close();

        $this->assertSame('Conversation stream resume completed.', $result->text);
        $this->assertSame(96, $result->usage['input_tokens']);
        $this->assertSame(32, $result->usage['last_turn_input_tokens']);
        $this->assertSame('Conversation stream follow-up completed.', $followUp->text);
        $this->assertSame(192, $followUp->usage['input_tokens']);
        $this->assertSame(32, $followUp->usage['last_turn_input_tokens']);
        $this->assertGreaterThan($result->cost, $followUp->cost);
    }

    public function test_conversation_resume_reattaches_sandbox_for_follow_up(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('conversation-sandbox-write', 'Write', [
                'file_path' => 'before-interrupt.txt',
                'content' => 'conversation sandbox state',
            ]),
            MockAnthropicSse::toolUseResponse('conversation-sandbox-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
            MockAnthropicSse::textResponse('Conversation sandbox resume completed.'),
            MockAnthropicSse::toolUseResponse('conversation-sandbox-read', 'Read', [
                'file_path' => 'before-interrupt.txt',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'conversation sandbox state',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );

                return MockAnthropicSse::textResponse('Conversation sandbox follow-up completed.');
            },
        ]);
        chdir($this->projectDir);
        $conversation = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['Write', 'Read', 'AskUserQuestion'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            enableAskUser: true,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        ));

        try {
            $conversation->send('Write a file, then ask whether to continue.');
            $this->fail('Expected a durable conversation interrupt.');
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

        try {
            $resumed = $conversation->resumeInterrupt(
                $interrupt->id,
                [HumanDecision::respond('conversation-sandbox-ask', [
                    'status' => 'answered',
                    'answers' => ['yes'],
                ])],
            );
            $followUp = $conversation->send('Read the file written before the interrupt.');
            $conversation->close();
        } catch (\Throwable $e) {
            try {
                $conversation->close();
            } finally {
                if (is_dir($sandboxRoot)) {
                    $this->removeDirectory($sandboxRoot);
                }
            }

            throw $e;
        }

        $this->assertSame('Conversation sandbox resume completed.', $resumed->text);
        $this->assertSame('Conversation sandbox follow-up completed.', $followUp->text);
        $this->assertDirectoryDoesNotExist($sandboxRoot);
    }

    public function test_resumed_conversation_reattaches_interrupt_sandbox_for_follow_up(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('resumed-conversation-write', 'Write', [
                'file_path' => 'before-interrupt.txt',
                'content' => 'resumed conversation sandbox state',
            ]),
            MockAnthropicSse::toolUseResponse('resumed-conversation-ask', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Continue?',
                    'type' => 'multiple_choice',
                    'options' => ['yes', 'no'],
                    'required' => true,
                ]],
            ]),
            MockAnthropicSse::textResponse('Resumed conversation completed.'),
            MockAnthropicSse::toolUseResponse('resumed-conversation-read', 'Read', [
                'file_path' => 'before-interrupt.txt',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'resumed conversation sandbox state',
                    (string) MockAnthropicSse::lastToolResultText($payload),
                );

                return MockAnthropicSse::textResponse('Resumed conversation follow-up completed.');
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
            $resumed = $conversation->resumeInterrupt(
                $interrupt->id,
                [HumanDecision::respond('resumed-conversation-ask', [
                    'status' => 'answered',
                    'answers' => ['yes'],
                ])],
            );
            $followUp = $conversation->send('Read the file written before the interrupt.');
            $conversation->close();

            $this->assertSame('Resumed conversation completed.', $resumed->text);
            $this->assertSame('Resumed conversation follow-up completed.', $followUp->text);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            $conversation->close();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }
}
