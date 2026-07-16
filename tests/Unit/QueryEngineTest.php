<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class QueryEngineTest extends TestCase
{
    private function makeClient(array $events): StreamingClient
    {
        $client = $this->createMock(StreamingClient::class);
        $client->method('streamMessages')->willReturnCallback(
            function (...$args) use ($events) {
                yield from $events;
            }
        );
        return $client;
    }

    private function makeRegistry(array $tools = []): ToolRegistry
    {
        $r = $this->createMock(ToolRegistry::class);
        $r->method('toApiTools')->willReturn($tools);
        return $r;
    }

    // ─── query — returns StreamProcessor ──────────────────────────────────

    public function test_query_returns_stream_processor(): void
    {
        $qe = new QueryEngine($this->makeClient([]), $this->makeRegistry());
        $result = $qe->query([], []);
        $this->assertInstanceOf(StreamProcessor::class, $result);
    }

    public function test_query_accumulates_text_from_events(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello ']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'world']]),
            new StreamEvent('content_block_stop', ['index' => 0]),
            new StreamEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]),
        ];

        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $processor = $qe->query([], []);

        $this->assertSame('Hello world', $processor->getAccumulatedText());
    }

    public function test_query_calls_on_text_delta_callback(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'chunk1']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'chunk2']]),
        ];

        $received = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onTextDelta: function (string $text) use (&$received) {
            $received[] = $text;
        });

        $this->assertSame(['chunk1', 'chunk2'], $received);
    }

    public function test_query_does_not_call_text_delta_for_thinking_events(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'thinking...']]),
        ];

        $received = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onTextDelta: function (string $text) use (&$received) {
            $received[] = $text;
        });

        $this->assertEmpty($received);
    }

    public function test_query_calls_on_tool_block_complete(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Bash', 'input' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"ls"}']]),
            new StreamEvent('content_block_stop', ['index' => 0]),
            new StreamEvent('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]]),
        ];

        $completedBlocks = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onToolBlockComplete: function (array $block) use (&$completedBlocks) {
            $completedBlocks[] = $block;
        });

        $this->assertNotEmpty($completedBlocks);
        $this->assertSame('Bash', $completedBlocks[0]['name'] ?? '');
    }

    public function test_query_passes_tools_from_registry(): void
    {
        $toolDef = ['name' => 'Bash', 'description' => '...', 'input_schema' => ['type' => 'object']];
        $registry = $this->makeRegistry([$toolDef]);

        $capturedTools = null;
        $capturedShouldAbort = null;
        $client = $this->createMock(StreamingClient::class);
        $client->method('streamMessages')->willReturnCallback(
            function ($systemPrompt, $messages, $tools, $onRawEvent = null, $shouldAbort = null) use (&$capturedTools, &$capturedShouldAbort) {
                $capturedTools = $tools;
                $capturedShouldAbort = $shouldAbort;
                return (function () { yield from []; })();
            }
        );

        $qe = new QueryEngine($client, $registry);
        $abortChecker = fn(): bool => false;
        $qe->query([], [], shouldAbort: $abortChecker);

        $this->assertSame([$toolDef], $capturedTools);
        $this->assertSame($abortChecker, $capturedShouldAbort);
    }

    public function test_query_with_empty_event_stream(): void
    {
        $qe = new QueryEngine($this->makeClient([]), $this->makeRegistry());
        $processor = $qe->query([], []);
        $this->assertSame('', $processor->getAccumulatedText());
    }

    public function test_query_tracks_usage_tokens(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 100, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]),
            new StreamEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 10]]),
        ];

        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $processor = $qe->query([], []);
        $usage = $processor->getUsage();

        $this->assertSame(100, $usage['input_tokens']);
        $this->assertSame(10, $usage['output_tokens']);
    }

    public function test_trace_uses_full_context_tokens_when_prompt_cache_hits(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_cache',
                'usage' => [
                    'input_tokens' => 100,
                    'context_input_tokens' => 1000,
                    'output_tokens' => 20,
                    'cache_read_input_tokens' => 900,
                ],
            ],
        ]));

        $attributes = [];
        $span = $this->createMock(\OpenTelemetry\API\Trace\SpanInterface::class);
        $span->method('setAttribute')->willReturnCallback(
            function (string $key, mixed $value) use (&$attributes, $span) {
                $attributes[$key] = $value;

                return $span;
            },
        );

        $engine = new QueryEngine($this->makeClient([]), $this->makeRegistry());
        $method = new \ReflectionMethod($engine, 'annotateLlmSpanWithResult');
        $method->invoke($engine, $span, $processor);

        $this->assertSame(1000, $attributes['llm.token_count.prompt']);
        $this->assertSame(1020, $attributes['llm.token_count.total']);
    }

    public function test_query_ignores_events_after_abort(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'first']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'second']]),
        ];

        $yieldedCount = 0;
        $client = $this->createMock(StreamingClient::class);
        $client->method('streamMessages')->willReturnCallback(
            function ($systemPrompt, $messages, $tools, $onRawEvent = null, $shouldAbort = null) use ($events, &$yieldedCount) {
                foreach ($events as $event) {
                    yield $event;
                    $yieldedCount++;
                    if ($shouldAbort && $shouldAbort()) {
                        break;
                    }
                }
            }
        );

        $qe = new QueryEngine($client, $this->makeRegistry());
        $abortAfterFirstDelta = function () use (&$yieldedCount) {
            return $yieldedCount >= 3;
        };
        $processor = $qe->query([], [], shouldAbort: $abortAfterFirstDelta);

        $this->assertSame('first', $processor->getAccumulatedText());
    }

    public function test_query_accumulates_tool_use_input(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Bash', 'input' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '":"ls"}']]),
            new StreamEvent('content_block_stop', ['index' => 0]),
        ];

        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $processor = $qe->query([], []);
        $blocks = $processor->getIndexedToolUseBlocks();

        $this->assertCount(1, $blocks);
        $this->assertSame('Bash', $blocks[0]['name']);
        $this->assertSame(['command' => 'ls'], $blocks[0]['input']);
    }
}
