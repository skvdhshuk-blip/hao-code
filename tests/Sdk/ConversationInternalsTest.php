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

/**
 * Guards the internal refactor where Conversation organizes itself around an
 * Agent definition + RunOptions instead of holding a raw HaoCodeConfig.
 */
class ConversationInternalsTest extends TestCase
{
    public function test_conversation_derives_agent_and_options_from_config(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);

        $config = new HaoCodeConfig(
            apiKey: 'test-key',
            model: 'claude-sonnet-4',
            allowedTools: [],
            ephemeral: false,
            headers: ['Editor-Version' => 'vscode/1.96.0'],
        );

        $conversation = new Conversation($config, $factory);

        $agent = (new \ReflectionProperty(Conversation::class, 'agent'))->getValue($conversation);
        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame('claude-sonnet-4', $agent->model);
        $this->assertSame('test-key', $agent->apiKey);
        $this->assertSame(['Editor-Version' => 'vscode/1.96.0'], $agent->headers);
        $this->assertFalse($agent->ephemeral);

        $options = (new \ReflectionProperty(Conversation::class, 'options'))->getValue($conversation);
        $this->assertInstanceOf(RunOptions::class, $options);
        $this->assertFalse($options->ephemeral);
    }

    public function test_agent_options_round_trip_reproduces_the_legacy_config(): void
    {
        // The createFromAgent path must rebuild the same config that
        // SdkRunFactory::create() received before the refactor.
        $onText = static function (string $delta): void {};

        $config = new HaoCodeConfig(
            apiKey: 'test-key',
            model: 'claude-sonnet-4',
            baseUrl: 'https://example.com',
            providerType: 'anthropic',
            maxTokens: 1024,
            cwd: '/tmp',
            maxTurns: 25,
            maxBudgetUsd: 1.5,
            permissionMode: 'plan',
            allowedTools: ['Read'],
            disallowedTools: ['Bash'],
            systemPrompt: 'System prompt',
            appendSystemPrompt: 'Append prompt',
            thinkingEnabled: true,
            thinkingBudget: 5000,
            onText: $onText,
            ephemeral: false,
            headers: ['X-Custom' => '1'],
        );

        $roundTripped = RunOptions::fromConfig($config)->toConfig(Agent::fromConfig($config));

        $this->assertEquals($config, $roundTripped);
    }

    public function test_snapshot_resume_rebuilds_the_parent_model_before_restoring_skill_scope(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('getCostTracker')->willReturn(new \HaoCode\Services\Cost\CostTracker);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturnCallback(function (...$arguments) use ($loop): AgentLoop {
                $runContext = $arguments[4] ?? null;
                $model = $arguments[10] ?? null;

                $this->assertInstanceOf(\HaoCode\Services\Agent\AgentRunContext::class, $runContext);
                $this->assertSame('parent-model', $runContext->settings->getModel());
                $this->assertSame('parent-model', $model);

                return $loop;
            });

        $config = new HaoCodeConfig(
            apiKey: 'test-key',
            model: 'parent-model',
            allowedTools: [],
            ephemeral: false,
        );
        SdkRunFactory::stageResumeSnapshot($config, [
            'cwd' => getcwd(),
            'model' => 'skill-model',
            'base_model' => 'parent-model',
            'active_skill_model_override' => 'skill-model',
        ]);

        $conversation = new Conversation($config, $factory);
        $conversation->close();
    }

    public function test_send_turns_used_is_agent_loop_turns_not_conversation_send_count(): void
    {
        $session = $this->createMock(\HaoCode\Services\Session\SessionManager::class);
        $session->method('getSessionId')->willReturn('sess-turns');

        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturn('done');
        // Multi-step agent loop (tool + final answer) should report 2 turns.
        $loop->method('getLastRunTurns')->willReturn(2);
        $loop->method('getTotalInputTokens')->willReturn(10);
        $loop->method('getTotalOutputTokens')->willReturn(5);
        $loop->method('getCacheCreationTokens')->willReturn(0);
        $loop->method('getCacheReadTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.01);
        $loop->method('isCostEstimateAvailable')->willReturn(true);
        $loop->method('getSessionManager')->willReturn($session);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);

        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: [], ephemeral: false),
            $factory,
        );

        $first = $conversation->send('hello');
        $second = $conversation->send('again');

        // Conversation send counter accumulates user messages.
        $this->assertSame(2, $conversation->getTurnCount());
        // QueryResult::turnsUsed is the latest loop's turn count, not the send counter.
        $this->assertSame(2, $first->turnsUsed);
        $this->assertSame(2, $second->turnsUsed);
    }

    public function test_send_interrupt_preserves_sandbox_when_conversation_is_closed(): void
    {
        $runtime = null;
        $interrupt = $this->interrupt('send-interrupt');
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('attachSandboxRuntime')->willReturnCallback(
            static function (?SandboxRuntime $sandbox) use (&$runtime): void {
                $runtime = $sandbox;
            },
        );
        $loop->method('run')->willThrowException(new HumanInterruptException($interrupt));

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);

        $conversation = new Conversation(
            new HaoCodeConfig(
                apiKey: 'k',
                allowedTools: [],
                ephemeral: false,
                sandbox: SandboxConfig::local(cleanup: 'always'),
            ),
            $factory,
        );
        $this->assertInstanceOf(SandboxRuntime::class, $runtime);
        $root = $runtime->exportLease()['root'];

        try {
            $conversation->send('pause');
            $this->fail('Expected a durable human interrupt.');
        } catch (HumanInterruptException $exception) {
            $this->assertSame($interrupt, $exception->interrupt);
        } finally {
            $conversation->close();
        }

        $this->assertDirectoryExists($root);
        $runtime->backend->delete('/');
    }

    public function test_snapshot_resume_interrupt_preserves_sandbox_when_conversation_is_closed(): void
    {
        $runtime = null;
        $interrupt = $this->interrupt('resume-interrupt');
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('attachSandboxRuntime')->willReturnCallback(
            static function (?SandboxRuntime $sandbox) use (&$runtime): void {
                $runtime = $sandbox;
            },
        );
        $loop->method('resumeInterrupt')->willThrowException(new HumanInterruptException($interrupt));

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $config = new HaoCodeConfig(
            apiKey: 'k',
            allowedTools: [],
            ephemeral: false,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        SdkRunFactory::stageResumeSnapshot($config, ['cwd' => getcwd()]);

        $conversation = new Conversation($config, $factory);
        $this->assertInstanceOf(SandboxRuntime::class, $runtime);
        $root = $runtime->exportLease()['root'];

        try {
            $conversation->resumeInterrupt('resume-interrupt', []);
            $this->fail('Expected a second durable human interrupt.');
        } catch (HumanInterruptException $exception) {
            $this->assertSame($interrupt, $exception->interrupt);
        } finally {
            $conversation->close();
        }

        $this->assertDirectoryExists($root);
        $runtime->backend->delete('/');
    }

    public function test_stream_resume_preserves_sandbox_after_interrupt_is_reached(): void
    {
        $runtime = null;
        $interrupt = $this->interrupt('stream-resume-interrupt');
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('attachSandboxRuntime')->willReturnCallback(
            static function (?SandboxRuntime $sandbox) use (&$runtime): void {
                $runtime = $sandbox;
            },
        );
        $loop->method('resumeInterrupt')->willReturnCallback(
            static function (
                string $interruptId,
                array $decisions,
                ?callable $onTextDelta = null,
            ) use ($interrupt): never {
                $onTextDelta?->__invoke('before second interrupt');

                throw new HumanInterruptException($interrupt);
            },
        );

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $config = new HaoCodeConfig(
            apiKey: 'k',
            allowedTools: [],
            ephemeral: false,
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        SdkRunFactory::stageResumeSnapshot($config, ['cwd' => getcwd()]);
        $conversation = new Conversation($config, $factory);
        $messages = $conversation->streamResumeInterrupt('first-interrupt', []);
        $messages->rewind();
        $this->assertInstanceOf(SandboxRuntime::class, $runtime);
        $root = $runtime->exportLease()['root'];
        $messages->next();
        $this->assertSame('interrupt', $messages->current()->type);

        unset($messages);
        gc_collect_cycles();
        $conversation->close();

        $this->assertDirectoryExists($root);
        $runtime->backend->delete('/');
    }

    public function test_stream_rejects_reentrancy_and_cancels_the_fiber_when_abandoned(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('getSessionId')->willReturn('sess-stream');

        $streamCallbackReturned = false;
        $runCount = 0;
        $autoDecisionHandlers = [];
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturnCallback(
            static function (
                string|array $userInput,
                ?callable $onTextDelta = null,
            ) use (&$streamCallbackReturned, &$runCount): string {
                $runCount++;
                if ($runCount === 1) {
                    $onTextDelta?->__invoke('first delta');
                    $streamCallbackReturned = true;

                    return 'stream completed';
                }

                return 'next send completed';
            },
        );
        $loop->expects($this->once())->method('abort');
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

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->method('createIsolated')->willReturn($loop);
        $conversation = new Conversation(
            new HaoCodeConfig(apiKey: 'k', allowedTools: []),
            $factory,
        );

        $messages = $conversation->stream('start streaming');
        $messages->rewind();
        $this->assertSame('text', $messages->current()->type);
        $this->assertFalse($streamCallbackReturned);

        $reentrancyError = null;
        try {
            $conversation->send('must not overlap');
        } catch (\RuntimeException $exception) {
            $reentrancyError = $exception;
        }
        $this->assertInstanceOf(\RuntimeException::class, $reentrancyError);
        $this->assertStringContainsString('already in progress', $reentrancyError->getMessage());

        $loadError = null;
        try {
            $conversation->loadSession('must-not-switch');
        } catch (\RuntimeException $exception) {
            $loadError = $exception;
        }
        $this->assertInstanceOf(\RuntimeException::class, $loadError);
        $this->assertStringContainsString('already in progress', $loadError->getMessage());

        $closeError = null;
        try {
            $conversation->close();
        } catch (\RuntimeException $exception) {
            $closeError = $exception;
        }
        $this->assertInstanceOf(\RuntimeException::class, $closeError);
        $this->assertStringContainsString('operation is already in progress', $closeError->getMessage());

        unset($messages);
        gc_collect_cycles();

        $this->assertFalse($streamCallbackReturned);
        $this->assertCount(2, $autoDecisionHandlers);
        $this->assertIsCallable($autoDecisionHandlers[0]);
        $this->assertNull($autoDecisionHandlers[1]);
        $this->assertSame('next send completed', $conversation->send('safe after cleanup')->text);
        $conversation->close();
    }

    public function test_terminal_stream_cleanup_does_not_clear_a_new_stream_operation(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('getSessionId')->willReturn('sess-terminal-stream');

        $runCount = 0;
        $autoDecisionHandlers = [];
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturnCallback(
            static function (
                string|array $userInput,
                ?callable $onTextDelta = null,
                ?callable $onToolStart = null,
                ?callable $onToolComplete = null,
                ?callable $onTurnStart = null,
                ?callable $onThinkingDelta = null,
            ) use (&$runCount): string {
                $runCount++;

                if ($runCount === 2) {
                    $onTextDelta?->__invoke('immediate follow-up started');

                    return 'immediate follow-up completed';
                }

                return $runCount === 1
                    ? 'stream completed'
                    : 'post-generator follow-up completed';
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
