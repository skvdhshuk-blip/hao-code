<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Services\Agent\AgentLoopSpec;
use HaoCode\Services\Agent\AgentInvocation;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Agent\CancellationToken;
use HaoCode\Services\Agent\ContextPreset;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class AgentInvocationTest extends TestCase
{
    public function test_root_and_child_calls_share_one_input_and_result_contract(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $session = $this->createMock(\HaoCode\Services\Session\SessionManager::class);
        $session->method('getSessionId')->willReturn('session-1');
        $loop->expects($this->once())->method('run')->with('task')->willReturn('done');
        $loop->method('getSessionManager')->willReturn($session);
        $loop->method('getTotalInputTokens')->willReturn(10);
        $loop->method('getTotalOutputTokens')->willReturn(4);
        $loop->method('getLocalInputTokens')->willReturn(3);
        $loop->method('getLocalOutputTokens')->willReturn(2);
        $loop->method('getEstimatedCost')->willReturn(0.5);
        $loop->method('getLocalEstimatedCost')->willReturn(0.2);
        $loop->method('getLastRunTurns')->willReturn(2);

        $result = (new AgentInvocation('task'))->invoke($loop);

        $this->assertSame('done', $result->text);
        $this->assertSame(10, $result->usage['input_tokens']);
        $this->assertSame(3, $result->localUsage['inputTokens']);
        $this->assertSame(0.2, $result->localCost);
        $this->assertSame('session-1', $result->sessionId);
        $this->assertSame(2, $result->turnsUsed);
    }

    public function test_child_invocation_accepts_a_forked_parent_scope(): void
    {
        $parent = $this->context(permissionMode: 'default');
        $child = $parent->fork(readOnly: true, contextPreset: ContextPreset::GENERIC);

        $invocation = new AgentLoopSpec(
            provider: $this->createMock(LlmProvider::class),
            runContext: $child,
            parentToolRegistry: new ToolRegistry,
            parentRunContext: $parent,
        );

        $this->assertSame($child, $invocation->runContext);
        $this->assertTrue($child->cancellationToken->isDescendantOf($parent->cancellationToken));

        $parent->cancellationToken->cancel();
        $this->assertTrue($child->cancellationToken->isCancelled());
    }

    public function test_child_invocation_rejects_an_unrelated_resource_scope(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('derive cancellation, resources, and policy');

        new AgentLoopSpec(
            provider: $this->createMock(LlmProvider::class),
            runContext: $this->context(),
            parentToolRegistry: new ToolRegistry,
            parentRunContext: $this->context(),
        );
    }

    public function test_child_invocation_requires_the_parent_provider(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('inherit its parent provider');

        new AgentLoopSpec(parentToolRegistry: new ToolRegistry);
    }

    public function test_generic_parent_cannot_be_broadened_to_coding_context(): void
    {
        $parent = $this->context(contextPreset: ContextPreset::GENERIC);
        $child = $parent->fork(contextPreset: ContextPreset::CODING);

        $this->assertFalse($child->isChildOf($parent));
    }

    public function test_agent_as_tool_context_only_applies_narrower_child_settings(): void
    {
        $parent = $this->context(
            permissionMode: 'plan',
            contextPreset: ContextPreset::GENERIC,
            thinkingEnabled: false,
            thinkingBudget: 256,
            maxTokens: 1024,
        );
        $config = new HaoCodeConfig(
            apiKey: 'child-must-not-win',
            baseUrl: 'https://child.invalid',
            providerType: 'openai',
            model: 'claude-child',
            maxTokens: 4096,
            permissionMode: 'bypass_permissions',
            thinkingEnabled: true,
            thinkingBudget: 4096,
            contextPreset: ContextPreset::CODING,
        );

        $child = AgentRunContextFactory::makeChild($config, $parent, '/tmp/child');
        $resolved = $child->settings->resolveProviderConfig();

        $this->assertTrue($child->isChildOf($parent));
        $this->assertSame('anthropic', $resolved->providerType);
        $this->assertSame('parent-key', $resolved->apiKey);
        $this->assertSame('https://parent.test', $resolved->baseUrl);
        $this->assertSame(1024, $resolved->maxTokens);
        $this->assertSame('plan', $child->settings->getPermissionMode()->value);
        $this->assertFalse($child->settings->isThinkingEnabled());
        $this->assertSame(256, $child->settings->getThinkingBudget());
        $this->assertSame(ContextPreset::GENERIC, $child->contextPreset);
    }

    public function test_agent_as_tool_cannot_attach_a_child_credential_pool(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot attach a credential pool');

        AgentRunContextFactory::makeChild(
            new HaoCodeConfig(credentialPool: new CredentialPool),
            $this->context(),
            '/tmp/child',
        );
    }

    private function context(
        string $permissionMode = 'default',
        string $contextPreset = ContextPreset::CODING,
        bool $thinkingEnabled = false,
        int $thinkingBudget = 10000,
        int $maxTokens = 4096,
    ): AgentRunContext {
        $root = sys_get_temp_dir();
        $settings = new SettingsManager($root);
        $settings->set('provider_type', 'anthropic');
        $settings->set('api_key', 'parent-key');
        $settings->set('api_base_url', 'https://parent.test');
        $settings->set('model', 'claude-parent');
        $settings->set('max_tokens', $maxTokens);
        $settings->set('permission_mode', $permissionMode);
        $settings->set('thinking_enabled', $thinkingEnabled);
        $settings->set('thinking_budget', $thinkingBudget);

        return new AgentRunContext(
            workingDirectory: $root,
            projectDirectory: $root,
            settings: $settings,
            skillLoader: new SkillLoader($root),
            cancellationToken: new CancellationToken,
            memoryStore: new JsonMemoryStore($root.'/haocode-agent-invocation-memory.json'),
            contextPreset: $contextPreset,
        );
    }
}
