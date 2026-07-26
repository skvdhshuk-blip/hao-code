<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RunOptions;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
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
}
