<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\SdkTool;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function test_construction_uses_safe_defaults(): void
    {
        $agent = new Agent();

        $this->assertSame('default', $agent->name);
        $this->assertNull($agent->model);
        $this->assertNull($agent->apiKey);
        $this->assertSame(50, $agent->maxTurns);
        $this->assertSame('default', $agent->permissionMode);
        $this->assertSame([], $agent->allowedTools);
        $this->assertSame([], $agent->disallowedTools);
        $this->assertSame([], $agent->tools);
        $this->assertSame([], $agent->skills);
        $this->assertFalse($agent->thinkingEnabled);
        $this->assertSame(10000, $agent->thinkingBudget);
        $this->assertSame('l0', $agent->memorySummaryLevel);
        $this->assertTrue($agent->ephemeral);
    }

    public function test_agent_rejects_unknown_permission_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('permissionMode');

        new Agent(permissionMode: 'plan ');
    }

    public function test_config_rejects_unknown_permission_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('permissionMode');

        new HaoCodeConfig(permissionMode: 'plan ');
    }

    public function test_with_methods_are_immutable_and_return_new_instances(): void
    {
        $agent = new Agent();

        $withTools = $agent->withTools([]);
        $this->assertNotSame($agent, $withTools);
        $this->assertSame([], $withTools->tools);

        $withModel = $agent->withModel('claude-sonnet-4');
        $this->assertNotSame($agent, $withModel);
        $this->assertSame('claude-sonnet-4', $withModel->model);
        $this->assertNull($agent->model, 'Original agent is unchanged');

        $withSystemPrompt = $agent->withSystemPrompt('You are a test agent.');
        $this->assertSame('You are a test agent.', $withSystemPrompt->systemPrompt);

        $withMaxTurns = $agent->withMaxTurns(10);
        $this->assertSame(10, $withMaxTurns->maxTurns);

        $withPermissionMode = $agent->withPermissionMode('bypass_permissions');
        $this->assertSame('bypass_permissions', $withPermissionMode->permissionMode);
    }

    public function test_to_config_preserves_all_agent_fields(): void
    {
        $agent = new Agent(
            name: 'test-agent',
            model: 'claude-sonnet-4',
            apiKey: 'secret',
            baseUrl: 'https://example.com',
            providerType: 'anthropic',
            maxTokens: 1024,
            maxTurns: 25,
            systemPrompt: 'System prompt',
            appendSystemPrompt: 'Append prompt',
            thinkingEnabled: true,
            thinkingBudget: 5000,
            permissionMode: 'plan',
            allowedTools: ['Read'],
            disallowedTools: ['Bash'],
            ephemeral: false,
        );

        $config = $agent->toConfig();

        $this->assertSame('test-agent', $config->agentName ?? $agent->name);
        $this->assertSame('claude-sonnet-4', $config->model);
        $this->assertSame('secret', $config->apiKey);
        $this->assertSame('https://example.com', $config->baseUrl);
        $this->assertSame('anthropic', $config->providerType);
        $this->assertSame(1024, $config->maxTokens);
        $this->assertSame(25, $config->maxTurns);
        $this->assertSame('System prompt', $config->systemPrompt);
        $this->assertSame('Append prompt', $config->appendSystemPrompt);
        $this->assertTrue($config->thinkingEnabled);
        $this->assertSame(5000, $config->thinkingBudget);
        $this->assertSame('plan', $config->permissionMode);
        $this->assertSame(['Read'], $config->allowedTools);
        $this->assertSame(['Bash'], $config->disallowedTools);
        $this->assertFalse($config->ephemeral);
    }

    public function test_from_config_round_trips_an_agent(): void
    {
        $original = new Agent(
            name: 'round-trip',
            model: 'claude-sonnet-4',
            maxTurns: 42,
            systemPrompt: 'Round trip',
            allowedTools: ['Read', 'Grep'],
            ephemeral: false,
        );

        $config = $original->toConfig();
        $restored = Agent::fromConfig($config, name: 'round-trip');

        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->model, $restored->model);
        $this->assertSame($original->maxTurns, $restored->maxTurns);
        $this->assertSame($original->systemPrompt, $restored->systemPrompt);
        $this->assertSame($original->allowedTools, $restored->allowedTools);
        $this->assertSame($original->ephemeral, $restored->ephemeral);
    }

    public function test_headers_round_trip_through_config(): void
    {
        $agent = new Agent(name: 'copilot', headers: ['Editor-Version' => 'vscode/1.96.0']);

        $config = $agent->toConfig();
        $this->assertSame(['Editor-Version' => 'vscode/1.96.0'], $config->headers);

        $restored = Agent::fromConfig($config);
        $this->assertSame(['Editor-Version' => 'vscode/1.96.0'], $restored->headers);
    }

    public function test_webfetch_fields_round_trip(): void
    {
        $original = new Agent(
            name: 'webfetch-agent',
            webfetchAllowPrivateNetworks: true,
            webfetchPrivateAllowList: ['192.168.0.0/16', '10.0.0.0/8'],
            webfetchMaxBytes: 1_048_576,
        );

        $config = $original->toConfig();
        $this->assertTrue($config->webfetchAllowPrivateNetworks);
        $this->assertSame(['192.168.0.0/16', '10.0.0.0/8'], $config->webfetchPrivateAllowList);
        $this->assertSame(1_048_576, $config->webfetchMaxBytes);

        $restored = Agent::fromConfig($config, name: 'webfetch-agent');
        $this->assertTrue($restored->webfetchAllowPrivateNetworks);
        $this->assertSame(['192.168.0.0/16', '10.0.0.0/8'], $restored->webfetchPrivateAllowList);
        $this->assertSame(1_048_576, $restored->webfetchMaxBytes);
    }

    public function test_deprecated_facade_fields_round_trip_for_backward_compatibility(): void
    {
        $original = new Agent(
            sessionId: 'sess-abc',
            continueSession: true,
            structuredMaxRetries: 3,
        );

        $config = $original->toConfig();
        $this->assertSame('sess-abc', $config->sessionId);
        $this->assertTrue($config->continueSession);
        $this->assertSame(3, $config->structuredMaxRetries);

        $restored = Agent::fromConfig($config);
        $this->assertSame('sess-abc', $restored->sessionId);
        $this->assertTrue($restored->continueSession);
        $this->assertSame(3, $restored->structuredMaxRetries);
    }

    /**
     * Immutable with*() operations must carry the new passthrough fields
     * forward — otherwise changing the model would silently reset the WebFetch
     * policy.
     */
    public function test_with_model_preserves_webfetch_passthrough(): void
    {
        $agent = new Agent(
            name: 'wf',
            webfetchAllowPrivateNetworks: true,
            webfetchMaxBytes: 2_097_152,
        );

        $rebuilt = $agent->withModel('claude-opus-4');
        $this->assertSame('claude-opus-4', $rebuilt->model);
        $this->assertTrue($rebuilt->webfetchAllowPrivateNetworks);
        $this->assertSame(2_097_152, $rebuilt->webfetchMaxBytes);
    }

    public function test_as_tool_returns_a_tool_with_given_name_and_description(): void
    {
        $agent = new Agent(name: 'specialist');
        $tool = $agent->asTool('specialist', 'Do a specialist task.');

        $this->assertInstanceOf(SdkTool::class, $tool);
        $this->assertSame('specialist', $tool->name());
        $this->assertSame('Do a specialist task.', $tool->description());
        $this->assertArrayHasKey('task', $tool->parameters());
    }
}
