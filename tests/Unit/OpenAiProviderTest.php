<?php

namespace Tests\Unit;

use HaoCode\Services\Api\OpenAiProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiProviderTest extends TestCase
{
    public function test_build_payload_translates_tools_messages_and_system(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test',
            model: 'gpt-5-codex',
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
                    ['type' => 'text', 'text' => 'thinking...'],
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'file.txt'],
                ]],
            ],
            tools: [
                ['name' => 'Bash', 'description' => 'Run shell', 'input_schema' => ['type' => 'object', 'properties' => []]],
            ],
        );

        $this->assertSame('gpt-5-codex', $payload['model']);
        $this->assertTrue($payload['stream']);
        $this->assertFalse($payload['store']);
        $this->assertStringContainsString('You are helpful.', $payload['instructions']);
        $this->assertStringContainsString('Be terse.', $payload['instructions']);

        // Tools should use flat Responses-API function shape.
        $this->assertCount(1, $payload['tools']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('Bash', $payload['tools'][0]['name']);
        $this->assertSame('Run shell', $payload['tools'][0]['description']);
        $this->assertArrayHasKey('parameters', $payload['tools'][0]);

        // Input item ordering: user msg, assistant text msg, function_call, function_call_output.
        $input = $payload['input'];
        $this->assertSame('message', $input[0]['type']);
        $this->assertSame('user', $input[0]['role']);
        $this->assertSame('input_text', $input[0]['content'][0]['type']);
        $this->assertSame('hi', $input[0]['content'][0]['text']);

        $this->assertSame('message', $input[1]['type']);
        $this->assertSame('assistant', $input[1]['role']);
        $this->assertSame('output_text', $input[1]['content'][0]['type']);
        $this->assertSame('thinking...', $input[1]['content'][0]['text']);

        $this->assertSame('function_call', $input[2]['type']);
        $this->assertSame('call_1', $input[2]['call_id']);
        $this->assertSame('Bash', $input[2]['name']);
        $this->assertSame('{"command":"ls"}', $input[2]['arguments']);

        $this->assertSame('function_call_output', $input[3]['type']);
        $this->assertSame('call_1', $input[3]['call_id']);
        $this->assertSame('file.txt', $input[3]['output']);
    }

    public function test_build_payload_skips_thinking_blocks_in_history(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test',
            model: 'gpt-5',
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload(
            systemPrompt: [],
            messages: [
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => 'hidden reasoning', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => 'the answer is 42'],
                ]],
            ],
            tools: [],
        );

        $this->assertCount(1, $payload['input']);
        $this->assertSame('message', $payload['input'][0]['type']);
        $this->assertSame('the answer is 42', $payload['input'][0]['content'][0]['text']);
    }

    public function test_build_payload_emits_reasoning_effort_when_thinking_enabled(): void
    {
        $provider = new OpenAiProvider(
            apiKey: 'test',
            model: 'gpt-5',
            thinkingEnabled: true,
            thinkingBudget: 10000,
            httpClient: new MockHttpClient([]),
        );

        $payload = $provider->buildPayload([], [['role' => 'user', 'content' => 'hi']], []);

        $this->assertSame(['effort' => 'medium', 'summary' => 'auto'], $payload['reasoning']);
    }

    public function test_stream_translates_text_turn_into_anthropic_events(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'response.created', 'data' => [
                'type' => 'response.created',
                'response' => ['id' => 'resp_1', 'model' => 'gpt-5'],
            ]],
            ['event' => 'response.output_item.added', 'data' => [
                'type' => 'response.output_item.added',
                'output_index' => 0,
                'item' => ['type' => 'message', 'id' => 'msg_1'],
            ]],
            ['event' => 'response.output_text.delta', 'data' => [
                'type' => 'response.output_text.delta',
                'output_index' => 0,
                'delta' => 'Hello ',
            ]],
            ['event' => 'response.output_text.delta', 'data' => [
                'type' => 'response.output_text.delta',
                'output_index' => 0,
                'delta' => 'world',
            ]],
            ['event' => 'response.output_item.done', 'data' => [
                'type' => 'response.output_item.done',
                'output_index' => 0,
                'item' => ['type' => 'message'],
            ]],
            ['event' => 'response.completed', 'data' => [
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_1',
                    'output' => [['type' => 'message']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
                ],
            ]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertEventTypes(
            ['message_start', 'content_block_start', 'content_block_delta', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop'],
            $events,
        );

        $this->assertSame('resp_1', $events[0]->data['message']['id']);
        $this->assertSame('gpt-5', $events[0]->data['message']['model']);

        $this->assertSame('text', $events[1]->data['content_block']['type']);
        $this->assertSame(0, $events[1]->data['index']);

        $this->assertSame('text_delta', $events[2]->data['delta']['type']);
        $this->assertSame('Hello ', $events[2]->data['delta']['text']);
        $this->assertSame('world', $events[3]->data['delta']['text']);

        $this->assertSame(0, $events[4]->data['index']);

        $this->assertSame('end_turn', $events[5]->data['delta']['stop_reason']);
        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 4], $events[5]->data['usage']);
    }

    public function test_stream_translates_function_call_into_tool_use_events(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'response.created', 'data' => [
                'type' => 'response.created',
                'response' => ['id' => 'resp_2', 'model' => 'gpt-5'],
            ]],
            ['event' => 'response.output_item.added', 'data' => [
                'type' => 'response.output_item.added',
                'output_index' => 0,
                'item' => ['type' => 'function_call', 'call_id' => 'call_xyz', 'name' => 'Bash', 'arguments' => ''],
            ]],
            ['event' => 'response.function_call_arguments.delta', 'data' => [
                'type' => 'response.function_call_arguments.delta',
                'output_index' => 0,
                'delta' => '{"command":',
            ]],
            ['event' => 'response.function_call_arguments.delta', 'data' => [
                'type' => 'response.function_call_arguments.delta',
                'output_index' => 0,
                'delta' => '"ls"}',
            ]],
            ['event' => 'response.output_item.done', 'data' => [
                'type' => 'response.output_item.done',
                'output_index' => 0,
                'item' => ['type' => 'function_call'],
            ]],
            ['event' => 'response.completed', 'data' => [
                'type' => 'response.completed',
                'response' => [
                    'output' => [['type' => 'function_call']],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
                ],
            ]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertEventTypes(
            ['message_start', 'content_block_start', 'content_block_delta', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop'],
            $events,
        );

        $this->assertSame('tool_use', $events[1]->data['content_block']['type']);
        $this->assertSame('call_xyz', $events[1]->data['content_block']['id']);
        $this->assertSame('Bash', $events[1]->data['content_block']['name']);

        $this->assertSame('input_json_delta', $events[2]->data['delta']['type']);
        $this->assertSame('{"command":', $events[2]->data['delta']['partial_json']);
        $this->assertSame('"ls"}', $events[3]->data['delta']['partial_json']);

        $this->assertSame('tool_use', $events[5]->data['delta']['stop_reason']);
    }

    public function test_stream_translates_reasoning_summary_into_thinking_delta(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'response.created', 'data' => ['type' => 'response.created', 'response' => ['id' => 'r', 'model' => 'gpt-5']]],
            ['event' => 'response.output_item.added', 'data' => [
                'type' => 'response.output_item.added',
                'output_index' => 0,
                'item' => ['type' => 'reasoning'],
            ]],
            ['event' => 'response.reasoning_summary_text.delta', 'data' => [
                'type' => 'response.reasoning_summary_text.delta',
                'output_index' => 0,
                'delta' => 'Planning...',
            ]],
            ['event' => 'response.output_item.done', 'data' => [
                'type' => 'response.output_item.done',
                'output_index' => 0,
                'item' => ['type' => 'reasoning'],
            ]],
            ['event' => 'response.completed', 'data' => [
                'type' => 'response.completed',
                'response' => ['output' => [['type' => 'reasoning']], 'usage' => []],
            ]],
        ]);

        $events = $this->collectEvents($sse);

        $this->assertSame('thinking', $events[1]->data['content_block']['type']);
        $this->assertSame('thinking_delta', $events[2]->data['delta']['type']);
        $this->assertSame('Planning...', $events[2]->data['delta']['thinking']);
    }

    public function test_stream_maps_incomplete_max_output_tokens_to_max_tokens_stop(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'response.created', 'data' => ['type' => 'response.created', 'response' => ['id' => 'r', 'model' => 'gpt-5']]],
            ['event' => 'response.incomplete', 'data' => [
                'type' => 'response.incomplete',
                'response' => [
                    'incomplete_details' => ['reason' => 'max_output_tokens'],
                    'output' => [],
                    'usage' => [],
                ],
            ]],
        ]);

        $events = $this->collectEvents($sse);
        $delta = array_values(array_filter($events, fn ($e) => $e->type === 'message_delta'))[0];
        $this->assertSame('max_tokens', $delta->data['delta']['stop_reason']);
    }

    public function test_stream_raises_api_error_on_response_failed(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'response.failed', 'data' => [
                'type' => 'response.failed',
                'response' => ['error' => ['message' => 'model overloaded', 'type' => 'invalid_request_error']],
            ]],
        ]);

        $this->expectException(\HaoCode\Services\Api\ApiErrorException::class);
        $this->expectExceptionMessage('model overloaded');

        $this->collectEvents($sse);
    }

    /**
     * Build a MockResponse that emits the provided OpenAI SSE envelopes as a
     * single chunk — the provider is responsible for correctly buffering
     * partial lines, which is the same logic already covered by
     * StreamingClientTest for the Anthropic side.
     */
    private function buildSseStream(array $events): MockHttpClient
    {
        $body = '';
        foreach ($events as $entry) {
            $body .= 'event: ' . $entry['event'] . "\n";
            $body .= 'data: ' . json_encode($entry['data'], JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        return new MockHttpClient([
            new MockResponse([$body], ['http_code' => 200]),
        ]);
    }

    private function collectEvents(MockHttpClient $client): array
    {
        $provider = new OpenAiProvider(
            apiKey: 'test',
            model: 'gpt-5',
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
