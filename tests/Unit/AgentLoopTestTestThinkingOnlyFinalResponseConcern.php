<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\QueryEngine;

trait AgentLoopTestTestThinkingOnlyFinalResponseConcern
{
    public function test_thinking_only_final_response_is_retried_without_returning_empty_success(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeThinkingOnlyProcessor('internal analysis'),
                $this->makePlainTextProcessor('可交付答案'),
            );

        $loop = $this->makeLoop($qe);

        $this->assertSame('可交付答案', $loop->run('继续'));
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('without a usable final answer', $messages[1]['content']);
    }

    public function test_repeated_thinking_only_final_response_throws(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeThinkingOnlyProcessor('analysis one'),
                $this->makeThinkingOnlyProcessor('analysis two'),
                $this->makeThinkingOnlyProcessor('analysis three'),
            );

        $loop = $this->makeLoop($qe);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty final response');

        $loop->run('继续');
    }
}
