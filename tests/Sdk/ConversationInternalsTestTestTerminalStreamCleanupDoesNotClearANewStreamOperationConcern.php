<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Sdk\SdkRun;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Session\SessionManager;
use Tests\TestCase;

trait ConversationInternalsTestTestTerminalStreamCleanupDoesNotClearANewStreamOperationConcern
{

    public function test_terminal_stream_cleanup_does_not_clear_a_new_stream_operation(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('getSessionId')->willReturn('sess-terminal-stream');

        $runCount = 0;
        $autoDecisionHandlers = [];
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('runOutcome')->willReturnCallback(
            static function (
                string|array $userInput,
                ?callable $onTextDelta = null,
                ?callable $onToolStart = null,
                ?callable $onToolComplete = null,
                ?callable $onTurnStart = null,
                ?callable $onThinkingDelta = null,
            ) use (&$runCount): \HaoCode\Services\Agent\AgentRunOutcome {
                $runCount++;

                if ($runCount === 2) {
                    $onTextDelta?->__invoke('immediate follow-up started');

                    return \HaoCode\Services\Agent\AgentRunOutcome::normal('immediate follow-up completed');
                }

                return \HaoCode\Services\Agent\AgentRunOutcome::normal($runCount === 1
                    ? 'stream completed'
                    : 'post-generator follow-up completed');
            },
        );
        $loop->method('setAutoDecisionHandler')->willReturnCallback(
            static function (?callable $handler) use (&$autoDecisionHandlers): void {
                $autoDecisionHandlers[] = $handler;
            },
        );
        $loop->method('getSessionManager')->willReturn($session);
        $loop->method('getTotalInputTokens')->willReturn(1);
        $loop->method('getTotalOutputTokens')->willReturn(1);
        $loop->method('getCacheCreationTokens')->willReturn(0);
        $loop->method('getCacheReadTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.0);
        $loop->method('getLastRunTurns')->willReturn(1);
        $loop->method('isCostEstimateAvailable')->willReturn(true);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: [], ephemeral: true),
            $factory,
        );

        $messages = $conversation->stream('terminal stream');
        $messages->rewind();
        $this->assertSame('result', $messages->current()->type);

        // Keep the terminal Generator alive and begin another stream. Its old
        // finally block must neither clear the new auto-decision handler nor
        // release the new operation when the caller later advances it.
        $followUp = $conversation->stream('follow up immediately');
        $followUp->rewind();
        $this->assertSame('text', $followUp->current()->type);
        $this->assertSame('immediate follow-up started', $followUp->current()->text);
        $this->assertIsCallable($autoDecisionHandlers[0]);
        $this->assertNull($autoDecisionHandlers[1]);
        $this->assertIsCallable($autoDecisionHandlers[2]);

        $messages->next();
        $this->assertFalse($messages->valid());
        $this->assertCount(3, $autoDecisionHandlers);
        $this->assertIsCallable($autoDecisionHandlers[2]);

        $followUp->next();
        $this->assertSame('result', $followUp->current()->type);
        $this->assertSame('immediate follow-up completed', $followUp->current()->text);
        $followUp->next();
        $this->assertFalse($followUp->valid());
        $this->assertNull($autoDecisionHandlers[3]);

        $this->assertSame(
            'post-generator follow-up completed',
            $conversation->send('follow up after generator cleanup')->text,
        );
        $conversation->close();
    }

    public function test_load_session_atomically_replaces_non_empty_idle_conversation(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('existing conversation message');

        $activeSessionId = 'current-session';
        $session = $this->createMock(SessionManager::class);
        $session->expects($this->once())
            ->method('loadSession')
            ->with('another-session')
            ->willReturn([
                ['type' => 'user_message', 'content' => 'loaded user message'],
                [
                    'type' => 'assistant_turn',
                    'message' => ['role' => 'assistant', 'content' => 'loaded reply'],
                ],
            ]);
        $session->method('getLastResolvedSessionId')->willReturn('canonical-session');
        $session->expects($this->once())
            ->method('switchToSession')
            ->with('canonical-session')
            ->willReturnCallback(
                static function (string $sessionId) use (&$activeSessionId): void {
                    $activeSessionId = $sessionId;
                },
            );
        $session->method('getSessionId')->willReturnCallback(
            static function () use (&$activeSessionId): string {
                return $activeSessionId;
            },
        );
        $this->app->instance(SessionManager::class, $session);

        $loop = $this->createMock(AgentLoop::class);
        $loop->method('getMessageHistory')->willReturn($history);
        $loop->method('getSessionManager')->willReturn($session);
        $loop->expects($this->once())->method('markSessionResumed');

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: []),
            $factory,
        );

        $conversation->loadSession('another-session');

        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'loaded user message'],
                ['role' => 'assistant', 'content' => 'loaded reply'],
            ],
            $history->getMessages(),
        );
        $this->assertSame('canonical-session', $conversation->getSessionId());
        $conversation->close();
    }

    public function test_load_session_failure_preserves_existing_history_and_session(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('existing conversation message');

        $session = $this->createMock(SessionManager::class);
        $session->expects($this->once())
            ->method('loadSession')
            ->with('missing-session')
            ->willReturn([]);
        $session->expects($this->never())->method('switchToSession');
        $session->method('getSessionId')->willReturn('current-session');
        $this->app->instance(SessionManager::class, $session);

        $loop = $this->createMock(AgentLoop::class);
        $loop->method('getMessageHistory')->willReturn($history);
        $loop->method('getSessionManager')->willReturn($session);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: []),
            $factory,
        );

        try {
            $conversation->loadSession('missing-session');
            $this->fail('Expected the missing session load to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Session not found: missing-session', $exception->getMessage());
        }

        $this->assertSame(
            [['role' => 'user', 'content' => 'existing conversation message']],
            $history->getMessages(),
        );
        $this->assertSame('current-session', $conversation->getSessionId());
        $conversation->close();
    }

    public function test_close_marks_conversation_closed_when_run_cleanup_throws(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: []),
            $factory,
        );

        $runProperty = new \ReflectionProperty(Conversation::class, 'run');
        $originalRun = $runProperty->getValue($conversation);
        $originalRun->close();

        $backend = $this->createMock(SandboxBackendInterface::class);
        $backend->method('close')->willThrowException(new \RuntimeException('cleanup failed'));
        $runtime = new SandboxRuntime(SandboxConfig::local(), $backend);
        $runProperty->setValue($conversation, new SdkRun($loop, $runtime));

        try {
            $conversation->close();
            $this->fail('Expected cleanup failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('cleanup failed', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation has been closed');
        $conversation->send('must remain closed');
    }

    private function interrupt(string $id): HumanInterrupt
    {
        return new HumanInterrupt(
            id: $id,
            sessionId: 'session-'.$id,
            actions: [],
            createdAt: '2026-07-29T00:00:00+00:00',
        );
    }
}
