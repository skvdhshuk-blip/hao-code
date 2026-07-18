<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Support\Runtime\SdkRuntime;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    /** @var \HaoCode\Services\Agent\AgentLoop&\PHPUnit\Framework\MockObject\MockObject */
    private AgentLoop $loop;

    /** @var \HaoCode\Services\Agent\AgentLoopFactory&\PHPUnit\Framework\MockObject\MockObject */
    private AgentLoopFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loop = $this->createMock(AgentLoop::class);
        $this->loop->method('getTotalInputTokens')->willReturn(10);
        $this->loop->method('getTotalOutputTokens')->willReturn(3);
        $this->loop->method('getCacheCreationTokens')->willReturn(0);
        $this->loop->method('getCacheReadTokens')->willReturn(0);
        $this->loop->method('getEstimatedCost')->willReturn(0.00001);
        $this->loop->method('getLastRunTurns')->willReturn(1);

        $this->factory = $this->createMock(AgentLoopFactory::class);
        $this->factory->method('createIsolated')->willReturn($this->loop);

        SdkRuntime::app()->instance(AgentLoopFactory::class, $this->factory);
    }

    public function test_run_returns_query_result_from_agent_loop(): void
    {
        $this->loop->method('run')->willReturn('fake response');

        $agent = $this->makeAgent();
        $result = Runner::run($agent, 'What is the answer?');

        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame('fake response', $result->text);
        $this->assertSame(10, $result->usage['input_tokens']);
        $this->assertSame(3, $result->usage['output_tokens']);
        $this->assertSame(1, $result->turnsUsed);
        $this->assertNull($result->sessionId);
    }

    public function test_run_invokes_text_callback_from_run_options(): void
    {
        $deltas = [];
        $this->loop->method('run')->willReturnCallback(
            static function (string|array $userInput, ?callable $onTextDelta = null) use (&$deltas): string {
                if ($onTextDelta !== null) {
                    $onTextDelta('hello');
                    $onTextDelta(' ');
                    $onTextDelta('world');
                }

                return 'hello world';
            }
        );

        $options = new RunOptions(onText: function (string $delta) use (&$deltas): void {
            $deltas[] = $delta;
        });

        $result = Runner::run($this->makeAgent(), 'Say hello', $options);

        $this->assertSame('hello world', $result->text);
        $this->assertSame(['hello', ' ', 'world'], $deltas);
    }

    public function test_stream_yields_messages_and_result(): void
    {
        $this->loop->method('run')->willReturnCallback(
            static function (string|array $userInput, ?callable $onTextDelta = null) use (&$deltas): string {
                if ($onTextDelta !== null) {
                    $onTextDelta('fake');
                    $onTextDelta(' ');
                    $onTextDelta('response');
                }

                return 'fake response';
            }
        );

        $messages = [];
        foreach (Runner::stream($this->makeAgent(), 'Stream this') as $message) {
            $messages[] = $message;
        }

        $texts = array_values(array_filter(
            array_map(static fn (Message $m): string => $m->text ?? '', $messages),
        ));

        $this->assertContains('fake', $texts);
        $this->assertContains(' ', $texts);
        $this->assertContains('response', $texts);

        $resultMessage = array_values(array_filter(
            $messages,
            static fn (Message $m): bool => $m->type === 'result',
        ))[0] ?? null;

        $this->assertNotNull($resultMessage, 'Stream should end with a result message');
        $this->assertSame('fake response', $resultMessage->text);
    }

    public function test_run_uses_durable_session_when_options_say_so(): void
    {
        $this->loop->method('run')->willReturn('ok');

        $options = RunOptions::make()->withDurableSession();
        $result = Runner::run($this->makeAgent(), 'Remember this', $options);

        // Ephemeral defaults are still true; Runner only changes sessionId when
        // the loop reports a session. Since the loop is a mock, sessionId stays null
        // here. This assertion mainly verifies the option is forwarded without error.
        $this->assertSame('ok', $result->text);
    }

    private function makeAgent(): Agent
    {
        return new Agent(
            name: 'test-runner-agent',
            apiKey: 'test-key',
            allowedTools: [],
        );
    }
}
