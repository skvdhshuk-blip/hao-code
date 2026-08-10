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

/**
 * E2E tests for the HaoCode PHP SDK.
 *
 * Tests HaoCode::query(), HaoCode::stream(), and HaoCode::conversation()
 * using mock API responses. Verifies the SDK facade correctly wires
 * into AgentLoop and returns typed Message objects.
 */
class SdkE2ETest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

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

    // ──────────────────────────────────────────────────────────────
    //  Test 1: HaoCode::query() — simple one-shot
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 2: HaoCode::query() with tool use
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 3: HaoCode::query() with config options
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 4: HaoCode::stream() yields typed Message objects
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 5: HaoCode::stream() with tool use yields tool messages
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 6: HaoCode::conversation() — multi-turn
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 7: Conversation::stream() yields messages per turn
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 8: Conversation throws after close
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 9: HaoCodeConfig::make() factory
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 10: HaoCodeConfig tool filter
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 11: Message factory methods
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 12: QueryResult carries usage metadata
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Test 13: AbortController
    // ──────────────────────────────────────────────────────────────

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

    public function test_abort_between_conversation_creation_and_send_does_not_send_a_request(): void
    {
        $requestCount = 0;
        $this->bootWithMock([
            function () use (&$requestCount): MockResponse {
                $requestCount++;

                return MockAnthropicSse::textResponse('must not be requested');
            },
        ]);
        $abort = new AbortController;
        $conversation = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: [],
            abortController: $abort,
        ));
        $abort->abort();

        $result = $conversation->send('Do not start');
        $conversation->close();

        $this->assertSame('(aborted)', $result->text);
        $this->assertSame(0, $requestCount);
    }

    public function test_completed_runs_unsubscribe_abort_listeners(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('first'),
            MockAnthropicSse::textResponse('second'),
            MockAnthropicSse::textResponse('third'),
        ]);
        $abort = new AbortController;
        $listeners = new \ReflectionProperty(AbortController::class, 'listeners');

        foreach (['one', 'two', 'three'] as $prompt) {
            HaoCode::query($prompt, new HaoCodeConfig(
                allowedTools: [],
                abortController: $abort,
            ));
            $this->assertCount(0, $listeners->getValue($abort));
        }
    }

    public function test_pre_aborted_interrupt_resume_does_not_claim_or_execute_the_tool(): void
    {
        $execution = new class
        {
            public int $count = 0;
        };
        $tool = new class($execution) extends SdkTool
        {
            public function __construct(private readonly object $execution) {}

            public function name(): string
            {
                return 'AbortSensitiveWrite';
            }

            public function description(): string
            {
                return 'Records whether an approved side effect ran.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                $this->execution->count++;

                return 'executed';
            }
        };
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('abort-sensitive-write', 'AbortSensitiveWrite', []),
        ]);
        $abort = new AbortController;
        $config = new HaoCodeConfig(
            allowedTools: ['AbortSensitiveWrite'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            tools: [$tool],
            abortController: $abort,
            interruptOn: ['AbortSensitiveWrite' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Pause before the side effect', $config);
            $this->fail('Expected an interrupt before tool execution.');
        } catch (HumanInterruptException $exception) {
            $interrupt = $exception->interrupt;
        }

        $abort->abort();
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('abort-sensitive-write')],
            $config,
        );
        $pending = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);

        $this->assertSame('(aborted)', $result->text);
        $this->assertSame(0, $execution->count);
        $this->assertSame('interrupt_pending', $pending['type']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 14: SdkTool — custom tool definition
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_tool_custom_tool_registers_and_works(): void
    {
        $customTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'GetWeather';
            }

            public function description(): string
            {
                return 'Get current weather for a city.';
            }

            public function parameters(): array
            {
                return [
                    'city' => ['type' => 'string', 'description' => 'City name', 'required' => true],
                ];
            }

            public function handle(array $input): string
            {
                return "Weather in {$input['city']}: Sunny, 25°C";
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_w1', 'GetWeather', [
                'city' => 'Tokyo',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Tokyo', $toolResult);
                $this->assertStringContainsString('Sunny', $toolResult);
                $this->assertStringContainsString('25', $toolResult);

                return MockAnthropicSse::textResponse('The weather in Tokyo is sunny and 25°C.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query("What's the weather in Tokyo?", new HaoCodeConfig(
            allowedTools: ['GetWeather'],
            tools: [$customTool],
        ));

        $this->assertStringContainsString('Tokyo', $result->text);
        $this->assertStringContainsString('25', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 15: SdkTool input schema generation
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_tool_generates_correct_input_schema(): void
    {
        $tool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'TestTool';
            }

            public function description(): string
            {
                return 'Test tool.';
            }

            public function parameters(): array
            {
                return [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true],
                    'age' => ['type' => 'integer', 'description' => 'Age'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'user']],
                ];
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };

        $schema = $tool->inputSchema();
        $this->assertNotNull($schema);

        // Conservative default: non-read-only until the tool opts in.
        $this->assertFalse($tool->isReadOnly([]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 16: StructuredResult access patterns
    // ──────────────────────────────────────────────────────────────

    public function test_structured_result_provides_property_and_array_access(): void
    {
        $result = new StructuredResult(
            data: ['category' => 'shipping', 'priority' => 'high', 'score' => 95],
            rawText: '{"category":"shipping","priority":"high","score":95}',
        );

        // Property access
        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result->priority);
        $this->assertSame(95, $result->score);
        $this->assertNull($result->nonexistent);

        // Array access
        $this->assertSame('shipping', $result['category']);
        $this->assertTrue(isset($result['priority']));
        $this->assertFalse(isset($result['missing']));

        // toArray / toJson
        $this->assertCount(3, $result->toArray());
        $this->assertStringContainsString('shipping', $result->toJson());

        // Stringable
        $this->assertStringContainsString('shipping', (string) $result);

        // Immutable
        $this->expectException(\RuntimeException::class);
        $result['category'] = 'billing';
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 17: HaoCodeConfig with new options
    // ──────────────────────────────────────────────────────────────

    public function test_config_accepts_new_sdk_options(): void
    {
        $abort = new AbortController;
        $tool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'Noop';
            }

            public function description(): string
            {
                return 'No-op.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };

        $config = new HaoCodeConfig(
            allowedTools: ['Noop'],
            tools: [$tool],
            abortController: $abort,
            sessionId: 'test_session_123',
            continueSession: true,
            responseSchema: ['type' => 'object'],
        );

        $this->assertCount(1, $config->tools);
        $this->assertSame($abort, $config->abortController);
        $this->assertSame('test_session_123', $config->sessionId);
        $this->assertTrue($config->continueSession);
        $this->assertSame(['type' => 'object'], $config->responseSchema);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 18: Conversation.send() returns QueryResult
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_send_returns_query_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Conversation result.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();
        $result = $conv->send('Hello');

        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame('Conversation result.', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertIsFloat($result->cost);
        $this->assertSame(1, $result->turnsUsed);

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 19: SDK config overrides reach StreamingClient
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_config_overrides_create_custom_streaming_client(): void
    {
        // Use reflection to test the private buildStreamingClient method
        $method = new \ReflectionMethod(HaoCode::class, 'buildStreamingClient');

        // No overrides → returns null (use container default)
        $defaultConfig = new HaoCodeConfig;
        $this->assertNull($method->invoke(null, $defaultConfig));

        // With apiKey override → returns custom StreamingClient
        $config = new HaoCodeConfig(apiKey: 'sk-custom-key-123');
        $client = $method->invoke(null, $config);
        $this->assertInstanceOf(StreamingClient::class, $client);

        // With model override → returns custom StreamingClient
        $config2 = new HaoCodeConfig(model: 'claude-opus-4-8');
        $client2 = $method->invoke(null, $config2);
        $this->assertInstanceOf(StreamingClient::class, $client2);

        // With baseUrl override → returns custom StreamingClient
        $config3 = new HaoCodeConfig(baseUrl: 'https://my-proxy.example.com');
        $client3 = $method->invoke(null, $config3);
        $this->assertInstanceOf(StreamingClient::class, $client3);

        // With maxTokens override → returns custom StreamingClient
        $config4 = new HaoCodeConfig(maxTokens: 8192);
        $client4 = $method->invoke(null, $config4);
        $this->assertInstanceOf(StreamingClient::class, $client4);

        $openAiClient = $method->invoke(null, new HaoCodeConfig(
            apiKey: 'test-openai-key',
            providerType: 'openai',
            model: 'gpt-5.2',
        ));
        $openAiReflection = new \ReflectionObject($openAiClient);
        $openAiProvider = $openAiReflection->getProperty('openai')->getValue($openAiClient);
        $this->assertSame(
            'https://api.openai.com',
            (new \ReflectionObject($openAiProvider))->getProperty('baseUrl')->getValue($openAiProvider),
        );

        mkdir($this->projectDir.'/.haocode', 0755, true);
        file_put_contents($this->projectDir.'/.haocode/settings.json', json_encode([
            'active_provider' => 'project-openai',
            'provider' => [
                'project-openai' => [
                    'type' => 'openai',
                    'api_key' => 'project-key',
                    'api_base_url' => 'https://project-openai.example.com',
                    'model' => 'project-default-model',
                    'max_tokens' => 12345,
                ],
            ],
        ]));

        $projectClient = $method->invoke(null, new HaoCodeConfig(
            cwd: $this->projectDir,
            model: 'explicit-model',
        ));
        $clientReflection = new \ReflectionObject($projectClient);
        $this->assertSame('openai', $clientReflection->getProperty('defaultProviderType')->getValue($projectClient));

        $openAiProvider = $clientReflection->getProperty('openai')->getValue($projectClient);
        $providerReflection = new \ReflectionObject($openAiProvider);
        $this->assertSame('project-key', $providerReflection->getProperty('apiKey')->getValue($openAiProvider));
        $this->assertSame('https://project-openai.example.com', $providerReflection->getProperty('baseUrl')->getValue($openAiProvider));
        $this->assertSame('explicit-model', $providerReflection->getProperty('model')->getValue($openAiProvider));
        $this->assertSame(12345, $providerReflection->getProperty('maxTokens')->getValue($openAiProvider));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 20: SDK query works with default config (no overrides)
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_query_with_default_config_uses_container_client(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Default client response.'),
        ]);

        chdir($this->projectDir);

        // Default config (no apiKey/baseUrl/model overrides) → uses container singleton
        $result = HaoCode::query('Hello', new HaoCodeConfig);

        $this->assertStringContainsString('Default client response', $result->text);
    }

    public function test_default_container_client_uses_run_scoped_project_settings(): void
    {
        mkdir($this->projectDir.'/.haocode', 0755, true);
        file_put_contents($this->projectDir.'/.haocode/settings.json', json_encode([
            'model' => 'project-scoped-model',
        ]));

        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame('project-scoped-model', $payload['model']);

                return MockAnthropicSse::textResponse('Project settings response.');
            },
        ]);

        $result = HaoCode::query('Hello', new HaoCodeConfig(cwd: $this->projectDir));

        $this->assertStringContainsString('Project settings response', $result->text);
    }

    public function test_openai_provider_without_model_fails_before_request_creation(): void
    {
        $method = new \ReflectionMethod(HaoCode::class, 'buildStreamingClient');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A model is required for provider type "openai"');

        $method->invoke(null, new HaoCodeConfig(
            apiKey: 'test-openai-key',
            providerType: 'openai',
        ));
    }

    public function test_openai_provider_never_sends_anthropic_key_from_active_provider(): void
    {
        $this->bootWithMock([]);
        mkdir($this->projectDir.'/.haocode', 0755, true);
        file_put_contents($this->projectDir.'/.haocode/settings.json', json_encode([
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'must-not-leave-anthropic-boundary',
                    'model' => 'claude-opus-4-8',
                ],
            ],
        ]));
        $originalOpenAiKey = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $method = new \ReflectionMethod(HaoCode::class, 'createRun');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('API key is required for provider type "openai"');

            $method->invoke(null, new HaoCodeConfig(
                cwd: $this->projectDir,
                providerType: 'openai',
                model: 'gpt-5.2',
            ));
        } finally {
            $originalOpenAiKey === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$originalOpenAiKey);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 21: maxBudgetUsd wires to CostTracker
    // ──────────────────────────────────────────────────────────────

    public function test_max_budget_usd_wires_to_cost_tracker(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Budget test.'),
        ]);

        chdir($this->projectDir);

        $config = new HaoCodeConfig(
            model: 'claude-sonnet-4-6',
            maxBudgetUsd: 2.50,
        );

        // Use reflection to verify CostTracker thresholds were set
        $method = new \ReflectionMethod(HaoCode::class, 'createRun');
        $run = $method->invoke(null, $config);
        $loop = $run->loop;

        $tracker = $loop->getCostTracker();
        $this->assertSame(2.50, $tracker->getStopThreshold());
        $run->close();
    }

    public function test_zero_budget_stops_before_sending_a_request(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('A zero budget must stop before an API request.');
            },
        ], model: 'claude-sonnet-4-6');
        chdir($this->projectDir);

        $result = HaoCode::query('Do not sample', new HaoCodeConfig(
            maxBudgetUsd: 0.0,
        ));

        $this->assertStringContainsString('Cost limit reached', $result->text);
        $this->assertSame(0.0, $result->cost);
        $this->assertSame(0, $result->turnsUsed);
    }

    public function test_max_budget_rejects_a_model_without_trusted_pricing(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('No request should be sent without trusted pricing.');
            },
        ]);
        chdir($this->projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No trusted pricing is configured');

        HaoCode::query('Budgeted request', new HaoCodeConfig(maxBudgetUsd: 1.0));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 22: systemPrompt overrides default
    // ──────────────────────────────────────────────────────────────

    public function test_system_prompt_override_reaches_model_without_mutating_global_settings(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'You are a pirate. Always say "Arrr!".',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Custom prompt response.');
            },
        ]);

        chdir($this->projectDir);

        HaoCode::query('Test', new HaoCodeConfig(
            systemPrompt: 'You are a pirate. Always say "Arrr!".',
        ));

        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getSystemPrompt());
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 23: appendSystemPrompt reaches SettingsManager
    // ──────────────────────────────────────────────────────────────

    public function test_append_system_prompt_reaches_model_without_mutating_global_settings(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'Always respond in JSON format.',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Appended response.');
            },
        ]);

        chdir($this->projectDir);

        HaoCode::query('Test', new HaoCodeConfig(
            appendSystemPrompt: 'Always respond in JSON format.',
        ));

        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getAppendSystemPrompt());
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 24: Custom SdkTool with error handling
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_tool_error_is_returned_to_model(): void
    {
        $failingTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'FailTool';
            }

            public function description(): string
            {
                return 'A tool that always fails.';
            }

            public function parameters(): array
            {
                return ['input' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                throw new \RuntimeException('Database connection refused');
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_fail', 'FailTool', [
                'input' => 'test',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Database connection refused', $toolResult);

                return MockAnthropicSse::textResponse('The tool failed with a database error.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Try the failing tool', new HaoCodeConfig(
            allowedTools: ['FailTool'],
            tools: [$failingTool],
        ));

        $this->assertStringContainsString('database error', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 25: Multi-tool SDK query — custom + built-in tools together
    // ──────────────────────────────────────────────────────────────

    public function test_custom_and_builtin_tools_work_together(): void
    {
        $dbTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'QueryDB';
            }

            public function description(): string
            {
                return 'Query the database.';
            }

            public function parameters(): array
            {
                return ['sql' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                return json_encode([
                    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
                    ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
                ]);
            }
        };

        $this->bootWithMock([
            // Turn 1: AI calls custom QueryDB tool
            MockAnthropicSse::toolUseResponse('toolu_db', 'QueryDB', [
                'sql' => 'SELECT * FROM users',
            ]),
            // Turn 2: AI writes query results to file using built-in Write
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Alice', $toolResult);
                $this->assertStringContainsString('Bob', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_write', 'Write', [
                    'file_path' => 'users.json',
                    'content' => $toolResult,
                ]);
            },
            // Turn 3: Summarize
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Exported 2 users to users.json.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Export all users to a file', new HaoCodeConfig(
            allowedTools: ['Write', 'QueryDB'],
            permissionMode: 'bypass_permissions',
            tools: [$dbTool],
        ));

        $this->assertStringContainsString('2 users', $result->text);
        $this->assertFileExists($this->projectDir.'/users.json');

        $users = json_decode(file_get_contents($this->projectDir.'/users.json'), true);
        $this->assertCount(2, $users);
        $this->assertSame('Alice', $users[0]['name']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 26: Stream with multi-turn tool use collects all events
    // ──────────────────────────────────────────────────────────────

    public function test_stream_multi_turn_collects_all_event_types(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_g1', 'Glob', [
                'pattern' => '*.txt',
            ]),
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                    'command' => 'echo "found files"',
                    'description' => 'List results',
                ]);
            },
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Found files and listed them.');
            },
        ]);

        chdir($this->projectDir);

        $typeCounter = [];
        foreach (HaoCode::stream('Find all text files', new HaoCodeConfig(
            allowedTools: ['Glob', 'Bash'],
            permissionMode: 'bypass_permissions',
        )) as $msg) {
            $typeCounter[$msg->type] = ($typeCounter[$msg->type] ?? 0) + 1;
        }

        // Should have tool_start (2x), tool_result (2x), text (1x), result (1x)
        $this->assertArrayHasKey('tool_start', $typeCounter);
        $this->assertArrayHasKey('tool_result', $typeCounter);
        $this->assertArrayHasKey('text', $typeCounter);
        $this->assertArrayHasKey('result', $typeCounter);
        $this->assertSame(2, $typeCounter['tool_start']);
        $this->assertSame(2, $typeCounter['tool_result']);
        $this->assertSame(1, $typeCounter['result']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 27: Conversation with custom tool across turns
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_custom_tool_persists_across_turns(): void
    {
        $statefulTool = new class extends SdkTool
        {
            private array $items = [];

            public function name(): string
            {
                return 'CartAdd';
            }

            public function description(): string
            {
                return 'Add item to cart.';
            }

            public function parameters(): array
            {
                return ['item' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                $this->items[] = $input['item'];

                return 'Cart: '.implode(', ', $this->items).' ('.count($this->items).' items)';
            }

            // Stateful tools must NOT be read-only, otherwise they get
            // fork-executed and state changes are lost in the child process.
            public function isReadOnly(array $input): bool
            {
                return false;
            }
        };

        $this->bootWithMock([
            // Turn 1: Add apple
            MockAnthropicSse::toolUseResponse('toolu_c1', 'CartAdd', ['item' => 'apple']),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('apple', $toolResult);
                $this->assertStringContainsString('1 items', $toolResult);

                return MockAnthropicSse::textResponse('Added apple to cart.');
            },
            // Turn 2: Add banana — tool should remember apple from turn 1
            MockAnthropicSse::toolUseResponse('toolu_c2', 'CartAdd', ['item' => 'banana']),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('apple', $toolResult);
                $this->assertStringContainsString('banana', $toolResult);
                $this->assertStringContainsString('2 items', $toolResult);

                return MockAnthropicSse::textResponse('Cart now has apple and banana.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['CartAdd'],
            permissionMode: 'bypass_permissions',
            tools: [$statefulTool],
        ));

        $r1 = $conv->send('Add apple to my cart');
        $this->assertStringContainsString('apple', $r1->text);

        $r2 = $conv->send('Now add banana');
        $this->assertStringContainsString('banana', $r2->text);
        $this->assertSame(2, $conv->getTurnCount());

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 28: QueryResult is Stringable in string contexts
    // ──────────────────────────────────────────────────────────────

    public function test_query_result_works_in_string_operations(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('The answer is 42.'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('What is the answer?');

        // String concatenation
        $this->assertSame('Result: The answer is 42.', 'Result: '.$result);

        // str_contains
        $this->assertTrue(str_contains((string) $result, '42'));

        // strlen
        $this->assertSame(strlen('The answer is 42.'), strlen((string) $result));

        // json_encode wraps in quotes
        $this->assertStringContainsString('42', json_encode($result->text));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 29: Multiple SdkTools registered at once
    // ──────────────────────────────────────────────────────────────

    public function test_multiple_sdk_tools_registered_simultaneously(): void
    {
        $toolA = new class extends SdkTool
        {
            public function name(): string
            {
                return 'ToolAlpha';
            }

            public function description(): string
            {
                return 'First tool.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'alpha-result';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $toolB = new class extends SdkTool
        {
            public function name(): string
            {
                return 'ToolBeta';
            }

            public function description(): string
            {
                return 'Second tool.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'beta-result';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_a', 'ToolAlpha', []),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('alpha-result', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_b', 'ToolBeta', []);
            },
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('beta-result', $toolResult);

                return MockAnthropicSse::textResponse('Both tools executed.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Use both tools', new HaoCodeConfig(
            allowedTools: ['ToolAlpha', 'ToolBeta'],
            tools: [$toolA, $toolB],
        ));

        $this->assertStringContainsString('Both tools', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 30: Conversation getCost() and getSessionId()
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_exposes_cost_and_session_id(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Turn one.'),
            MockAnthropicSse::textResponse('Turn two.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(ephemeral: false));

        $conv->send('First');
        $this->assertIsFloat($conv->getCost());
        $this->assertNotNull($conv->getSessionId());

        $conv->send('Second');
        $this->assertSame(2, $conv->getTurnCount());

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 31: SdkSkill — agent invokes a custom skill via SkillTool
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_skill_is_invocable_by_agent(): void
    {
        $this->bootWithMock([
            // Agent decides to invoke the custom skill
            MockAnthropicSse::toolUseResponse('toolu_skill', 'Skill', [
                'skill' => 'security-review',
                'args' => 'auth.php',
            ]),
            // Agent receives the expanded skill prompt and acts on it
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                // Skill prompt should be returned as tool result
                $this->assertStringContainsString('OWASP Top 10', $toolResult);
                $this->assertStringContainsString('auth.php', $toolResult);

                return MockAnthropicSse::textResponse('Security review complete. No critical issues found.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'security-review',
                    description: 'Review code for security vulnerabilities',
                    prompt: 'Review the following file for OWASP Top 10 vulnerabilities. Focus on injection, XSS, and auth bypass. File: $ARGUMENTS',
                ),
            ],
        ));

        $this->assertStringContainsString('Security review complete', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 32: SdkSkill with allowedTools restriction
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_skill_definition_converts_correctly(): void
    {
        $skill = new SdkSkill(
            name: 'lint-check',
            description: 'Run linting on the project',
            prompt: 'Run lint checks on $ARGUMENTS and report issues.',
            allowedTools: ['Bash', 'Read'],
            model: 'haiku',
        );

        $def = $skill->toDefinition();

        $this->assertSame('lint-check', $def->name);
        $this->assertSame('Run linting on the project', $def->description);
        $this->assertStringContainsString('Run lint checks', $def->prompt);
        $this->assertSame(['Bash', 'Read'], $def->allowedTools);
        $this->assertSame('haiku', $def->model);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 33: Multiple skills registered at once
    // ──────────────────────────────────────────────────────────────

    public function test_multiple_sdk_skills_registered(): void
    {
        $this->bootWithMock([
            // Agent invokes the second skill
            MockAnthropicSse::toolUseResponse('toolu_s2', 'Skill', [
                'skill' => 'deploy',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Deploy to production', $toolResult);

                return MockAnthropicSse::textResponse('Deployment initiated.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Deploy the app', new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'test-suite',
                    description: 'Run the test suite',
                    prompt: 'Run all tests and report failures.',
                ),
                new SdkSkill(
                    name: 'deploy',
                    description: 'Deploy to production',
                    prompt: 'Deploy to production. Run tests first, then build and push.',
                ),
            ],
        ));

        $this->assertStringContainsString('Deployment', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 34: Skills and tools together in one query
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_skills_and_tools_work_together(): void
    {
        $dbTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'CheckDB';
            }

            public function description(): string
            {
                return 'Check database status.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'DB: 3 tables, 150 rows, healthy';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            // Agent invokes custom skill first
            MockAnthropicSse::toolUseResponse('toolu_skill', 'Skill', [
                'skill' => 'health-check',
            ]),
            // Agent then calls custom tool
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Check all systems', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_db', 'CheckDB', []);
            },
            // Summary
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('3 tables', $toolResult);

                return MockAnthropicSse::textResponse('Health check passed. DB is healthy with 150 rows.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Run a full health check', new HaoCodeConfig(
            allowedTools: ['Skill', 'CheckDB'],
            skills: [
                new SdkSkill(
                    name: 'health-check',
                    description: 'Run system health check',
                    prompt: 'Check all systems: database, cache, and queues. Use CheckDB tool for database.',
                ),
            ],
            tools: [$dbTool],
        ));

        $this->assertStringContainsString('Health check passed', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 35: Conversation path applies system prompt overrides
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_applies_system_prompt_override_before_send(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'You are a release manager. Always think about rollback safety.',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Conversation prompt response.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            systemPrompt: 'You are a release manager. Always think about rollback safety.',
        ));

        $result = $conv->send('Give me a deployment recommendation.');

        $this->assertStringContainsString('Conversation prompt response', $result->text);
        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getSystemPrompt());

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 36: Conversation path registers SDK skills
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_registers_sdk_skills_before_send(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_skill_conv', 'Skill', [
                'skill' => 'release-guard',
                'args' => 'billing.php',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Validate release readiness', $toolResult);
                $this->assertStringContainsString('billing.php', $toolResult);
                $this->assertStringContainsString('rollback checklist', $toolResult);

                return MockAnthropicSse::textResponse('Release guard review complete.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'release-guard',
                    description: 'Validate release readiness before deployment',
                    prompt: 'Validate release readiness for $ARGUMENTS and include a rollback checklist.',
                ),
            ],
        ));

        $result = $conv->send('Review billing.php before deployment.');

        $this->assertStringContainsString('Release guard review complete', $result->text);

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 37: HaoCode::structured() parses fenced JSON
    // ──────────────────────────────────────────────────────────────

    public function test_structured_parses_markdown_fenced_json_response(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse(<<<'JSON'
```json
{"category":"shipping","priority":"high","score":95}
```
JSON),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this support ticket.', [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'score' => ['type' => 'integer'],
            ],
            'required' => ['category', 'priority', 'score'],
        ]);

        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result['priority']);
        $this->assertSame(95, $result->score);
        $this->assertInstanceOf(QueryResult::class, $result->queryResult);
        $this->assertStringContainsString('```json', $result->rawText);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 38: HaoCode::resume() restores prior session context
    // ──────────────────────────────────────────────────────────────

    public function test_resume_restores_previous_session_history(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('The project codename is ORBIT.'),
        ]);

        chdir($this->projectDir);

        $seedResult = HaoCode::query(
            'Remember that the project codename is ORBIT.',
            new HaoCodeConfig(allowedTools: [], ephemeral: false),
        );
        $this->assertNotNull($seedResult->sessionId);
        $this->assertFileExists($this->sessionDir.'/'.$seedResult->sessionId.'.jsonl');

        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('What is the project codename?', MockAnthropicSse::lastUserText($payload));
                $this->assertSame(
                    'Remember that the project codename is ORBIT.',
                    $payload['messages'][0]['content'] ?? null,
                );
                $this->assertSame('assistant', $payload['messages'][1]['role'] ?? null);

                return MockAnthropicSse::textResponse('The codename is ORBIT.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::resume($seedResult->sessionId);
        $result = $conv->send('What is the project codename?');

        $this->assertStringContainsString('ORBIT', $result->text);
        $this->assertSame($seedResult->sessionId, $result->sessionId);
        $this->assertSame(1, $result->turnsUsed);

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 39: continueSession prefers the matching working directory
    // ──────────────────────────────────────────────────────────────

    public function test_query_continue_session_prefers_latest_session_in_same_working_directory(): void
    {
        $otherProjectDir = $this->tempRoot.'/other-project';
        mkdir($otherProjectDir, 0755, true);

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Workspace alpha remembered.'),
        ]);

        chdir($this->tempRoot);
        HaoCode::query('Remember that this workspace is alpha.', new HaoCodeConfig(
            cwd: $this->projectDir,
            ephemeral: false,
        ));

        sleep(1);

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Workspace beta remembered.'),
        ]);

        chdir($this->tempRoot);
        HaoCode::query('Remember that this workspace is beta.', new HaoCodeConfig(
            cwd: $otherProjectDir,
            ephemeral: false,
        ));

        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('Which workspace am I in?', MockAnthropicSse::lastUserText($payload));
                $this->assertSame(
                    'Remember that this workspace is alpha.',
                    $payload['messages'][0]['content'] ?? null,
                );

                return MockAnthropicSse::textResponse('You are in workspace alpha.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Which workspace am I in?', new HaoCodeConfig(
            continueSession: true,
            cwd: $this->projectDir,
        ));

        $this->assertStringContainsString('workspace alpha', $result->text);
    }

    public function test_independent_queries_do_not_share_read_before_write_state(): void
    {
        $file = $this->projectDir.'/isolated.txt';
        file_put_contents($file, 'before');

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_read', 'Read', ['file_path' => $file]),
            MockAnthropicSse::textResponse('Read complete.'),
            MockAnthropicSse::toolUseResponse('toolu_edit', 'Edit', [
                'file_path' => $file,
                'old_string' => 'before',
                'new_string' => 'after',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString('must be read before writing', MockAnthropicSse::lastToolResultText($payload) ?? '');

                return MockAnthropicSse::textResponse('Edit was correctly blocked.');
            },
        ]);

        HaoCode::query('Read the file', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: ['Read'],
            ephemeral: true,
        ));
        HaoCode::query('Edit the file without reading it', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: ['Edit'],
            permissionMode: 'bypass_permissions',
            ephemeral: true,
        ));

        $this->assertSame('before', file_get_contents($file));
    }

    public function test_credential_pool_is_applied_by_hao_code_query(): void
    {
        $pool = new CredentialPool;
        $pool->add('anthropic', new Credential(apiKey: 'pool-key', id: 'pool'));

        $this->bootWithMock([
            function (array $payload, int $requestNumber, array $request): MockResponse {
                $this->assertStringContainsString('pool-key', json_encode($request['headers']));

                return MockAnthropicSse::textResponse('Pool applied.');
            },
        ]);
        config(['haocode.api_key' => '']);

        $result = HaoCode::query('Use the pool', new HaoCodeConfig(
            credentialPool: $pool,
            allowedTools: [],
            ephemeral: true,
        ));

        $this->assertSame('Pool applied.', $result->text);
    }

    public function test_structured_uses_response_schema_from_config(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $prompt = MockAnthropicSse::lastUserText($payload) ?? '';
                $this->assertStringContainsString('configured_field', $prompt);
                $this->assertStringNotContainsString('method_field', $prompt);

                return MockAnthropicSse::textResponse('{"configured_field":"ok"}');
            },
        ]);

        $result = HaoCode::structured('Return data', [
            'type' => 'object',
            'properties' => ['method_field' => ['type' => 'string']],
        ], new HaoCodeConfig(
            responseSchema: [
                'type' => 'object',
                'properties' => ['configured_field' => ['type' => 'string']],
            ],
        ));

        $this->assertSame('ok', $result->configured_field);
    }

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

    // ══════════════════════════════════════════════════════════════
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

    public function test_stream_emits_interrupt_without_fake_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_hitl_bash', 'Bash', [
                'command' => 'echo guarded',
                'description' => 'Guarded command',
            ]),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Run it', new HaoCodeConfig(
            allowedTools: ['Bash'],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'ask',
        )));

        $this->assertCount(1, array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
        $this->assertCount(0, array_filter($messages, fn (Message $message): bool => $message->isResult()));
    }

    public function test_stream_interrupt_can_resume_to_one_real_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('stream-write', 'Write', [
                'file_path' => 'stream-resume.txt',
                'content' => 'ok',
            ]),
            MockAnthropicSse::textResponse('Stream resume completed.'),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;

        $resumed = iterator_to_array(HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('stream-write')],
            $config,
        ));
        $results = array_values(array_filter($resumed, fn (Message $message): bool => $message->isResult()));

        $this->assertCount(1, $results);
        $this->assertSame('Stream resume completed.', $results[0]->text);
        $this->assertSame(32, $results[0]->usage['last_turn_input_tokens']);
    }

    public function test_stream_resume_closes_sandbox_before_terminal_result_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('terminal-stream-write', 'Write', [
                'file_path' => 'terminal-stream.txt',
                'content' => 'ok',
            ]),
            MockAnthropicSse::textResponse('Terminal stream resume completed.'),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('terminal-stream-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $result = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isResult()) {
                    $result = $resumed->current();
                    break;
                }
                $resumed->next();
            }
            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame('Terminal stream resume completed.', $result->text);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_stream_resume_closes_sandbox_before_terminal_error_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('terminal-error-write', 'Write', [
                'file_path' => 'terminal-error.txt',
                'content' => 'ok',
            ]),
            new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"resume failed"}}',
                ['http_code' => 400],
            ),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('terminal-error-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $error = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isError()) {
                    $error = $resumed->current();
                    break;
                }
                $resumed->next();
            }
            $this->assertInstanceOf(Message::class, $error);
            $this->assertSame('resume failed', $error->error);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_stream_resume_preserves_sandbox_before_a_second_interrupt_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('first-stream-write', 'Write', [
                'file_path' => 'first-stream.txt',
                'content' => 'first',
            ]),
            MockAnthropicSse::toolUseResponse('second-stream-write', 'Write', [
                'file_path' => 'second-stream.txt',
                'content' => 'second',
            ]),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the first file', $config));
        $firstInterrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($firstInterrupt->sessionId, $firstInterrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $firstInterrupt->sessionId,
            $firstInterrupt->id,
            [HumanDecision::approve('first-stream-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $secondInterrupt = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isInterrupt()) {
                    $secondInterrupt = $resumed->current();
                    break;
                }
                $resumed->next();
            }

            $this->assertInstanceOf(Message::class, $secondInterrupt);
            $this->assertSame('second-stream-write', $secondInterrupt->interrupt?->actions[0]->id);
            $this->assertDirectoryExists($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_multi_tool_interrupt_executes_unguarded_sibling_once_and_checkpoints_its_result(): void
    {
        $executions = 0;
        $lookup = new class($executions) extends SdkTool {
            public function __construct(private int &$executions) {}
            public function name(): string { return 'Lookup'; }
            public function description(): string { return 'Return a stable lookup value.'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { $this->executions++; return 'lookup-value'; }
            public function isReadOnly(array $input): bool { return true; }
        };
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'lookup-1', 'name' => 'Lookup', 'input' => []],
                ['id' => 'write-1', 'name' => 'Write', 'input' => ['file_path' => 'batch.txt', 'content' => 'batch']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('lookup-value', $results);
                $this->assertStringContainsString('batch.txt', $results);
                return MockAnthropicSse::textResponse('Batch resumed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Lookup', 'Write'],
            tools: [$lookup],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Lookup then write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }
        $this->assertSame(1, $executions);
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('write-1')],
            $config,
        );
        $this->assertSame(1, $executions);
        $this->assertSame('Batch resumed.', $result->text);
    }

    public function test_hitl_resume_promotes_prior_read_receipts_only_after_interrupted_batch_results_are_visible(): void
    {
        file_put_contents($this->projectDir.'/target.txt', 'old-content');
        $makeRevisionWriteTool = static fn (string $name): SdkTool => new class($name) extends SdkTool {
            public function __construct(private readonly string $toolName) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'Write a file after checking its read receipt.'; }
            public function parameters(): array
            {
                return [
                    'file_path' => ['type' => 'string', 'required' => true],
                    'content' => ['type' => 'string', 'required' => true],
                ];
            }
            public function handle(array $input): string { return 'unused'; }
            public function isReadOnly(array $input): bool { return $this->toolName === 'ReceiptWrite'; }
            public function call(array $input, \HaoCode\Tools\ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                $path = (string) $input['file_path'];
                if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
                    $path = rtrim($context->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
                }
                $content = (string) $input['content'];
                if (file_exists($path) && ($error = $context->fileRevisionError($path)) !== null) {
                    return \HaoCode\Tools\ToolResult::error($error);
                }
                file_put_contents($path, $content);
                $context->recordFileRead($path, $content);

                return \HaoCode\Tools\ToolResult::success($this->toolName.' wrote '.$path);
            }
        };
        $sensitiveWrite = $makeRevisionWriteTool('SensitiveWrite');
        $receiptWrite = $makeRevisionWriteTool('ReceiptWrite');
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'read-before-hitl', 'name' => 'Read', 'input' => ['file_path' => 'target.txt']],
                ['id' => 'sensitive-write', 'name' => 'SensitiveWrite', 'input' => ['file_path' => 'target.txt', 'content' => 'new-content']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('old-content', $results);
                $this->assertStringContainsString('Read tool first', $results);

                return MockAnthropicSse::toolUseResponse('write-after-visible-read', 'ReceiptWrite', [
                    'file_path' => 'target.txt',
                    'content' => 'new-content',
                ], 'msg_write_after_visible_read');
            },
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('ReceiptWrite wrote', $results);

                return MockAnthropicSse::textResponse('Visible read retry completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'SensitiveWrite', 'ReceiptWrite'],
            tools: [$sensitiveWrite, $receiptWrite],
            ephemeral: false,
            interruptOn: ['SensitiveWrite' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Read then request a sensitive write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $this->assertSame('old-content', file_get_contents($this->projectDir.'/target.txt'));
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('sensitive-write')],
            $config,
        );

        $this->assertSame('Visible read retry completed.', $result->text);
        $this->assertSame('new-content', file_get_contents($this->projectDir.'/target.txt'));
    }

    public function test_hitl_resume_compacts_large_prior_read_results_before_promoting_receipts(): void
    {
        $largeContent = str_repeat('A', 130_000);
        file_put_contents($this->projectDir.'/target.txt', $largeContent);
        $makeRevisionWriteTool = static fn (string $name): SdkTool => new class($name) extends SdkTool {
            public function __construct(private readonly string $toolName) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'Write a file after checking its read receipt.'; }
            public function parameters(): array
            {
                return [
                    'file_path' => ['type' => 'string', 'required' => true],
                    'content' => ['type' => 'string', 'required' => true],
                ];
            }
            public function handle(array $input): string { return 'unused'; }
            public function isReadOnly(array $input): bool { return $this->toolName === 'ReceiptWrite'; }
            public function call(array $input, \HaoCode\Tools\ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                $path = (string) $input['file_path'];
                if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
                    $path = rtrim($context->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
                }
                if (file_exists($path) && ($error = $context->fileRevisionError($path)) !== null) {
                    return \HaoCode\Tools\ToolResult::error($error);
                }
                $content = (string) $input['content'];
                file_put_contents($path, $content);
                $context->recordFileRead($path, $content);

                return \HaoCode\Tools\ToolResult::success($this->toolName.' wrote '.$path);
            }
        };
        $sensitiveWrite = $makeRevisionWriteTool('SensitiveWrite');
        $receiptWrite = $makeRevisionWriteTool('ReceiptWrite');
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'large-read-before-hitl', 'name' => 'Read', 'input' => ['file_path' => 'target.txt']],
                ['id' => 'sensitive-write', 'name' => 'SensitiveWrite', 'input' => ['file_path' => 'target.txt', 'content' => 'new-content']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('<persisted-output>', $results);
                $this->assertStringContainsString('Read tool first', $results);

                return MockAnthropicSse::toolUseResponse('write-after-compacted-read', 'ReceiptWrite', [
                    'file_path' => 'target.txt',
                    'content' => 'new-content',
                ], 'msg_write_after_compacted_read');
            },
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Read the complete file first', $results);

                return MockAnthropicSse::textResponse('Compacted read retry stayed blocked.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'SensitiveWrite', 'ReceiptWrite'],
            tools: [$sensitiveWrite, $receiptWrite],
            ephemeral: false,
            interruptOn: ['SensitiveWrite' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Read a large file then request a sensitive write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('sensitive-write')],
            $config,
        );

        $this->assertSame('Compacted read retry stayed blocked.', $result->text);
        $this->assertSame($largeContent, file_get_contents($this->projectDir.'/target.txt'));
    }

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

    public function test_foreground_worktree_agent_finalizes_and_reports_after_interrupt_resume(): void
    {
        exec('git -C '.escapeshellarg($this->projectDir).' init -q', $output, $code);
        $this->assertSame(0, $code);
        exec('git -C '.escapeshellarg($this->projectDir).' config user.email test@example.test');
        exec('git -C '.escapeshellarg($this->projectDir).' config user.name "HaoCode Test"');
        file_put_contents($this->projectDir.'/README.md', "root\n");
        exec('git -C '.escapeshellarg($this->projectDir).' add README.md');
        exec('git -C '.escapeshellarg($this->projectDir).' commit -qm initial', $output, $code);
        $this->assertSame(0, $code);

        $worktreePath = null;
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('worktree-agent', 'Agent', [
                'description' => 'Write in isolated worktree',
                'prompt' => 'Write isolated.txt with the word isolated.',
                'subagent_type' => 'general-purpose',
                'isolation' => 'worktree',
            ]),
            MockAnthropicSse::toolUseResponse('worktree-write', 'Write', [
                'file_path' => 'isolated.txt',
                'content' => 'isolated',
            ]),
            MockAnthropicSse::textResponse('Child completed isolated work.'),
            function (array $payload) use (&$worktreePath): MockResponse {
                $childResult = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Worktree with changes retained at:', $childResult);
                preg_match('/retained at: (.+) \\(branch:/', $childResult, $matches);
                $worktreePath = $matches[1] ?? null;

                return MockAnthropicSse::textResponse('Parent completed after isolated child.');
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
            HaoCode::query('Delegate isolated work', $config);
            $this->fail('Expected child worktree interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $snapshot = $state['checkpoint']['run_snapshot'];
        $this->assertTrue($snapshot['managed_worktree']);
        $this->assertDirectoryExists($snapshot['worktree_path']);

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('worktree-write')],
            $config,
        );

        $this->assertIsString($worktreePath);
        $this->assertSame($worktreePath, $result->usage['worktree_path']);
        $this->assertTrue($result->usage['worktree_retained']);
        $this->assertStringContainsString($worktreePath, $result->text);
        $this->assertSame('isolated', file_get_contents($worktreePath.'/isolated.txt'));
        $this->assertFileDoesNotExist($this->projectDir.'/isolated.txt');
    }

    public function test_approved_agent_gate_can_pause_again_for_a_child_interrupt_without_reexecution(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('gated-agent', 'Agent', [
                'description' => 'Run gated child',
                'prompt' => 'Write gated-child.txt with the word child.',
            ]),
            MockAnthropicSse::toolUseResponse('gated-child-write', 'Write', [
                'file_path' => 'gated-child.txt',
                'content' => 'child',
            ]),
            MockAnthropicSse::textResponse('Gated child completed.'),
            function (array $payload): MockResponse {
                $agentToolUses = 0;
                foreach ($payload['messages'] ?? [] as $message) {
                    if (($message['role'] ?? null) !== 'assistant') {
                        continue;
                    }
                    foreach ($message['content'] ?? [] as $block) {
                        if (($block['type'] ?? null) === 'tool_use'
                            && ($block['id'] ?? null) === 'gated-agent') {
                            $agentToolUses++;
                        }
                    }
                }
                $this->assertSame(1, $agentToolUses);

                return MockAnthropicSse::textResponse('Gated parent completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Agent', 'Write'],
            ephemeral: false,
            interruptOn: ['Agent' => true, 'Write' => true],
            hitlMode: 'ask',
        );
        try {
            HaoCode::query('Run a gated child', $config);
            $this->fail('Expected parent agent gate.');
        } catch (HumanInterruptException $e) {
            $parentInterrupt = $e->interrupt;
        }
        try {
            HaoCode::resumeInterrupt($parentInterrupt->sessionId, $parentInterrupt->id, [
                HumanDecision::approve('gated-agent'),
            ], $config);
            $this->fail('Expected child write gate.');
        } catch (HumanInterruptException $e) {
            $childInterrupt = $e->interrupt;
        }
        $childState = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($childInterrupt->sessionId, $childInterrupt->id);
        $snapshot = $childState['checkpoint']['run_snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('general-purpose', $snapshot['agent_type']);
        $this->assertSame(realpath($this->projectDir), realpath($snapshot['cwd']));
        $this->assertSame(49, $snapshot['max_turns_remaining']);

        $result = HaoCode::resumeInterrupt($childInterrupt->sessionId, $childInterrupt->id, [
            HumanDecision::approve('gated-child-write'),
        ], $config);

        $this->assertSame('child', file_get_contents($this->projectDir.'/gated-child.txt'));
        $this->assertSame('Gated parent completed.', $result->text);
    }

    public function test_resume_redirect_forwards_images_to_the_conversation(): void
    {
        $payloads = [];
        $this->bootWithMock([
            function (array $payload) use (&$payloads): MockResponse {
                $payloads[] = $payload;
                return MockAnthropicSse::textResponse('first');
            },
            function (array $payload) use (&$payloads): MockResponse {
                $payloads[] = $payload;
                return MockAnthropicSse::textResponse('second');
            },
        ]);

        $first = HaoCode::query('hi', new HaoCodeConfig(ephemeral: false));
        $this->assertNotNull($first->sessionId);

        // Regression: resuming a session through the HaoCode facade must carry
        // config images into the conversation (the v1.15.0 Runner refactor
        // dropped them on the resume redirect).
        HaoCode::query('look at this', new HaoCodeConfig(
            ephemeral: false,
            sessionId: $first->sessionId,
            images: ['data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='],
        ));

        $this->assertCount(2, $payloads);
        $messages = $payloads[1]['messages'] ?? [];
        $lastUser = end($messages);
        $blocks = is_array($lastUser['content'] ?? null) ? $lastUser['content'] : [];
        $this->assertContains('image', array_column($blocks, 'type'));
    }

    //  Infrastructure
    // ══════════════════════════════════════════════════════════════

    private function bootWithMock(
        array $responses,
        ?array &$capturedRequests = null,
        string $model = 'claude-test',
    ): void
    {
        $requests = [];
        $this->refreshApplication();
        $this->app->useStoragePath($this->storageDir);

        $_SERVER['HAOCODE_STORAGE_PATH'] = $this->storageDir;
        putenv('HAOCODE_STORAGE_PATH='.$this->storageDir);

        config([
            'haocode.api_key' => 'test-key',
            'haocode.api_base_url' => 'https://mock.anthropic.test',
            'haocode.model' => $model,
            'haocode.max_tokens' => 4096,
            'haocode.permission_mode' => 'bypass_permissions',
            // These E2E tests exercise interrupt mechanics under 'ask'; pin the
            // process default so the config-file default ('smart') stays untested here.
            'haocode.hitl_mode' => 'ask',
            'haocode.global_settings_path' => $this->homeDir.'/.haocode/settings.json',
            'haocode.session_path' => $this->sessionDir,
            'haocode.api_stream_idle_timeout' => 2,
            'haocode.api_stream_poll_timeout' => 0.01,
        ]);

        $this->app->singleton(StreamingClient::class, function ($app) use (&$requests, $responses, $model): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: $model,
                baseUrl: 'https://mock.anthropic.test',
                maxTokens: 4096,
                httpClient: MockAnthropicSse::client($responses, $requests),
                settingsManager: $app->make(SettingsManager::class),
                idleTimeoutSeconds: 2,
                streamPollTimeoutSeconds: 0.01,
            );
        });

        if ($capturedRequests !== null) {
            $capturedRequests = &$requests;
        }
    }

    private function setHomeDirectory(string $home): void
    {
        if ($home === '') {
            putenv('HOME');
            unset($_SERVER['HOME']);

            return;
        }

        putenv('HOME='.$home);
        $_SERVER['HOME'] = $home;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test: structured() schema validation (chatgpt P2)
    // ──────────────────────────────────────────────────────────────

    public function test_structured_accepts_response_that_satisfies_schema(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"high"}'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ]);

        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result['priority']);
    }

    public function test_structured_retries_once_when_model_returns_invalid_enum(): void
    {
        // First response violates the enum; second response is valid. Track
        // request count via a closure response so we don't depend on the
        // bootWithMock captured-reference helper.
        $requestCount = 0;
        $lastPayload = null;
        $this->bootWithMock([
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;
                return MockAnthropicSse::textResponse('{"category":"shipping","priority":"urgent"}');
            },
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;
                return MockAnthropicSse::textResponse('{"category":"shipping","priority":"high"}');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ]);

        $this->assertSame('high', $result['priority']);
        // Confirm the retry actually happened: two provider requests were made.
        $this->assertSame(2, $requestCount, 'invalid first response must trigger exactly one retry');
        // Correction is a new user turn on the same conversation (not messages[0]).
        $secondPromptStr = (string) (MockAnthropicSse::lastUserText($lastPayload) ?? '');
        $this->assertStringContainsString('Your previous response was not acceptable', $secondPromptStr);
    }

    public function test_structured_retries_share_one_total_budget(): void
    {
        $invalid = '{"category":"shipping","priority":"urgent"}';
        $valid = '{"category":"shipping","priority":"high"}';
        $model = 'claude-sonnet-4-6';
        $this->bootWithMock([
            MockAnthropicSse::textResponse($invalid, model: $model),
            MockAnthropicSse::textResponse($valid, model: $model),
        ], model: $model);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ], new HaoCodeConfig(
            maxBudgetUsd: 1.0,
        ));

        $expectedCost = (
            64 * 3
            + (strlen($invalid) + strlen($valid)) * 15
        ) / 1_000_000;
        $this->assertNotNull($result->queryResult);
        $this->assertEqualsWithDelta(
            $expectedCost,
            $result->queryResult->cost,
            0.000001,
        );
    }

    public function test_structured_throws_when_retry_exhausted(): void
    {
        // Both responses violate the enum. Default maxRetries=1 means one retry,
        // so the second invalid response must throw.
        $this->bootWithMock([
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"urgent"}'),
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"asap"}'),
        ]);

        chdir($this->projectDir);

        try {
            HaoCode::structured('Classify this ticket.', [
                'type' => 'object',
                'required' => ['category', 'priority'],
                'properties' => [
                    'category' => ['type' => 'string'],
                    'priority' => ['enum' => ['low', 'medium', 'high']],
                ],
            ]);
            $this->fail('Expected StructuredResultValidationException');
        } catch (StructuredResultValidationException $e) {
            $this->assertNotEmpty($e->validationErrors);
            $this->assertStringContainsString('schema validation', $e->getMessage());
            // Raw response + at least one error path are surfaced for diagnosis.
            $this->assertNotSame('', $e->rawResponse);
        }
    }

    public function test_structured_respects_zero_max_retries(): void
    {
        // With structuredMaxRetries=0, a single invalid response throws
        // immediately without a second request.
        $requestCount = 0;
        $this->bootWithMock([
            function (array $payload) use (&$requestCount): MockResponse {
                $requestCount++;
                return MockAnthropicSse::textResponse('{"category":"shipping"}');
            },
        ]);

        chdir($this->projectDir);

        try {
            HaoCode::structured('Classify this ticket.', [
                'type' => 'object',
                'required' => ['category', 'priority'],
                'properties' => [
                    'category' => ['type' => 'string'],
                    'priority' => ['enum' => ['low', 'medium', 'high']],
                ],
            ], new HaoCodeConfig(structuredMaxRetries: 0));
            $this->fail('Expected StructuredResultValidationException');
        } catch (StructuredResultValidationException $e) {
            // Only one provider request — no retry attempted.
            $this->assertSame(1, $requestCount, 'structuredMaxRetries=0 must not retry');
            $this->assertStringContainsString('priority', implode(' ', $e->validationErrors));
        }
    }

    public function test_structured_retry_reuses_conversation_so_tools_run_once(): void
    {
        // Ephemeral + mutating tool: tool once, then invalid JSON, then correction
        // only. The third user turn must be a correction prompt, not a fresh task
        // that would invite re-running Write.
        $model = 'claude-sonnet-4-6';
        $invalidJson = '{"status":';
        $validJson = '{"status":"ok"}';
        $requestCount = 0;
        $toolUseResponses = 0;
        $this->bootWithMock([
            function () use (&$requestCount, &$toolUseResponses, $model): MockResponse {
                $requestCount++;
                $toolUseResponses++;

                return MockAnthropicSse::toolUseResponse('side-effect-1', 'Write', [
                    'file_path' => 'once.txt',
                    'content' => "written-once\n",
                ], model: $model);
            },
            function () use (&$requestCount, $invalidJson, $model): MockResponse {
                $requestCount++;

                return MockAnthropicSse::textResponse($invalidJson, model: $model);
            },
            function (array $payload) use (&$requestCount, $validJson, $model): MockResponse {
                $requestCount++;
                $lastUser = (string) (MockAnthropicSse::lastUserText($payload) ?? '');
                $this->assertStringContainsString(
                    'Do not call tools again',
                    $lastUser,
                    'retry must send a correction turn, not rebuild the full task',
                );
                $this->assertStringNotContainsString('Write once.txt then report status', $lastUser);

                return MockAnthropicSse::textResponse($validJson, model: $model);
            },
        ], model: $model);

        chdir($this->projectDir);

        $result = HaoCode::structured('Write once.txt then report status', [
            'type' => 'object',
            'required' => ['status'],
            'properties' => ['status' => ['type' => 'string']],
        ], new HaoCodeConfig(
            allowedTools: ['Write'],
            permissionMode: 'bypass_permissions',
            ephemeral: true,
            structuredMaxRetries: 2,
        ));

        $this->assertSame('ok', $result->status);
        $this->assertSame(1, $toolUseResponses, 'model must issue Write only once across structured retries');
        $this->assertFileExists($this->projectDir.'/once.txt');
        $this->assertSame("written-once\n", file_get_contents($this->projectDir.'/once.txt'));
        $this->assertSame(3, $requestCount);
        $this->assertSame(128, $result->queryResult?->usage['input_tokens']);
        $expectedCost = (
            128 * 3
            + (1 + strlen($invalidJson) + strlen($validJson)) * 15
        ) / 1_000_000;
        $this->assertEqualsWithDelta($expectedCost, $result->queryResult?->cost, 0.000001);
    }

    public function test_structured_retries_on_invalid_json_syntax(): void
    {
        $requestCount = 0;
        $lastPayload = null;
        $this->bootWithMock([
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;
                // Truncated JSON — must enter the same retry budget as schema violations.
                return MockAnthropicSse::textResponse('{"category":"shipping",');
            },
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;

                return MockAnthropicSse::textResponse('{"category":"shipping","priority":"high"}');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ]);

        $this->assertSame('high', $result['priority']);
        $this->assertSame(2, $requestCount, 'invalid JSON must trigger a retry');
        $secondPromptStr = (string) (MockAnthropicSse::lastUserText($lastPayload) ?? '');
        $this->assertStringContainsString('JSON syntax error', $secondPromptStr);
    }

    public function test_structured_rejects_unusable_schema_before_model_call(): void
    {
        $requestCount = 0;
        $this->bootWithMock([
            function () use (&$requestCount): MockResponse {
                $requestCount++;

                return MockAnthropicSse::textResponse('{}');
            },
        ]);

        chdir($this->projectDir);

        try {
            HaoCode::structured('x', [
                // Invalid JSON Schema: type object with non-object properties shape
                // that swaggest cannot import.
                'type' => 'not-a-real-schema-type-xyz',
            ], new HaoCodeConfig(structuredMaxRetries: 2));
            $this->fail('Expected StructuredResultValidationException for bad schema');
        } catch (StructuredResultValidationException $e) {
            $this->assertStringContainsString('schema is invalid', strtolower($e->getMessage()));
            $this->assertSame(0, $requestCount, 'broken schema must not call the model');
        }
    }

    public function test_structured_rejects_external_schema_ref_without_io(): void
    {
        $scheme = 'haocode-structured-schema-probe';
        $this->assertNotContains($scheme, stream_get_wrappers());
        $this->assertTrue(stream_wrapper_register(
            $scheme,
            StructuredSchemaProbeStreamWrapper::class,
        ));
        StructuredSchemaProbeStreamWrapper::$openCalls = 0;
        $requestCount = 0;
        $this->bootWithMock([
            function () use (&$requestCount): MockResponse {
                $requestCount++;

                return MockAnthropicSse::textResponse('{}');
            },
        ]);

        chdir($this->projectDir);

        try {
            try {
                HaoCode::structured('x', [
                    'type' => 'object',
                    '$ref' => "{$scheme}://internal.example/schema.json",
                ], new HaoCodeConfig(structuredMaxRetries: 2));
                $this->fail('Expected external schema reference to be rejected.');
            } catch (StructuredResultValidationException $exception) {
                $this->assertStringContainsString(
                    'schema is invalid',
                    strtolower($exception->getMessage()),
                );
                $this->assertSame(0, $requestCount, 'unsafe schema must not call the model');
                $this->assertSame(0, StructuredSchemaProbeStreamWrapper::$openCalls);
            }
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }
}

final class StructuredSchemaProbeStreamWrapper
{
    public mixed $context;

    public static int $openCalls = 0;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$openCalls++;

        return false;
    }
}
