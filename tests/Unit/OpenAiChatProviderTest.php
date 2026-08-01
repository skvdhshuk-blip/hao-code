<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiChatProviderTest extends TestCase
{
    public function test_build_payload_translates_messages_tools_and_system(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload(
            systemPrompt: [
                ['type' => 'text', 'text' => 'You are helpful.'],
                ['type' => 'text', 'text' => 'Be terse.', 'cache_control' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'text', 'text' => 'ok'],
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'file.txt'],
                ]],
            ],
            tools: [
                ['name' => 'Bash', 'description' => 'Run shell', 'input_schema' => ['type' => 'object']],
            ],
        );

        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertTrue($payload['stream']);
        $this->assertSame(['include_usage' => true], $payload['stream_options']);

        // System message is always first and concatenates all system blocks.
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertStringContainsString('You are helpful.', $payload['messages'][0]['content']);
        $this->assertStringContainsString('Be terse.', $payload['messages'][0]['content']);

        $this->assertSame('user', $payload['messages'][1]['role']);
        $this->assertSame('hi', $payload['messages'][1]['content']);

        $this->assertSame('assistant', $payload['messages'][2]['role']);
        $this->assertSame('ok', $payload['messages'][2]['content']);
        $this->assertCount(1, $payload['messages'][2]['tool_calls']);
        $this->assertSame('call_1', $payload['messages'][2]['tool_calls'][0]['id']);
        $this->assertSame('Bash', $payload['messages'][2]['tool_calls'][0]['function']['name']);
        $this->assertSame('{"command":"ls"}', $payload['messages'][2]['tool_calls'][0]['function']['arguments']);

        $this->assertSame('tool', $payload['messages'][3]['role']);
        $this->assertSame('call_1', $payload['messages'][3]['tool_call_id']);
        $this->assertSame('file.txt', $payload['messages'][3]['content']);

        $this->assertCount(1, $payload['tools']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('Bash', $payload['tools'][0]['function']['name']);
    }

    public function test_build_payload_places_all_tool_results_before_retry_text(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-chat',
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload(
            systemPrompt: [],
            messages: [
                ['role' => 'assistant', 'content' => [
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'Read', 'input' => ['file_path' => 'a.php']],
                    ['type' => 'tool_use', 'id' => 'call_2', 'name' => 'Read', 'input' => ['file_path' => 'b.php']],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'first result'],
                    ['type' => 'tool_result', 'tool_use_id' => 'call_2', 'content' => 'second result'],
                    ['type' => 'text', 'text' => 'Retry with corrected input.'],
                ]],
            ],
            tools: [],
        );

        $this->assertSame(['assistant', 'tool', 'tool', 'user'], array_column($payload['messages'], 'role'));
        $this->assertSame('call_1', $payload['messages'][1]['tool_call_id']);
        $this->assertSame('call_2', $payload['messages'][2]['tool_call_id']);
        $this->assertSame('Retry with corrected input.', $payload['messages'][3]['content']);
    }

    public function test_build_payload_emits_reasoning_effort_when_thinking_enabled(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-reasoner',
            thinkingEnabled: true,
            thinkingBudget: 10000,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [['role' => 'user', 'content' => 'hi']], []);

        $this->assertSame('medium', $payload['reasoning_effort']);
    }

    public function test_build_payload_uses_deepseek_v4_thinking_contract(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-v4-flash',
            thinkingEnabled: true,
            thinkingBudget: 32000,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [['role' => 'user', 'content' => 'Fix the bug.']], []);

        $this->assertSame(['type' => 'enabled'], $payload['thinking']);
        $this->assertSame('max', $payload['reasoning_effort']);
    }

    public function test_build_payload_explicitly_disables_deepseek_v4_thinking(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-v4-flash',
            thinkingEnabled: false,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [['role' => 'user', 'content' => 'Answer directly.']], []);

        $this->assertSame(['type' => 'disabled'], $payload['thinking']);
        $this->assertArrayNotHasKey('reasoning_effort', $payload);
    }

    public function test_build_payload_replays_deepseek_reasoning_before_tool_results(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-v4-flash',
            thinkingEnabled: true,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'I should inspect composer.json first.'],
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'Read', 'input' => ['file_path' => 'composer.json']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{}'],
            ]],
        ], []);

        $this->assertSame('I should inspect composer.json first.', $payload['messages'][0]['reasoning_content']);
        $this->assertSame('call_1', $payload['messages'][0]['tool_calls'][0]['id']);
        $this->assertSame('tool', $payload['messages'][1]['role']);
    }

    public function test_build_payload_does_not_replay_reasoning_on_plain_assistant_turns(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-v4-flash',
            thinkingEnabled: true,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'Long private reasoning.'],
                ['type' => 'text', 'text' => 'The answer.'],
            ]],
            ['role' => 'user', 'content' => 'Continue.'],
        ], []);

        $this->assertSame('The answer.', $payload['messages'][0]['content']);
        $this->assertArrayNotHasKey('reasoning_content', $payload['messages'][0]);
    }

    public function test_usage_normalizes_deepseek_prefix_cache_tokens(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'deepseek-v4-flash',
            httpClient: new MockHttpClient([]),
        );
        $method = new \ReflectionMethod($provider, 'mapUsage');
        $usage = $method->invoke($provider, [
            'prompt_tokens' => 1000,
            'completion_tokens' => 20,
            'prompt_cache_hit_tokens' => 900,
            'prompt_cache_miss_tokens' => 100,
        ]);

        $this->assertSame(100, $usage['input_tokens']);
        $this->assertSame(1000, $usage['context_input_tokens']);
        $this->assertSame(900, $usage['cache_read_input_tokens']);
        $this->assertSame(20, $usage['output_tokens']);
    }

    public function test_usage_normalizes_nested_openai_cache_tokens(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4.1',
            httpClient: new MockHttpClient([]),
        );
        $method = new \ReflectionMethod($provider, 'mapUsage');
        $usage = $method->invoke($provider, [
            'prompt_tokens' => 1000,
            'completion_tokens' => 20,
            'prompt_tokens_details' => ['cached_tokens' => 600],
        ]);

        $this->assertSame(400, $usage['input_tokens']);
        $this->assertSame(1000, $usage['context_input_tokens']);
        $this->assertSame(600, $usage['cache_read_input_tokens']);
    }

    public function test_stream_translates_text_turn(): void
    {
        $sse = $this->buildSseStream([
            ['id' => 'chatcmpl-1', 'model' => 'gpt-4o-mini', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['choices' => [['delta' => ['content' => 'Hello ']]]],
            ['choices' => [['delta' => ['content' => 'world']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertEventTypes([
            'message_start',
            'content_block_start',
            'content_block_delta',
            'content_block_delta',
            'content_block_stop',
            'message_delta',
            'message_stop',
        ], $events);

        $this->assertSame('chatcmpl-1', $events[0]->data['message']['id']);
        $this->assertSame('text', $events[1]->data['content_block']['type']);
        $this->assertSame('Hello ', $events[2]->data['delta']['text']);
        $this->assertSame('world', $events[3]->data['delta']['text']);
        $this->assertSame('end_turn', $events[5]->data['delta']['stop_reason']);
        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 4], $events[5]->data['usage']);
    }

    public function test_stream_translates_tool_calls_with_partial_arguments(): void
    {
        $sse = $this->buildSseStream([
            ['id' => 'chatcmpl-tool', 'model' => 'gpt-4o', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_xyz', 'type' => 'function', 'function' => ['name' => 'Bash', 'arguments' => '']],
            ]]]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"command":']],
            ]]]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"ls"}']],
            ]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertEventTypes([
            'message_start',
            'content_block_start',
            'content_block_delta',
            'content_block_delta',
            'content_block_stop',
            'message_delta',
            'message_stop',
        ], $events);

        $this->assertSame('tool_use', $events[1]->data['content_block']['type']);
        $this->assertSame('call_xyz', $events[1]->data['content_block']['id']);
        $this->assertSame('Bash', $events[1]->data['content_block']['name']);

        $this->assertSame('input_json_delta', $events[2]->data['delta']['type']);
        $this->assertSame('{"command":', $events[2]->data['delta']['partial_json']);
        $this->assertSame('"ls"}', $events[3]->data['delta']['partial_json']);

        $this->assertSame('tool_use', $events[5]->data['delta']['stop_reason']);
    }

    public function test_stream_translates_reasoning_content_into_thinking_delta(): void
    {
        $sse = $this->buildSseStream([
            ['id' => 'chatcmpl-r', 'model' => 'deepseek-reasoner', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['choices' => [['delta' => ['reasoning_content' => 'Step 1: ']]]],
            ['choices' => [['delta' => ['reasoning_content' => 'plan']]]],
            ['choices' => [['delta' => ['content' => 'Answer']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 6, 'completion_tokens_details' => ['reasoning_tokens' => 4]]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertEventTypes([
            'message_start',
            'content_block_start',      // thinking block opens
            'content_block_delta',      // thinking_delta
            'content_block_delta',      // thinking_delta
            'content_block_stop',       // thinking block closes before text
            'content_block_start',      // text block opens
            'content_block_delta',      // text_delta
            'content_block_stop',       // text block closes at finish
            'message_delta',
            'message_stop',
        ], $events);

        $this->assertSame('thinking', $events[1]->data['content_block']['type']);
        $this->assertSame('thinking_delta', $events[2]->data['delta']['type']);
        $this->assertSame('Step 1: ', $events[2]->data['delta']['thinking']);
        $this->assertSame('plan', $events[3]->data['delta']['thinking']);

        $this->assertSame('text', $events[5]->data['content_block']['type']);
        $this->assertSame('Answer', $events[6]->data['delta']['text']);

        $this->assertSame(4, $events[8]->data['usage']['thinking_tokens']);
    }

    public function test_stream_maps_finish_reason_length_to_max_tokens(): void
    {
        $sse = $this->buildSseStream([
            ['id' => 'x', 'model' => 'gpt-4o-mini', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['choices' => [['delta' => ['content' => 'partial']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'length']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]],
        ]);

        $events = $this->collectEvents($sse);
        $delta = array_values(array_filter($events, fn ($e) => $e->type === 'message_delta'))[0];
        $this->assertSame('max_tokens', $delta->data['delta']['stop_reason']);
    }

    public function test_stream_surfaces_mid_stream_error_payload(): void
    {
        $sse = $this->buildSseStream([
            ['error' => ['message' => 'upstream bad gateway', 'type' => 'invalid_request_error']],
        ]);

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('upstream bad gateway');

        $this->collectEvents($sse);
    }

    public function test_stream_captures_usage_when_sent_after_finish_reason_on_a_separate_chunk(): void
    {
        // OpenAI sends finish_reason on one chunk and the include_usage
        // totals on a subsequent usage-only chunk. Regression test for the
        // race where message_delta would be emitted before usage arrived.
        $sse = $this->buildSseStream([
            ['id' => 'x', 'model' => 'gpt-4o-mini', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['choices' => [['delta' => ['content' => 'hi']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 56]],
        ]);

        $events = $this->collectEvents($sse);
        $delta = array_values(array_filter($events, fn ($e) => $e->type === 'message_delta'))[0];

        $this->assertSame(['input_tokens' => 1234, 'output_tokens' => 56], $delta->data['usage']);
        $this->assertSame('end_turn', $delta->data['delta']['stop_reason']);
    }

    public function test_stream_surfaces_http_error_body(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                '{"error":{"type":"invalid_api_key","message":"bad key"}}',
                ['http_code' => 401]
            ),
        ]);

        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($provider->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hi']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('invalid_api_key', $e->getErrorType());
            $this->assertStringContainsString('bad key', $e->getMessage());
        }
    }

    public function test_native_wrapper_fixture_captures_rate_limit_headers_and_final_status(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: new MockHttpClient([]),
        );
        $reflection = new \ReflectionClass($provider);
        $headerMethod = $reflection->getMethod('extractRateLimitHeadersFromWrapperData');
        $statusMethod = $reflection->getMethod('extractStatusCodeFromWrapperData');
        $fixture = file(
            dirname(__DIR__).'/fixtures/openai-chat-native-rate-limit-headers.txt',
            FILE_IGNORE_NEW_LINES,
        );

        $this->assertIsArray($fixture);
        $headers = $headerMethod->invoke($provider, $fixture);

        $this->assertSame(429, $statusMethod->invoke($provider, $fixture));
        $this->assertSame('17', $headers['retry-after']);
        $this->assertSame('500', $headers['x-ratelimit-limit-requests']);
        $this->assertSame('0', $headers['x-ratelimit-remaining-requests']);
        $this->assertArrayNotHasKey('content-type', $headers);
        $this->assertSame($headers, $provider->getLastRateLimitHeaders());
    }

    public function test_stream_rejects_an_oversized_unterminated_sse_line(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([str_repeat('x', 4 * 1024 * 1024 + 1)], ['http_code' => 200]),
        ]);
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('SSE line exceeded');
        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));
    }

    // ─── custom request headers ─────────────────────────────────────────

    public function test_http_client_transport_merges_custom_headers_and_protects_authorization(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            $body = 'data: ' . json_encode([
                'id' => 'chatcmpl-h',
                'model' => 'gpt-4o-mini',
                'choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            $body .= "data: [DONE]\n\n";

            return new MockResponse([$body], ['http_code' => 200]);
        });

        $provider = new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
            headers: [
                'Editor-Version' => 'vscode/1.96.0',
                'Copilot-Integration-Id' => 'vscode-chat',
                'Accept' => 'application/json',     // same-name override wins
                'Authorization' => 'Bearer stolen', // auth header stays protected
            ],
        );

        iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ));

        $this->assertNotNull($capturedHeaders);
        $this->assertSame('vscode/1.96.0', $this->headerLineValue($capturedHeaders, 'editor-version'));
        $this->assertSame('vscode-chat', $this->headerLineValue($capturedHeaders, 'copilot-integration-id'));
        $this->assertSame('application/json', $this->headerLineValue($capturedHeaders, 'accept'));
        $this->assertSame('Bearer real-key', $this->headerLineValue($capturedHeaders, 'authorization'));
    }

    public function test_http_client_transport_resolves_custom_headers_from_settings_manager(): void
    {
        $settings = new \HaoCode\Services\Settings\SettingsManager(getcwd());
        $settings->set('api_key', 'real-key');
        $settings->set('headers', ['Editor-Version' => 'phpstorm/2024.3']);

        $provider = (new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            httpClient: new MockHttpClient([]),
            headers: ['Editor-Version' => 'ctor-value'],
        ))->withSettingsManager($settings);

        $headers = $provider->buildRequestHeaders();

        // Runtime settings win over the constructor map.
        $this->assertSame('phpstorm/2024.3', $headers['Editor-Version']);
        $this->assertSame('Bearer real-key', $headers['Authorization']);
    }

    public function test_native_transport_header_lines_merge_custom_headers(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'real-key',
            model: 'gpt-4o-mini',
            headers: [
                'Editor-Version' => 'vscode/1.96.0',
                'Content-Type' => 'application/vnd.custom+json', // same-name override
                'Authorization' => 'Bearer stolen',              // auth stays protected
            ],
        );

        $lines = $provider->buildNativeHeaderLines();

        $this->assertContains('Editor-Version: vscode/1.96.0', $lines);
        $this->assertContains('Content-Type: application/vnd.custom+json', $lines);
        $this->assertContains('Authorization: Bearer real-key', $lines);
        $this->assertContains('Connection: close', $lines);
        $this->assertNotContains('Authorization: Bearer stolen', $lines);

        // The overridden hardcoded default must not linger as a duplicate.
        $contentTypeLines = array_filter($lines, fn (string $l) => stripos($l, 'content-type:') === 0);
        $this->assertCount(1, $contentTypeLines);
    }

    public function test_native_transport_respects_caller_connection_header(): void
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'k',
            model: 'gpt-4o-mini',
            headers: ['Connection' => 'keep-alive'],
        );

        $lines = $provider->buildNativeHeaderLines();

        $this->assertContains('Connection: keep-alive', $lines);
        $this->assertNotContains('Connection: close', $lines);
    }

    public function test_http_error_preserves_rate_limit_headers_for_pool_failover(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                '{"error":{"type":"rate_limit_error","message":"slow down"}}',
                [
                    'http_code' => 429,
                    'response_headers' => [
                        'Retry-After: 23',
                        'x-ratelimit-remaining-requests: 0',
                    ],
                ],
            ),
        ]);
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $httpClient,
        );
        $maxRetries = new \ReflectionProperty($provider, 'maxRetries');
        $maxRetries->setValue($provider, 1);

        try {
            iterator_to_array($provider->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hi']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('rate_limit_error', $e->getErrorType());
            $this->assertSame('23', $provider->getLastRateLimitHeaders()['retry-after'] ?? null);
            $this->assertSame('0', $provider->getLastRateLimitHeaders()['x-ratelimit-remaining-requests'] ?? null);
        }
    }

    public function test_default_headers_are_unchanged_when_no_custom_headers(): void
    {
        $provider = new OpenAiChatProvider(apiKey: 'k', model: 'gpt-4o-mini');

        $this->assertSame([
            'Authorization' => 'Bearer k',
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ], $provider->buildRequestHeaders());

        $this->assertSame([
            'Authorization: Bearer k',
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Connection: close',
        ], $provider->buildNativeHeaderLines());
    }

    /**
     * @param string[] $headers
     */
    private function headerLineValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function buildSseStream(array $payloads): MockHttpClient
    {
        $body = '';
        foreach ($payloads as $payload) {
            $body .= 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new MockHttpClient([
            new MockResponse([$body], ['http_code' => 200]),
        ]);
    }

    private function collectEvents(MockHttpClient $client): array
    {
        $provider = new OpenAiChatProvider(
            apiKey: 'test',
            model: 'gpt-4o-mini',
            httpClient: $client,
        );

        return iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
        ), false);
    }

    private function assertEventTypes(array $expected, array $events): void
    {
        $actual = array_map(fn (StreamEvent $e) => $e->type, $events);
        $this->assertSame($expected, $actual);
    }
}
