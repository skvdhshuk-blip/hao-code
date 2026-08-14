<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;

trait StreamProcessorTestTestEventWithNullDataIsIgnoredConcern
{

    public function test_event_with_null_data_is_ignored(): void
    {
        $p = new StreamProcessor;
        $event = new StreamEvent('text_delta', null);
        $p->processEvent($event); // should not throw
        $this->assertSame('', $p->getAccumulatedText());
    }

    public function test_it_treats_detected_tool_use_blocks_as_follow_up_even_without_stop_reason(): void
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'Glob',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"pattern":"**/*.php"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_stop', [
            'index' => 0,
        ]));

        $this->assertNotEmpty($processor->getToolUseBlocks());
        $this->assertTrue($processor->hasToolUse());
    }

    public function test_it_completes_a_trailing_tool_block_when_message_delta_stops_for_tool_use(): void
    {
        $processor = new StreamProcessor;
        $completed = [];

        $processor->setOnToolBlockComplete(function (array $block, int $index) use (&$completed): void {
            $completed[] = [$index, $block];
        });

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 1,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_456',
                'name' => 'Read',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 1,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"file_path":"/tmp/demo.txt"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => [
                'stop_reason' => 'tool_use',
            ],
        ]));

        $this->assertCount(1, $completed);
        $this->assertSame(1, $completed[0][0]);
        $this->assertSame('toolu_456', $completed[0][1]['id']);
        $this->assertSame('Read', $completed[0][1]['name']);
        $this->assertSame('/tmp/demo.txt', $completed[0][1]['input']['file_path'] ?? null);
    }

    public function test_it_does_not_complete_the_same_tool_block_twice(): void
    {
        $processor = new StreamProcessor;
        $completedCount = 0;

        $processor->setOnToolBlockComplete(function () use (&$completedCount): void {
            $completedCount++;
        });

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_789',
                'name' => 'Glob',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"pattern":"**/*.php"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => [
                'stop_reason' => 'tool_use',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_stop', [
            'index' => 0,
        ]));

        $this->assertSame(1, $completedCount);
    }

    public function test_signature_delta_is_captured_for_thinking_block(): void
    {
        // The Anthropic API sends a signature_delta for thinking blocks.
        // It must be stored so that toAssistantMessage() can include it
        // when the block is passed back in subsequent turns.
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'my reasoning'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'signature_delta', 'signature' => 'sig_abc123'],
        ]));

        $msg = $p->toAssistantMessage();
        $block = $msg['content'][0];
        $this->assertSame('thinking', $block['type']);
        $this->assertSame('my reasoning', $block['thinking']);
        $this->assertArrayHasKey('signature', $block, 'signature must be present for multi-turn conversations');
        $this->assertSame('sig_abc123', $block['signature']);
    }

    public function test_thinking_block_without_signature_delta_has_no_signature_key(): void
    {
        // When no signature_delta arrives (e.g., non-thinking blocks or incomplete stream),
        // the thinking block in the assistant message must NOT include a 'signature' key
        // (an explicit null would also be rejected by the API).
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'brief thought'],
        ]));

        $msg = $p->toAssistantMessage();
        $block = $msg['content'][0];
        $this->assertSame('thinking', $block['type']);
        $this->assertArrayNotHasKey('signature', $block, 'signature must not be present when no signature_delta arrived');
    }
}
