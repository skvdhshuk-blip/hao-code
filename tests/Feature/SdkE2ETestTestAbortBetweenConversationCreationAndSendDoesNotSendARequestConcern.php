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

trait SdkE2ETestTestAbortBetweenConversationCreationAndSendDoesNotSendARequestConcern
{

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
}
