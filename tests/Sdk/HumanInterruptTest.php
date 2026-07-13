<?php

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use PHPUnit\Framework\TestCase;

final class HumanInterruptTest extends TestCase
{
    public function test_interrupt_round_trips_and_stream_message_is_typed(): void
    {
        $interrupt = new HumanInterrupt(
            'int-1',
            'session-1',
            [new HumanActionRequest('call-1', 'Bash', ['command' => 'pwd'], 'Review command')],
            '2026-07-13T10:00:00+08:00',
            'agent-1',
            'team-1',
        );

        $copy = HumanInterrupt::fromArray($interrupt->toArray());
        $this->assertEquals($interrupt, $copy);
        $message = Message::interrupt($copy);
        $this->assertTrue($message->isInterrupt());
        $this->assertFalse($message->isResult());
        $this->assertSame($copy, $message->interrupt);
        $this->assertSame($copy, (new HumanInterruptException($copy))->interrupt);
    }

    public function test_decision_factories_round_trip(): void
    {
        foreach ([
            HumanDecision::approve('a'),
            HumanDecision::edit('b', ['path' => '/tmp/a']),
            HumanDecision::reject('c', 'not now'),
            HumanDecision::respond('d', ['status' => 'answered', 'answers' => ['yes']]),
        ] as $decision) {
            $this->assertEquals($decision, HumanDecision::fromArray($decision->toArray()));
        }
    }

    public function test_hitl_rejects_ephemeral_sessions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HaoCodeConfig(ephemeral: true, interruptOn: ['Bash' => true]);
    }

    public function test_interrupt_configuration_rejects_unknown_decisions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HaoCodeConfig(ephemeral: false, interruptOn: [
            'Write' => ['allowedDecisions' => ['approve', 'replace-tool']],
        ]);
    }

    public function test_resume_interrupt_requires_the_original_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HaoCodeConfig is required to resume an interrupt');

        HaoCode::resumeInterrupt('session-1', 'interrupt-1', []);
    }

    public function test_stream_resume_interrupt_requires_the_original_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HaoCodeConfig is required to resume an interrupt');

        iterator_to_array(HaoCode::streamResumeInterrupt('session-1', 'interrupt-1', []));
    }
}
