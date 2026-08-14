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

trait SdkE2ETestSetUpConcern
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-sdk-e2e-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->projectDir = $this->tempRoot.'/project';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/sdk-storage';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->projectDir, 0755, true);
        mkdir($this->sessionDir, 0755, true);
        mkdir($this->storageDir, 0755, true);

        $this->setHomeDirectory($this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        unset($_SERVER['HAOCODE_STORAGE_PATH']);
        putenv('HAOCODE_STORAGE_PATH');
        $this->setHomeDirectory($this->originalHome);
        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_query_returns_final_response_text(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame([], $payload['tools'] ?? []);
                $systemPrompt = (string) ($payload['system'][0]['text'] ?? '');
                $this->assertLessThan(1000, strlen($systemPrompt));
                $this->assertStringNotContainsString('# Using tools', $systemPrompt);
                $this->assertStringNotContainsString('# Skills', $systemPrompt);
                $this->assertStringNotContainsString('# Git Status', $systemPrompt);
                $this->assertStringNotContainsString('# Project Instructions', $systemPrompt);

                return MockAnthropicSse::textResponse('Hello from the SDK! The answer is 42.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('What is the answer to life?');

        $this->assertStringContainsString('42', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertIsFloat($result->cost);
        // Stringable: can still be used as string
        $this->assertStringContainsString('42', (string) $result);
        $this->assertNull($result->sessionId);
        $this->assertSame([], glob($this->sessionDir.'/*.jsonl') ?: []);
    }

    public function test_query_rejects_an_empty_api_key_before_sending_a_request(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('No request should be sent without an API key.');
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key is required');

        HaoCode::query('Hello', new HaoCodeConfig(
            apiKey: '',
            allowedTools: [],
        ));
    }

    public function test_conversation_rejects_an_empty_api_key_before_sending_a_request(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('No request should be sent without an API key.');
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key is required');

        HaoCode::conversation(new HaoCodeConfig(
            apiKey: '',
            allowedTools: [],
        ));
    }

    public function test_query_executes_tools_and_returns_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                'file_path' => 'hello.txt',
                'content' => "Hello from SDK!\n",
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('hello.txt', $toolResult);

                return MockAnthropicSse::textResponse('File created successfully.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Create hello.txt', new HaoCodeConfig(
            allowedTools: ['Write'],
            permissionMode: 'bypass_permissions',
        ));

        $this->assertStringContainsString('File created', $result->text);
        $this->assertFileExists($this->projectDir.'/hello.txt');
        $this->assertSame("Hello from SDK!\n", file_get_contents($this->projectDir.'/hello.txt'));
        $this->assertSame(2, $result->turnsUsed);
    }

    public function test_query_with_config_respects_options(): void
    {
        $textChunks = [];

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Configured response.'),
        ]);

        chdir($this->projectDir);

        $config = new HaoCodeConfig(
            maxTurns: 5,
            onText: function (string $delta) use (&$textChunks) {
                $textChunks[] = $delta;
            },
        );

        $result = HaoCode::query('Test', $config);

        $this->assertStringContainsString('Configured response', $result->text);
        $this->assertNotEmpty($textChunks);
        $this->assertSame('Configured response.', implode('', $textChunks));
    }

    public function test_stream_yields_text_and_result_messages(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame([], $payload['tools'] ?? []);

                return MockAnthropicSse::textResponse('Streamed answer.');
            },
        ]);

        chdir($this->projectDir);

        $messages = [];
        foreach (HaoCode::stream('Stream test') as $msg) {
            $messages[] = $msg;
        }

        // Should have at least a text message and a result message
        $this->assertNotEmpty($messages);

        $textMessages = array_filter($messages, fn (Message $m) => $m->type === 'text');
        $resultMessages = array_filter($messages, fn (Message $m) => $m->type === 'result');

        $this->assertNotEmpty($textMessages);
        $this->assertCount(1, $resultMessages);

        $result = array_values($resultMessages)[0];
        $this->assertStringContainsString('Streamed answer', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertArrayHasKey('input_tokens', $result->usage);
        $this->assertArrayHasKey('output_tokens', $result->usage);
        $this->assertIsFloat($result->cost);
        $this->assertNull($result->sessionId);
        $this->assertSame([], glob($this->sessionDir.'/*.jsonl') ?: []);
    }

    public function test_stream_yields_tool_messages(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                'command' => 'echo "SDK streaming"',
                'description' => 'Echo test',
            ]),
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Bash executed.');
            },
        ]);

        chdir($this->projectDir);

        $types = [];
        foreach (HaoCode::stream('Run a command', new HaoCodeConfig(
            allowedTools: ['Bash'],
        )) as $msg) {
            $types[] = $msg->type;
        }

        $this->assertContains('tool_start', $types);
        $this->assertContains('tool_result', $types);
        $this->assertContains('result', $types);
    }

    public function test_conversation_maintains_context_across_turns(): void
    {
        $requestPayloads = [];

        $this->bootWithMock([
            // Turn 1 response
            MockAnthropicSse::textResponse('I created the variable x = 10.'),
            // Turn 2 response — should see previous context
            function (array $payload) use (&$requestPayloads): MockResponse {
                $requestPayloads[] = $payload;

                return MockAnthropicSse::textResponse('The value of x is 10.');
            },
        ], $requestPayloads);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();

        $r1 = $conv->send('Set x = 10');
        $this->assertStringContainsString('x = 10', $r1->text);
        $this->assertSame(32, $r1->usage['last_turn_input_tokens']);
        $this->assertSame(1, $conv->getTurnCount());

        $r2 = $conv->send('What is x?');
        $this->assertStringContainsString('10', $r2->text);
        $this->assertSame(32, $r2->usage['last_turn_input_tokens']);
        $this->assertSame(2, $conv->getTurnCount());

        // Verify the second request included conversation history
        $this->assertNotEmpty($requestPayloads);
        $lastPayload = end($requestPayloads);
        // Should have 3 messages: user1 + assistant1 + user2
        $this->assertSame(3, MockAnthropicSse::messageCount($lastPayload));

        $conv->close();
    }

    public function test_conversation_stream_yields_messages(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('First turn response.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();

        $messages = [];
        foreach ($conv->stream('Hello') as $msg) {
            $messages[] = $msg;
        }

        $resultMsgs = array_filter($messages, fn (Message $m) => $m->isResult());
        $this->assertCount(1, $resultMsgs);

        $result = array_values($resultMsgs)[0];
        $this->assertStringContainsString('First turn', $result->text);
        $this->assertSame(32, $result->usage['last_turn_input_tokens']);

        $conv->close();
    }

    public function test_conversation_throws_after_close(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('OK.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();
        $conv->send('Hello');
        $conv->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed');
        $conv->send('This should fail');
    }

    public function test_config_make_creates_minimal_config(): void
    {
        $config = HaoCodeConfig::make('test-key', 'claude-haiku');
        $this->assertSame('test-key', $config->apiKey);
        $this->assertSame('claude-haiku', $config->model);
        $this->assertSame([], $config->allowedTools);
        $this->assertSame('default', $config->permissionMode);
        $this->assertTrue($config->ephemeral);
        $this->assertSame(50, $config->maxTurns);
    }

    public function test_config_with_only_api_key_keeps_safe_query_defaults(): void
    {
        $config = new HaoCodeConfig(apiKey: 'test-key');

        $this->assertSame([], $config->allowedTools);
        $this->assertSame('default', $config->permissionMode);
        $this->assertTrue($config->ephemeral);
        $filter = $config->toolFilter();
        $this->assertNotNull($filter);
        $this->assertFalse($filter('Bash'));
    }

    public function test_config_tool_filter_respects_allow_and_deny(): void
    {
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'Write', 'Bash'],
            disallowedTools: ['Bash'],
        );

        $filter = $config->toolFilter();
        $this->assertNotNull($filter);
        $this->assertTrue($filter('Read'));
        $this->assertTrue($filter('Write'));
        $this->assertFalse($filter('Bash'));   // Denied
        $this->assertFalse($filter('Agent'));  // Not in allowed list
    }

    public function test_message_factory_methods_produce_correct_types(): void
    {
        $text = Message::text('hello');
        $this->assertSame('text', $text->type);
        $this->assertSame('hello', $text->text);

        $toolStart = Message::toolStart('Bash', ['command' => 'ls']);
        $this->assertSame('tool_start', $toolStart->type);
        $this->assertSame('Bash', $toolStart->toolName);

        $toolResult = Message::toolResult('Bash', 'file.txt', false);
        $this->assertSame('tool_result', $toolResult->type);
        $this->assertFalse($toolResult->toolIsError);

        $result = Message::result('done', ['input_tokens' => 100], 0.01, 'sess_123');
        $this->assertTrue($result->isResult());
        $this->assertSame(0.01, $result->cost);
        $this->assertSame('sess_123', $result->sessionId);

        $error = Message::error('boom');
        $this->assertTrue($error->isError());
        $this->assertSame('boom', $error->error);
    }

    public function test_query_result_carries_usage_and_cost(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Result with metadata.'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Test metadata');

        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame('Result with metadata.', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertGreaterThanOrEqual(0, $result->inputTokens());
        $this->assertGreaterThanOrEqual(0, $result->outputTokens());
        $this->assertSame(32, $result->usage['last_turn_input_tokens']);
        $this->assertIsFloat($result->cost);
        $this->assertSame(1, $result->turnsUsed);
        // Stringable
        $this->assertSame('Result with metadata.', (string) $result);
    }

    public function test_abort_controller_signals_abort(): void
    {
        $abort = new AbortController;
        $this->assertFalse($abort->isAborted());

        $callbackFired = false;
        $abort->onAbort(function () use (&$callbackFired) {
            $callbackFired = true;
        });

        $abort->abort();
        $this->assertTrue($abort->isAborted());
        $this->assertTrue($callbackFired);
        $listeners = new \ReflectionProperty(AbortController::class, 'listeners');
        $this->assertCount(0, $listeners->getValue($abort));

        // Double abort is a no-op
        $abort->abort();

        // Late listener fires immediately
        $lateCallbackFired = false;
        $abort->onAbort(function () use (&$lateCallbackFired) {
            $lateCallbackFired = true;
        });
        $this->assertTrue($lateCallbackFired);
        $this->assertCount(0, $listeners->getValue($abort));
    }

    public function test_pre_aborted_controller_does_not_send_a_provider_request(): void
    {
        $requestCount = 0;
        $this->bootWithMock([
            function () use (&$requestCount): MockResponse {
                $requestCount++;

                return MockAnthropicSse::textResponse('must not be requested');
            },
        ]);
        $abort = new AbortController;
        $abort->abort();

        $result = HaoCode::query('Do not start', new HaoCodeConfig(
            allowedTools: [],
            abortController: $abort,
        ));

        $this->assertSame('(aborted)', $result->text);
        $this->assertSame(0, $requestCount);
    }

    public function test_abort_notifies_every_listener_before_rethrowing_the_first_failure(): void
    {
        $abort = new AbortController;
        $secondListenerCalled = false;
        $abort->onAbort(static function (): void {
            throw new \RuntimeException('listener failed');
        });
        $abort->onAbort(static function () use (&$secondListenerCalled): void {
            $secondListenerCalled = true;
        });

        try {
            $abort->abort();
            $this->fail('Expected the first listener failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('listener failed', $exception->getMessage());
        }

        $this->assertTrue($secondListenerCalled);
    }
}
