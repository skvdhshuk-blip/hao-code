<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Tools\ToolResult;

trait AgentLoopTestTestTurnInjectionsAreDeliveredAsTrailingTextBlockConcern
{
    public function test_turn_injections_are_delivered_as_a_trailing_text_block(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('tool ran'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);

        $producerCalls = [];
        $loop->turnInjections()->addProducer(
            function (int $turn, string $session) use (&$producerCalls): ?string {
                $producerCalls[] = [$turn, $session];

                return $turn === 1 ? '# Background task updates' : null;
            },
        );

        $queryCount = 0;
        $secondRequestMessages = [];
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(
                function (...$args) use (&$queryCount, &$secondRequestMessages): StreamProcessor {
                    $queryCount++;
                    if ($queryCount === 1) {
                        return $this->makeValidToolUseProcessor('noop', 'toolu_inject', []);
                    }
                    $secondRequestMessages = $args[1];

                    return $this->makePlainTextProcessor('all done');
                },
            );

        $this->assertSame('all done', $loop->run('do the thing'));

        // Consulted at run start (turn 0, so work finished between sends is delivered)
        // and again after each completed tool turn, with that turn's number.
        $this->assertSame([[0, 'test-session'], [1, 'test-session']], $producerCalls);

        $lastMessage = end($secondRequestMessages);
        $this->assertSame('user', $lastMessage['role']);
        $blocks = $lastMessage['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);

        // The injected text is a trailing block, after every tool_result.
        $trailing = end($blocks);
        $this->assertSame('text', $trailing['type']);
        $this->assertSame('# Background task updates', $trailing['text']);
    }

    public function test_turn_injections_queued_before_a_run_ride_on_the_user_message(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('tool ran'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);

        // A background task that finished between two send() calls.
        $loop->turnInjections()->push('# Background task updates');

        $firstRequestMessages = [];
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                function (...$args) use (&$firstRequestMessages): StreamProcessor {
                    $firstRequestMessages = $args[1];

                    return $this->makePlainTextProcessor('acknowledged');
                },
            );

        $this->assertSame('acknowledged', $loop->run('next question'));

        $blocks = $firstRequestMessages[0]['content'];
        $trailing = end($blocks);
        $this->assertSame('text', $trailing['type']);
        $this->assertSame('# Background task updates', $trailing['text']);
    }

    public function test_a_tool_can_terminate_the_run_after_its_batch_is_recorded(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('handoff', function (array $input, $context): ToolResult {
            $context->turnInjections?->requestTermination('plan_ready', 'THE PLAN');

            return ToolResult::success('handed off');
        });
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);

        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturn($this->makeValidToolUseProcessor('handoff', 'toolu_handoff', []));

        $outcome = $loop->runOutcome('design something');

        $this->assertSame('THE PLAN', $outcome->text);
        $this->assertSame(
            \HaoCode\Contracts\RunTerminationReason::PlanReady,
            $outcome->terminationReason,
        );
    }
}
