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
}
