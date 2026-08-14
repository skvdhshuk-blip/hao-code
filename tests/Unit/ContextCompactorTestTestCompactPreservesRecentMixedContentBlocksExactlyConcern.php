<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

trait ContextCompactorTestTestCompactPreservesRecentMixedContentBlocksExactlyConcern
{

    public function test_compact_preserves_recent_mixed_content_blocks_exactly(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('old user');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'old assistant']);
        $recent = [
            'role' => 'user',
            'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'output', 'is_error' => false],
                ['type' => 'text', 'text' => 'tail', 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'abc']],
                ['type' => 'future_block', 'payload' => ['nested' => true]],
            ],
        ];
        $history->addUserMessage($recent['content']);
        $history->addAssistantMessage([
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'kept assistant']],
        ]);

        $this->makeCompactor($this->makeQueryEngine('summary'))->compact($history, keepLast: 2);

        $messages = $history->getMessages();
        $this->assertSame($recent, $messages[2]);
        $this->assertSame(
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'kept assistant']]],
            $messages[3],
        );
    }

    public function test_compact_preserves_pure_multi_image_message_exactly(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('old user');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'old assistant']);
        $images = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'first']],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'second']],
        ];
        $history->addUserMessage($images);
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'kept']);

        $this->makeCompactor($this->makeQueryEngine('summary'))->compact($history, keepLast: 2);

        $this->assertSame(['role' => 'user', 'content' => $images], $history->getMessages()[2]);
    }

    public function test_micro_compact_changes_only_old_tool_result_content(): void
    {
        $history = new MessageHistory;
        $oldBlock = [
            'type' => 'tool_result',
            'tool_use_id' => 'old',
            'content' => str_repeat('x', 1500),
            'is_error' => true,
            'cache_control' => ['type' => 'ephemeral'],
            'extension' => ['keep' => true],
        ];
        $tailBlocks = [
            ['type' => 'text', 'text' => 'tail'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'abc']],
            ['type' => 'unknown_type', 'data' => 'keep'],
        ];
        $history->addUserMessage(array_merge([$oldBlock], $tailBlocks));
        $history->addUserMessage([[
            'type' => 'tool_result',
            'tool_use_id' => 'new',
            'content' => str_repeat('y', 1500),
            'is_error' => false,
        ]]);

        $this->makeCompactor()->microCompact($history, keepLastToolResults: 1);

        $messages = $history->getMessages();
        $expectedOld = $oldBlock;
        $expectedOld['content'] = '[Old tool result content cleared to save context]';
        $this->assertSame(array_merge([$expectedOld], $tailBlocks), $messages[0]['content']);
        $this->assertSame(str_repeat('y', 1500), $messages[1]['content'][0]['content']);
    }

    public function test_successful_compact_resets_failure_count(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
            ['role' => 'assistant', 'content' => 'd'],
            ['role' => 'user', 'content' => 'e'],
        ]);

        // First compact fails, then succeeds
        $processor1 = $this->createMock(StreamProcessor::class);
        $processor1->method('getAccumulatedText')->willReturn('');
        $processor2 = $this->createMock(StreamProcessor::class);
        $processor2->method('getAccumulatedText')->willReturn('Good summary');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($processor1, $processor2);

        $compactor = new ContextCompactor($qe);

        $h1 = clone $history;
        $compactor->compact($h1, keepLast: 2); // failure

        $h2 = clone $history;
        $compactor->compact($h2, keepLast: 2); // success → resets failures

        // After reset, should auto compact again
        $this->assertTrue($compactor->shouldAutoCompact(200_000));
    }

    public function test_emergency_compact_drops_large_image_payloads_and_preserves_tool_ids(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage([
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_image',
                'name' => 'Read',
                'input' => ['file_path' => '/tmp/image.png'],
            ]],
        ]);
        $history->addToolResultMessage([[
            'tool_use_id' => 'toolu_image',
            'content' => '[Image: image/png] data:image/png;base64,'.str_repeat('A', 50000),
            'is_error' => false,
        ]]);

        $result = $this->makeCompactor()->emergencyCompact($history);
        $messages = $history->getMessagesForApi();
        $toolResult = $messages[1]['content'][0];

        $this->assertStringContainsString('Emergency-compacted 1 tool results', $result);
        $this->assertSame('toolu_image', $toolResult['tool_use_id']);
        $this->assertSame(
            '[Large image tool result omitted during emergency context compaction]',
            $toolResult['content'],
        );
    }

    public function test_emergency_compact_preserves_non_tool_blocks_and_fields(): void
    {
        $history = new MessageHistory;
        $blocks = [
            [
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_large',
                'content' => str_repeat('z', 5000),
                'is_error' => true,
                'cache_control' => ['type' => 'ephemeral'],
            ],
            ['type' => 'text', 'text' => 'tail'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'abc']],
            ['type' => 'future_block', 'payload' => ['keep' => true]],
        ];
        $history->addUserMessage($blocks);

        $this->makeCompactor()->emergencyCompact($history, previewChars: 20);

        $content = $history->getMessages()[0]['content'];
        $this->assertSame('toolu_large', $content[0]['tool_use_id']);
        $this->assertTrue($content[0]['is_error']);
        $this->assertSame(['type' => 'ephemeral'], $content[0]['cache_control']);
        $this->assertSame(array_slice($blocks, 1), array_slice($content, 1));
    }
}
