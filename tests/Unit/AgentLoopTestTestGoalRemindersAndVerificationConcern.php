<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Tools\ToolResult;

trait AgentLoopTestTestGoalRemindersAndVerificationConcern
{
    public function test_goal_reminder_is_injected_on_the_configured_interval(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('ok'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);
        $loop->configureGoal(null, ['recapEvery' => 2, 'fullEvery' => 0], 0);

        $queryCount = 0;
        $requests = [];
        $queryEngine->method('query')->willReturnCallback(
            function (...$args) use (&$queryCount, &$requests): StreamProcessor {
                $queryCount++;
                $requests[$queryCount] = $args[1];
                if ($queryCount >= 4) {
                    return $this->makePlainTextProcessor('finished');
                }

                return $this->makeValidToolUseProcessor('noop', "toolu_{$queryCount}", []);
            },
        );

        $this->assertSame('finished', $loop->run('refactor the parser'));

        // Turn 1 completes with no reminder; turn 2 is due, so request 3 carries it.
        $this->assertStringNotContainsString('# Task reminder', $this->lastUserText($requests[2]));
        $this->assertStringContainsString('# Task reminder (after turn 2)', $this->lastUserText($requests[3]));
        $this->assertStringContainsString('refactor the parser', $this->lastUserText($requests[3]));
    }

    public function test_no_reminder_is_injected_by_default(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('ok'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);

        $queryCount = 0;
        $requests = [];
        $queryEngine->method('query')->willReturnCallback(
            function (...$args) use (&$queryCount, &$requests): StreamProcessor {
                $queryCount++;
                $requests[$queryCount] = $args[1];
                if ($queryCount >= 3) {
                    return $this->makePlainTextProcessor('finished');
                }

                return $this->makeValidToolUseProcessor('noop', "toolu_{$queryCount}", []);
            },
        );

        $loop->run('refactor the parser');

        foreach ($requests as $messages) {
            $this->assertStringNotContainsString('# Task reminder', $this->lastUserText($messages));
        }
    }

    public function test_goal_verification_reenters_the_loop_once(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('ok'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);
        $loop->configureGoal('The parser handles nested groups', null, 1);

        $queryCount = 0;
        $secondRequest = [];
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(
                function (...$args) use (&$queryCount, &$secondRequest): StreamProcessor {
                    $queryCount++;
                    if ($queryCount === 1) {
                        return $this->makePlainTextProcessor('I think it is done.');
                    }
                    $secondRequest = $args[1];

                    return $this->makePlainTextProcessor('Verified: nested groups parse.');
                },
            );

        $this->assertSame('Verified: nested groups parse.', $loop->run('fix the parser'));

        $check = $this->lastUserText($secondRequest);
        $this->assertStringContainsString('# Goal check', $check);
        $this->assertStringContainsString('The parser handles nested groups', $check);
        $this->assertStringContainsString('I think it is done.', $check);
    }

    public function test_goal_verification_still_runs_at_the_turn_limit(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('ok'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);
        $loop->setMaxTurns(1);
        $loop->configureGoal('Everything compiles', null, 1);

        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(
                fn (): StreamProcessor => $this->makePlainTextProcessor('done'),
            );

        // The check spends its own budget, not a turn, so a run that is already at
        // the limit is still asked to verify.
        $this->assertSame('done', $loop->run('build it'));
    }

    public function test_without_a_goal_the_run_finishes_in_one_request(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $tool = $this->makeTool('noop', fn (): ToolResult => ToolResult::success('ok'));
        $loop = $this->makeEarlyExecutionLoop($queryEngine, $tool);
        $loop->configureGoal(null, null, 1);

        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturn($this->makePlainTextProcessor('done'));

        $this->assertSame('done', $loop->run('build it'));
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function lastUserText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $messages[$i]['content'];
            if (is_string($content)) {
                return $content;
            }
            $text = '';
            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'text') {
                    $text .= $block['text']."\n";
                }
            }

            return $text;
        }

        return '';
    }
}
