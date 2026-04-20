<?php

namespace Tests\Unit;

use App\Services\Api\ApiErrorException;
use App\Services\Api\OpenAiChatProvider;
use App\Services\Api\StreamEvent;
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
