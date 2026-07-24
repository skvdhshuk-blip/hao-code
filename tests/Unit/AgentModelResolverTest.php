<?php

namespace Tests\Unit;

use HaoCode\Tools\Agent\AgentModelResolver;
use PHPUnit\Framework\TestCase;

class AgentModelResolverTest extends TestCase
{
    public function test_call_model_takes_precedence_over_definition_model(): void
    {
        $this->assertSame(
            'claude-opus-4-8',
            AgentModelResolver::resolve('opus', 'haiku'),
        );
    }

    public function test_inherit_and_null_keep_the_parent_model(): void
    {
        $this->assertNull(AgentModelResolver::resolve(null, 'inherit'));
        $this->assertNull(AgentModelResolver::resolve(null, null));
    }

    public function test_definition_tier_alias_inherits_parent_model_for_non_anthropic_provider(): void
    {
        $this->assertNull(AgentModelResolver::resolve(null, 'haiku', 'openai'));
        $this->assertNull(AgentModelResolver::resolve(null, 'sonnet', 'openai_chat'));
    }

    public function test_explicit_tier_alias_is_rejected_for_non_anthropic_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only supported by the Anthropic provider');

        AgentModelResolver::resolve('haiku', null, 'openai');
    }

    public function test_invalid_definition_model_is_rejected_explicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported agent model');

        AgentModelResolver::resolve(null, 'fastest');
    }
}
