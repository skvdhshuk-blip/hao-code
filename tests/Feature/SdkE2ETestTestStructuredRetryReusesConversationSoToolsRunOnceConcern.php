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

trait SdkE2ETestTestStructuredRetryReusesConversationSoToolsRunOnceConcern
{

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
