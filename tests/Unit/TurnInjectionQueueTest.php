<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\TurnInjectionQueue;
use PHPUnit\Framework\TestCase;

class TurnInjectionQueueTest extends TestCase
{
    public function test_drain_returns_null_when_nothing_is_owed(): void
    {
        $this->assertNull((new TurnInjectionQueue)->drain(1, 'session-a'));
    }

    public function test_pushed_text_is_returned_once(): void
    {
        $queue = new TurnInjectionQueue;
        $queue->push('first notice');

        $this->assertTrue($queue->hasPending());
        $this->assertSame('first notice', $queue->drain(1, 'session-a'));
        $this->assertFalse($queue->hasPending());
        $this->assertNull($queue->drain(2, 'session-a'));
    }

    public function test_blank_pushes_are_ignored(): void
    {
        $queue = new TurnInjectionQueue;
        $queue->push('   ');
        $queue->push("\n");

        $this->assertFalse($queue->hasPending());
        $this->assertNull($queue->drain(1, 'session-a'));
    }

    public function test_pushed_text_precedes_producer_output_and_is_blank_separated(): void
    {
        $queue = new TurnInjectionQueue;
        $queue->addProducer(fn (): string => 'from producer');
        $queue->push('from push');

        $this->assertSame("from push\n\nfrom producer", $queue->drain(1, 'session-a'));
    }

    public function test_producers_run_in_registration_order_and_receive_turn_and_session(): void
    {
        $queue = new TurnInjectionQueue;
        $seen = [];
        $queue->addProducer(function (int $turn, string $session) use (&$seen): string {
            $seen[] = "a:{$turn}:{$session}";

            return 'A';
        });
        $queue->addProducer(function (int $turn, string $session) use (&$seen): string {
            $seen[] = "b:{$turn}:{$session}";

            return 'B';
        });

        $this->assertSame("A\n\nB", $queue->drain(7, 'session-x'));
        $this->assertSame(['a:7:session-x', 'b:7:session-x'], $seen);
    }

    public function test_producers_stay_registered_across_drains(): void
    {
        $queue = new TurnInjectionQueue;
        $queue->addProducer(fn (int $turn): ?string => $turn % 2 === 0 ? "even {$turn}" : null);

        $this->assertNull($queue->drain(1, 'session-a'));
        $this->assertSame('even 2', $queue->drain(2, 'session-a'));
        $this->assertNull($queue->drain(3, 'session-a'));
        $this->assertSame('even 4', $queue->drain(4, 'session-a'));
    }

    public function test_producer_returning_blank_contributes_nothing(): void
    {
        $queue = new TurnInjectionQueue;
        $queue->addProducer(fn (): string => '   ');
        $queue->addProducer(fn (): ?string => null);

        $this->assertNull($queue->drain(1, 'session-a'));
    }

    public function test_termination_is_one_shot_and_first_request_wins(): void
    {
        $queue = new TurnInjectionQueue;
        $this->assertNull($queue->takeTermination());

        $queue->requestTermination('plan_ready', 'the plan');
        $queue->requestTermination('turn_limit', 'ignored');

        $this->assertSame(['reason' => 'plan_ready', 'text' => 'the plan'], $queue->takeTermination());
        $this->assertNull($queue->takeTermination());
    }

    public function test_append_text_block_normalizes_a_plain_string(): void
    {
        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'hello'],
                ['type' => 'text', 'text' => 'notice'],
            ],
            TurnInjectionQueue::appendTextBlock('hello', 'notice'),
        );
    }

    public function test_append_text_block_appends_after_existing_blocks(): void
    {
        $blocks = [
            ['type' => 'text', 'text' => 'context'],
            ['type' => 'image', 'source' => ['type' => 'base64']],
        ];

        $result = TurnInjectionQueue::appendTextBlock($blocks, 'notice');

        $this->assertCount(3, $result);
        $this->assertSame(['type' => 'text', 'text' => 'notice'], $result[2]);
        $this->assertSame($blocks[1], $result[1]);
    }
}
